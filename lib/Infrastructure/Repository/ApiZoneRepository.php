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
use Poweradmin\Application\Service\ZoneSyncService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Database\DbCompat;

class ApiZoneRepository implements ZoneRepositoryInterface
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
            $query .= " WHERE (z.owner = :userId
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :userId_own AND z_own.zone_name IS NULL)
                OR EXISTS (
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
            $stmt->bindValue(':userId_own', $userId, PDO::PARAM_INT);
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
        // Sync local zones table with PowerDNS API before listing so reverse
        // zones are visible on a fresh install without the user having to open
        // the Forward Zones page first. Throttled to once per 5 minutes.
        (new ZoneSyncService($this->db, $this->backendProvider))->syncIfStale();

        // Build base query from local zones table
        if ($countOnly) {
            $query = "SELECT COUNT(*) as count FROM (
                SELECT DISTINCT z.id FROM zones z
                WHERE z.zone_name IS NOT NULL";

            $params = [];
            if ($permType == 'own') {
                $query .= " AND (z.owner = :userId
                    OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :userId_own AND z_own.zone_name IS NULL)
                    OR EXISTS (
                        SELECT 1 FROM zones_groups zg
                        INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                        WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
                    ))";
                $params[':userId'] = $userId;
                $params[':userId_own'] = $userId;
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
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        // Owners are filled in by enrichZonesWithOwnership() so every assigned user
        // appears in the list, not just the primary owner.
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.comment
                  FROM zones z
                  LEFT JOIN users u ON z.owner = u.id
                  WHERE z.zone_name IS NOT NULL";

        $params = [];
        if ($permType == 'own') {
            $query .= " AND (z.owner = :userId
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :userId_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
                ))";
            $params[':userId'] = $userId;
            $params[':userId_own'] = $userId;
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
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $zoneStats = $this->backendProvider->getZoneStats();
        $zones = [];
        foreach ($results as $row) {
            $name = (string)$row['name'];
            if (!isset($zones[$name])) {
                $apiName = $name . '.';
                $stats = $zoneStats[$apiName] ?? [];
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'] ?? 'NATIVE',
                    'count_records' => $this->resolveRecordCount($stats, (int)$row['id']),
                    'comment' => $row['comment'] ?? '',
                    'secured' => $stats['dnssec'] ?? false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }
        }

        $this->enrichZonesWithOwnership($zones);

        return $zones;
    }

    /**
     * Resolve a zone's record count, falling back to a per-zone API call when
     * PowerDNS's /zones summary endpoint omits rrset_count (older versions
     * such as 4.4.x) or returns 0.
     *
     * @param array<string, mixed> $stats Stats row from getZoneStats()
     */
    private function resolveRecordCount(array $stats, int $zoneId): int
    {
        $count = (int)($stats['rrset_count'] ?? 0);
        if ($count > 0 || $zoneId <= 0) {
            return $count;
        }
        return $this->backendProvider->countZoneRecords($zoneId);
    }

    /**
     * Locate the canonical row for the requested zone, even when a different
     * zone happens to share the same identifier value. Returns null when no
     * zone matches.
     */
    private function resolveCanonicalRow(int $zoneId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id
             FROM zones
             WHERE (id = :id OR domain_id = :did) AND zone_name IS NOT NULL
             ORDER BY CASE WHEN id = :pref THEN 0 ELSE 1 END
             LIMIT 1"
        );
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':did', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':pref', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fill in every assigned user as an owner for each zone, including
     * additional users beyond the primary owner. Mutates $zones.
     */
    private function enrichZonesWithOwnership(array &$zones): void
    {
        if (empty($zones)) {
            return;
        }

        $zoneIds = array_values(array_unique(array_map(fn($z) => (int)$z['id'], $zones)));
        $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));

        $stmt = $this->db->prepare(
            "SELECT z.id, z.domain_id, z.zone_name, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id
             WHERE z.id IN ($placeholders) OR z.domain_id IN ($placeholders)"
        );
        $params = array_merge($zoneIds, $zoneIds);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ownership = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['zone_name'] !== null ? (int)$row['id'] : (int)$row['domain_id'];
            if (!isset($ownership[$key])) {
                $ownership[$key] = ['owners' => [], 'full_names' => []];
            }
            if ($row['username'] !== null) {
                $ownership[$key]['owners'][] = $row['username'];
                $ownership[$key]['full_names'][] = $row['fullname'] ?: '';
            }
        }

        foreach ($zones as &$zone) {
            $key = (int)$zone['id'];
            if (isset($ownership[$key])) {
                $zone['owners'] = $ownership[$key]['owners'];
                $zone['full_names'] = $ownership[$key]['full_names'];
                $zone['users'] = $ownership[$key]['owners'];
            }
        }
        unset($zone);
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
            $query .= " AND (z.owner = :user_id
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :user_id_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg2
                    INNER JOIN user_group_members ugm ON zg2.group_id = ugm.group_id
                    WHERE zg2.domain_id = z.id AND ugm.user_id = :user_id_group
                ))";
        }
        $stmt = $this->db->prepare($query);
        if ($permType === 'own') {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id_own', $userId, PDO::PARAM_INT);
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
        $canonical = $this->resolveCanonicalRow($zoneId);
        return $canonical['zone_name'] ?? null;
    }

    public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array
    {
        // Owners are filled in by enrichZonesWithOwnership() so every assigned user
        // appears in the list, not just the primary owner.
        $query = "SELECT z.id, z.zone_name as name, z.zone_type as type, z.comment
                  FROM zones z
                  LEFT JOIN users u ON z.owner = u.id
                  WHERE z.zone_name IS NOT NULL";
        $params = [];
        if ($userId !== null && !$viewOthers) {
            $query .= " AND (z.owner = :userId
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :userId_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = z.id AND ugm.user_id = :userId_group
                ))";
            $params[':userId'] = $userId;
            $params[':userId_own'] = $userId;
            $params[':userId_group'] = $userId;
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
        $zoneStats = $this->backendProvider->getZoneStats();
        $zones = [];
        foreach ($results as $row) {
            $name = (string)$row['name'];
            if (!isset($zones[$name])) {
                $apiName = $name . '.';
                $stats = $zoneStats[$apiName] ?? [];
                $zones[$name] = [
                    'id' => $row['id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'],
                    'count_records' => $this->resolveRecordCount($stats, (int)$row['id']),
                    'comment' => $row['comment'] ?? '',
                    'secured' => $stats['dnssec'] ?? false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }
        }

        $this->enrichZonesWithOwnership($zones);

        return array_values($zones);
    }

    public function zoneExists(int $zoneId, ?int $userId = null): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        if ($userId === null) {
            return true;
        }
        $cid = (int)$canonical['id'];
        $stmt = $this->db->prepare(
            "SELECT 1 FROM zones z
             WHERE z.id = :cid AND (
                 z.owner = :userId
                 OR EXISTS (
                     SELECT 1 FROM zones zo
                     WHERE zo.zone_name IS NULL
                       AND zo.domain_id = :cid_e
                       AND zo.owner = :userId_own
                 )
                 OR EXISTS (
                     SELECT 1 FROM zones_groups zg
                     INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                     WHERE zg.domain_id = :cid_g AND ugm.user_id = :userId_group
                 )
             )"
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_g', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':userId_own', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':userId_group', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function getZone(int $zoneId): ?array
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                    z.comment, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id
             WHERE z.id = :id"
        );
        $stmt->bindValue(':id', (int)$canonical['id'], PDO::PARAM_INT);
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
        return $this->resolveCanonicalRow($zoneId) !== null;
    }

    public function getDomainType(int $zoneId): string
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        return $canonical['zone_type'] ?? '';
    }

    public function getDomainSlaveMaster(int $zoneId): ?string
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        return $canonical['zone_master'] ?? null;
    }

    public function getZoneComment(int $zoneId): ?string
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        return $canonical['comment'] ?? null;
    }

    public function updateZoneComment(int $zoneId, string $comment): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE zones SET comment = :comment WHERE id = :id");
        $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$canonical['id'], PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getZoneOwners(int $zoneId): array
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return [];
        }
        $cid = (int)$canonical['id'];
        $stmt = $this->db->prepare(
            "SELECT DISTINCT u.id, u.username, u.fullname
             FROM zones z
             JOIN users u ON z.owner = u.id
             WHERE z.id = :cid
                OR (z.zone_name IS NULL AND z.domain_id = :cid_e)"
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $cid, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addOwnerToZone(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO zones (domain_id, owner, zone_templ_id)
             VALUES (:domain_id, :owner, :zone_templ_id)"
        );
        $stmt->bindValue(':domain_id', (int)$canonical['id'], PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', (int)($canonical['zone_templ_id'] ?? 0), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function removeOwnerFromZone(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];

        // First try to delete extra ownership rows (zone_name IS NULL) to preserve the canonical row
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE zone_name IS NULL AND domain_id = :cid AND owner = :owner"
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return true;
        }

        // If the user is on the canonical row, clear the owner instead of deleting the row
        $stmt = $this->db->prepare(
            "UPDATE zones SET owner = 0 WHERE id = :id AND owner = :owner AND zone_name IS NOT NULL"
        );
        $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function isUserZoneOwner(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $stmt = $this->db->prepare(
            "SELECT 1 FROM zones z
             WHERE z.owner = :user_id
               AND (z.id = :cid
                    OR (z.zone_name IS NULL AND z.domain_id = :cid_e))
             LIMIT 1"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $cid, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function getZoneIdByName(string $zoneName): ?int
    {
        $query = "SELECT COALESCE(domain_id, id) FROM zones WHERE zone_name = :name";
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
        // createZone already inserts into zones table in API mode; set the owner
        // on the canonical row only.
        $canonical = $this->resolveCanonicalRow($domainId);
        if ($canonical === null) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE zones SET owner = :owner WHERE id = :id");
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$canonical['id'], PDO::PARAM_INT);
        $stmt->execute();
        return true;
    }

    public function deleteZone(int $zoneId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $zoneName = $canonical['zone_name'] ?? null;

        if ($zoneName) {
            $result = $this->backendProvider->deleteZone($zoneId, $zoneName);
            if (!$result) {
                return false;
            }
        }
        // Delete local group ownership for this zone
        $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :domain_id");
        $stmt->bindValue(':domain_id', $cid, PDO::PARAM_INT);
        $stmt->execute();
        // Delete the canonical row plus any extra ownership rows linked to it
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE id = :cid
                OR (zone_name IS NULL AND domain_id = :cid_e)"
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $cid, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateZone(int $zoneId, array $updates): bool
    {
        if (isset($updates['name'])) {
            $currentName = $this->getDomainNameById($zoneId);
            if ($currentName !== null && $updates['name'] !== $currentName) {
                throw new \InvalidArgumentException(
                    'Zone renaming is not supported in API backend mode. PowerDNS API does not support zone rename operations.'
                );
            }
            unset($updates['name']);
        }

        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];

        $success = true;
        if (isset($updates['type'])) {
            $success = $this->backendProvider->updateZoneType($zoneId, $updates['type']);
            $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type WHERE id = :id");
            $stmt->bindValue(':type', $updates['type'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
            $stmt->execute();
        }
        if (isset($updates['master'])) {
            $success = $success && $this->backendProvider->updateZoneMaster($zoneId, $updates['master']);
            $stmt = $this->db->prepare("UPDATE zones SET zone_master = :master WHERE id = :id");
            $stmt->bindValue(':master', $updates['master'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
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
            $query = "SELECT COUNT(*) FROM zones z WHERE z.zone_name IS NOT NULL";
            $params = [];
        } elseif ($zoneIds === null) {
            $query = "SELECT COUNT(DISTINCT z.id) FROM zones z WHERE z.zone_name IS NOT NULL";
            $params = [];
        } else {
            $query = "SELECT COUNT(DISTINCT z.id) FROM zones z WHERE (z.owner = :user_id
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :user_id_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = z.id AND ugm.user_id = :user_id_group
                )) AND z.zone_name IS NOT NULL";
            $params = [':user_id' => $userId, ':user_id_own' => $userId, ':user_id_group' => $userId];
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
                      WHERE (z.owner = :user_id
                          OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :user_id_own AND z_own.zone_name IS NULL)
                          OR EXISTS (
                              SELECT 1 FROM zones_groups zg
                              INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                              WHERE zg.domain_id = z.id AND ugm.user_id = :user_id_group
                          )) AND z.zone_name IS NOT NULL";
            $params = [':user_id' => $userId, ':user_id_own' => $userId, ':user_id_group' => $userId];
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
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT z.id, z.zone_name as name, z.zone_type as type, z.zone_master as master,
                    COALESCE(z.owner, 0) as owner
             FROM zones z
             WHERE z.id = :id"
        );
        $stmt->bindValue(':id', (int)$canonical['id'], PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }
        $result['account'] = '';
        $result['record_count'] = $this->backendProvider->countZoneRecords($zoneId);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getDomainMetadata(int $zoneId): array
    {
        // TODO: Implement via PowerDNS API metadata endpoints
        return [];
    }

    /**
     * @inheritDoc
     */
    public function replaceDomainMetadata(int $zoneId, array $metadata): bool
    {
        // TODO: Implement via PowerDNS API metadata endpoints
        return false;
    }
}
