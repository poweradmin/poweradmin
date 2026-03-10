<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Database\DbCompat;

class ApiZoneRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly DnsBackendProvider $backendProvider,
        private readonly string $dbType
    ) {
    }

    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array
    {
        $query = "SELECT DISTINCT LOWER(" . DbCompat::substr($this->dbType) . "(z.zone_name, 1, 1)) AS letter
                  FROM zones z";
        if (!$viewOthers) {
            $query .= " WHERE (z.owner = :userId OR EXISTS (
                SELECT 1 FROM zones_groups zg
                INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
            ))
            AND z.zone_name NOT LIKE '%.in-addr.arpa'
            AND z.zone_name NOT LIKE '%.ip6.arpa'
            AND z.zone_name IS NOT NULL";
        } else {
            $query .= " WHERE z.zone_name NOT LIKE '%.in-addr.arpa'
                         AND z.zone_name NOT LIKE '%.ip6.arpa'
                         AND z.zone_name IS NOT NULL";
        }
        $query .= " ORDER BY letter";
        $stmt = $this->db->prepare($query);
        if (!$viewOthers) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':userId_group', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $letters = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_filter($letters, function ($letter) {
            return ctype_alpha($letter) || is_numeric($letter);
        });
    }

    public function getReverseZones(
        string $permType,
        int $userId,
        string $reverseType = 'all',
        int $offset = 0,
        int $limit = 25,
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        bool $countOnly = false,
        bool $showSerial = false,
        bool $showTemplate = false
    ) {
        // Build base query from local zones table
        if ($countOnly) {
            $query = "SELECT COUNT(*) as count FROM (
                SELECT DISTINCT z.id FROM zones z
                WHERE z.zone_name IS NOT NULL";

            $params = [];
            if ($permType == 'own') {
                $query .= " AND (z.owner = :userId OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
                ))";
                $params[':userId'] = $userId;
                $params[':userId_group'] = $userId;
            }

            $query .= " AND (";
            if ($reverseType == 'all' || $reverseType == 'ipv4') {
                $query .= "z.zone_name LIKE '%.in-addr.arpa'";
                if ($reverseType == 'all') {
                    $query .= " OR ";
                }
            }
            if ($reverseType == 'all' || $reverseType == 'ipv6') {
                $query .= "z.zone_name LIKE '%.ip6.arpa'";
            }
            $query .= ")) AS distinct_zones";

            $stmt = $this->db->prepare($query);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        // Full results query
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.comment,
                         u.username, u.fullname
                  FROM zones z
                  LEFT JOIN users u ON z.owner = u.id
                  WHERE z.zone_name IS NOT NULL";

        $params = [];
        if ($permType == 'own') {
            $query .= " AND (z.owner = :userId OR EXISTS (
                SELECT 1 FROM zones_groups zg
                INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
            ))";
            $params[':userId'] = $userId;
            $params[':userId_group'] = $userId;
        }

        $query .= " AND (";
        if ($reverseType == 'all' || $reverseType == 'ipv4') {
            $query .= "z.zone_name LIKE '%.in-addr.arpa'";
            if ($reverseType == 'all') {
                $query .= " OR ";
            }
        }
        if ($reverseType == 'all' || $reverseType == 'ipv6') {
            $query .= "z.zone_name LIKE '%.ip6.arpa'";
        }
        $query .= ")";

        // Sorting
        $sortCol = match ($sortBy) {
            'owner' => 'u.username',
            default => "z.zone_name",
        };
        $query .= " ORDER BY $sortCol $sortDirection";
        $query .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zones = [];
        foreach ($results as $row) {
            $name = $row['name'];
            if (!isset($zones[$name])) {
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'] ?? 'NATIVE',
                    'count_records' => 0,
                    'comment' => $row['comment'] ?? '',
                    'secured' => false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }
            $zones[$name]['owners'][] = $row['username'];
            $zones[$name]['full_names'][] = $row['fullname'] ?: '';
            $zones[$name]['users'][] = $row['username'];
        }

        return $zones;
    }

    public function getReverseZoneCounts(string $permType, int $userId): array
    {
        $query = "SELECT
                    COUNT(DISTINCT z.id) AS count_all,
                    COUNT(DISTINCT CASE WHEN z.zone_name LIKE '%.in-addr.arpa' THEN z.id END) AS count_ipv4,
                    COUNT(DISTINCT CASE WHEN z.zone_name LIKE '%.ip6.arpa' THEN z.id END) AS count_ipv6
                  FROM zones z";
        if ($permType === 'own') {
            $query .= " LEFT JOIN zones_groups zg ON zg.domain_id = z.id";
        }
        $query .= " WHERE z.zone_name IS NOT NULL AND (z.zone_name LIKE '%.in-addr.arpa' OR z.zone_name LIKE '%.ip6.arpa')";
        if ($permType === 'own') {
            $query .= " AND (z.owner = :user_id OR EXISTS (
                SELECT 1 FROM zones_groups zg2
                INNER JOIN user_group_members ugm ON zg2.group_id = ugm.group_id
                WHERE zg2.domain_id = z.id AND ugm.user_id = :user_id_group
            ))";
        }
        $stmt = $this->db->prepare($query);
        if ($permType === 'own') {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id_group', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'count_all' => (int)($result['count_all'] ?? 0),
            'count_ipv4' => (int)($result['count_ipv4'] ?? 0),
            'count_ipv6' => (int)($result['count_ipv6'] ?? 0),
        ];
    }

    public function getDomainNameById(int $zoneId): ?string
    {
        $query = "SELECT zone_name FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result ?: null;
    }

    public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array
    {
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type,
                         z.comment, u.username, u.fullname
                  FROM zones z
                  LEFT JOIN users u ON z.owner = u.id
                  WHERE z.zone_name IS NOT NULL";
        $params = [];
        if ($userId !== null && !$viewOthers) {
            $query .= " AND z.owner = :userId";
            $params[':userId'] = $userId;
        }
        if (isset($filters['type']) && in_array($filters['type'], ['MASTER', 'SLAVE', 'NATIVE'])) {
            $query .= " AND z.zone_type = :type";
            $params[':type'] = $filters['type'];
        }
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query .= " AND z.zone_name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        $query .= " ORDER BY z.zone_name ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zones = [];
        foreach ($results as $row) {
            $name = $row['name'];
            if (!isset($zones[$name])) {
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'],
                    'count_records' => 0,
                    'comment' => $row['comment'] ?? '',
                    'secured' => false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }
            $zones[$name]['owners'][] = $row['username'];
            $zones[$name]['full_names'][] = $row['fullname'] ?: '';
            $zones[$name]['users'][] = $row['username'];
        }
        return array_values($zones);
    }

    public function zoneExists(int $zoneId, ?int $userId = null): bool
    {
        $query = "SELECT 1 FROM zones WHERE (id = :id OR domain_id = :id)";
        if ($userId !== null) {
            $query .= " AND owner = :userId";
        }
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        if ($userId !== null) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function getZone(int $zoneId): ?array
    {
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                         z.comment, u.username, u.fullname
                  FROM zones z
                  LEFT JOIN users u ON z.owner = u.id
                  WHERE z.id = :id OR z.domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zone) {
            return null;
        }
        $countRecords = $this->backendProvider->countZoneRecords($zoneId);
        $zoneInfo = $this->backendProvider->getZoneById($zoneId);
        $zone['count_records'] = $countRecords;
        $zone['secured'] = $zoneInfo['dnssec'] ?? false;
        $zone['utf8_name'] = DnsIdnService::toUtf8($zone['name']);
        $zone['owners'] = [$zone['username']];
        $zone['full_names'] = [$zone['fullname'] ?: ''];
        $zone['users'] = [$zone['username']];
        return $zone;
    }

    public function getZoneByName(string $zoneName): ?array
    {
        $query = "SELECT id FROM zones WHERE zone_name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $zoneName, PDO::PARAM_STR);
        $stmt->execute();
        $zoneId = $stmt->fetchColumn();
        if (!$zoneId) {
            return null;
        }
        return $this->getZone((int)$zoneId);
    }

    public function findForwardZonesByPtrRecords(array $reverseZoneIds): array
    {
        // Fetch PTR records from API for each reverse zone
        $ptrRecords = [];
        foreach ($reverseZoneIds as $zoneId) {
            $records = $this->backendProvider->getRecordsByZoneId($zoneId, 'PTR');
            foreach ($records as $record) {
                $ptrRecords[] = [
                    'domain_id' => $zoneId,
                    'content' => $record['content'] ?? ''
                ];
            }
        }
        if (empty($ptrRecords)) {
            return [];
        }
        // Extract domain suffixes from PTR content
        $domainSuffixes = [];
        foreach ($ptrRecords as $ptr) {
            $content = rtrim($ptr['content'], '.');
            if (empty($content)) {
                continue;
            }
            $parts = explode('.', $content);
            for ($i = 0; $i < count($parts); $i++) {
                $suffix = implode('.', array_slice($parts, $i));
                if (!empty($suffix) && substr_count($suffix, '.') > 0) {
                    $domainSuffixes[$suffix] = true;
                }
            }
        }
        if (empty($domainSuffixes)) {
            return [];
        }
        // Look up forward zones in local zones table
        $suffixList = array_keys($domainSuffixes);
        $placeholders = implode(',', array_fill(0, count($suffixList), '?'));
        $query = "SELECT id, zone_name as name FROM zones WHERE zone_name IN ($placeholders) AND zone_name NOT LIKE '%.arpa'";
        $stmt = $this->db->prepare($query);
        $paramIndex = 1;
        foreach ($suffixList as $suffix) {
            $stmt->bindValue($paramIndex, $suffix, PDO::PARAM_STR);
            $paramIndex++;
        }
        $stmt->execute();
        $forwardZones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $forwardZones[$row['name']] = $row;
        }
        if (empty($forwardZones)) {
            return [];
        }
        // Match PTR content to forward zones
        $results = [];
        foreach ($ptrRecords as $ptr) {
            $content = rtrim($ptr['content'], '.');
            if (empty($content)) {
                continue;
            }
            $parts = explode('.', $content);
            for ($i = 0; $i < count($parts); $i++) {
                $suffix = implode('.', array_slice($parts, $i));
                if (isset($forwardZones[$suffix])) {
                    $results[] = [
                        'reverse_domain_id' => $ptr['domain_id'],
                        'forward_domain_id' => $forwardZones[$suffix]['id'],
                        'forward_domain_name' => $forwardZones[$suffix]['name'],
                        'ptr_content' => $ptr['content']
                    ];
                    break;
                }
            }
        }
        return $results;
    }

    public function zoneIdExists(int $zoneId): bool
    {
        $query = "SELECT 1 FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function getDomainType(int $zoneId): string
    {
        $query = "SELECT zone_type FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ?: '';
    }

    public function getDomainSlaveMaster(int $zoneId): ?string
    {
        $query = "SELECT zone_master FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    public function getZoneComment(int $zoneId): ?string
    {
        $query = "SELECT comment FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    public function updateZoneComment(int $zoneId, string $comment): bool
    {
        $query = "UPDATE zones SET comment = :comment WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getZoneOwners(int $zoneId): array
    {
        $query = "SELECT u.id, u.username, u.fullname
                  FROM zones z
                  JOIN users u ON z.owner = u.id
                  WHERE z.id = :id OR z.domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addOwnerToZone(int $zoneId, int $userId): bool
    {
        // Get the zone_templ_id from an existing zone record for this domain
        $getTemplateQuery = "SELECT zone_templ_id FROM zones WHERE id = :id OR domain_id = :id LIMIT 1";
        $getStmt = $this->db->prepare($getTemplateQuery);
        $getStmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $getStmt->execute();
        $templateResult = $getStmt->fetch(PDO::FETCH_ASSOC);
        $zoneTemplId = $templateResult ? $templateResult['zone_templ_id'] : 0;

        // Get the zone_name from an existing record
        $getNameQuery = "SELECT zone_name FROM zones WHERE id = :id OR domain_id = :id LIMIT 1";
        $getNameStmt = $this->db->prepare($getNameQuery);
        $getNameStmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $getNameStmt->execute();
        $nameResult = $getNameStmt->fetch(PDO::FETCH_ASSOC);

        $query = "INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name) VALUES (:domain_id, :owner, :zone_templ_id, :zone_name)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', $zoneTemplId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_name', $nameResult ? $nameResult['zone_name'] : null, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function removeOwnerFromZone(int $zoneId, int $userId): bool
    {
        $query = "DELETE FROM zones WHERE (id = :id OR domain_id = :id) AND owner = :owner";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function isUserZoneOwner(int $zoneId, int $userId): bool
    {
        $query = "SELECT COUNT(id) FROM zones WHERE owner = :user_id AND (id = :id OR domain_id = :id)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function getZoneIdByName(string $zoneName): ?int
    {
        $query = "SELECT id FROM zones WHERE zone_name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $zoneName, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    public function createDomain(string $domain, int $owner, string $type, string $slaveMaster = '', string $zoneTemplate = 'none'): bool
    {
        $domainId = $this->backendProvider->createZone($domain, $type, $slaveMaster);
        if ($domainId === false) {
            return false;
        }
        // createZone already inserts into zones table in API mode
        // But we need to set the owner
        $query = "UPDATE zones SET owner = :owner WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        return true;
    }

    public function deleteZone(int $zoneId): bool
    {
        // Get zone name for API call
        $zoneName = $this->getDomainNameById($zoneId);
        if ($zoneName) {
            $result = $this->backendProvider->deleteZone($zoneId, $zoneName);
            if (!$result) {
                return false;
            }
        }
        // Delete local ownership data
        $query = "DELETE FROM zones_groups WHERE domain_id = :domain_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $query = "DELETE FROM zones WHERE id = :id OR domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateZone(int $zoneId, array $updates): bool
    {
        $success = true;
        if (isset($updates['type'])) {
            $success = $success && $this->backendProvider->updateZoneType($zoneId, $updates['type']);
            // Update local cache
            $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type WHERE id = :id OR domain_id = :id");
            $stmt->bindValue(':type', $updates['type'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
        }
        if (isset($updates['master'])) {
            $success = $success && $this->backendProvider->updateZoneMaster($zoneId, $updates['master']);
            $stmt = $this->db->prepare("UPDATE zones SET zone_master = :master WHERE id = :id OR domain_id = :id");
            $stmt->bindValue(':master', $updates['master'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
        }
        if (isset($updates['name'])) {
            // Zone rename - update local zone_name
            $stmt = $this->db->prepare("UPDATE zones SET zone_name = :name WHERE id = :id OR domain_id = :id");
            $stmt->bindValue(':name', $updates['name'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $success;
    }

    public function getAllZones(?int $offset = null, ?int $limit = null): array
    {
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                         COALESCE(z.owner, 0) as owner
                  FROM zones z
                  WHERE z.zone_name IS NOT NULL
                  ORDER BY z.zone_name";
        if ($limit !== null && $limit > 0) {
            $query .= " LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare($query);
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as &$row) {
            $row['record_count'] = 0; // Skip per-zone API calls for list views
        }
        return $results;
    }

    public function getZoneCount(): int
    {
        $query = "SELECT COUNT(*) FROM zones WHERE zone_name IS NOT NULL";
        $stmt = $this->db->query($query);
        return (int)$stmt->fetchColumn();
    }

    public function getZoneCountFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null): int
    {
        if ($zoneIds !== null && empty($zoneIds)) {
            return 0;
        }
        if ($zoneIds === null && $userId === null) {
            $query = "SELECT COUNT(*) FROM zones WHERE zone_name IS NOT NULL";
            $params = [];
        } elseif ($zoneIds === null && $userId !== null) {
            $query = "SELECT COUNT(DISTINCT z.id) FROM zones z WHERE z.zone_name IS NOT NULL";
            $params = [];
        } else {
            $query = "SELECT COUNT(DISTINCT z.id) FROM zones z WHERE z.owner = :user_id AND z.zone_name IS NOT NULL";
            $params = [':user_id' => $userId];
        }
        if ($nameFilter !== null && $nameFilter !== '') {
            $query .= " AND z.zone_name = :name_filter";
            $params[':name_filter'] = $nameFilter;
        }
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getAllZonesFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null, ?int $offset = null, ?int $limit = null): array
    {
        if ($zoneIds !== null && empty($zoneIds)) {
            return [];
        }
        if ($zoneIds === null && $userId === null) {
            $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                             COALESCE(z.owner, 0) as owner
                      FROM zones z
                      WHERE z.zone_name IS NOT NULL";
            $params = [];
        } else {
            $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                             COALESCE(z.owner, 0) as owner
                      FROM zones z
                      WHERE z.owner = :user_id AND z.zone_name IS NOT NULL";
            $params = [':user_id' => $userId];
        }
        if ($nameFilter !== null && $nameFilter !== '') {
            $query .= " AND z.zone_name = :name_filter";
            $params[':name_filter'] = $nameFilter;
        }
        $query .= " ORDER BY z.zone_name";
        if ($limit !== null && $limit > 0) {
            $query .= " LIMIT :limit OFFSET :offset";
        }
        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if ($limit !== null && $limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as &$row) {
            $row['record_count'] = 0;
        }
        return $results;
    }

    public function getZoneById(int $zoneId): ?array
    {
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                         COALESCE(z.owner, 0) as owner
                  FROM zones z
                  WHERE z.id = :id OR z.domain_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }
        $result['account'] = '';
        $result['record_count'] = $this->backendProvider->countZoneRecords($zoneId);
        return $result;
    }
}
