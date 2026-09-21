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
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\ZoneAccountSyncService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Domain\Enum\ReverseZoneFilter;
use Poweradmin\Domain\Enum\ZoneKind;
use Poweradmin\Domain\Enum\ZoneSoaHealth;

/**
 * API-backend zone repository; reads zone state through PowerDNS and ownership from zones and zones_groups.
 */
readonly class ApiZoneRepository implements ZoneRepositoryInterface
{
    // Failing beats returning an empty set that reads as "this zone has none".

    private TableNameService $tableNameService;

    public function __construct(
        private PDO $db,
        private DnsBackendProviderInterface $backendProvider,
        private string $dbType,
        private ConfigurationInterface $config
    ) {
        $this->tableNameService = new TableNameService($config);
    }

    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array
    {
        if (!$viewOthers) {
            $where = " WHERE (z.owner = :userId
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :userId_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :userId_group
                ))
            AND z.zone_name NOT LIKE '%.in-addr.arpa'
            AND z.zone_name NOT LIKE '%.ip6.arpa'
            AND z.zone_name IS NOT NULL";
        } else {
            $where = " WHERE z.zone_name NOT LIKE '%.in-addr.arpa'
                         AND z.zone_name NOT LIKE '%.ip6.arpa'
                         AND z.zone_name IS NOT NULL";
        }

        $bind = function ($stmt) use ($viewOthers, $userId): void {
            if (!$viewOthers) {
                $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':userId_own', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':userId_group', $userId, PDO::PARAM_INT);
            }
        };

        // IDN zones are excluded here so they do not all register as "x"; they are
        // resolved to their decoded initial below.
        $query = "SELECT DISTINCT LOWER(" . DbCompat::substr($this->dbType) . "(z.zone_name, 1, 1)) AS letter
                  FROM zones z" . $where . " AND z.zone_name NOT LIKE 'xn--%' ORDER BY letter";
        $stmt = $this->db->prepare($query);
        $bind($stmt);
        $stmt->execute();

        $letters = array_filter($stmt->fetchAll(PDO::FETCH_COLUMN, 0), function ($letter) {
            return ctype_alpha($letter) || is_numeric($letter);
        });

        $idnStmt = $this->db->prepare("SELECT DISTINCT z.zone_name FROM zones z" . $where . " AND z.zone_name LIKE 'xn--%'");
        $bind($idnStmt);
        $idnStmt->execute();

        foreach ($idnStmt->fetchAll(PDO::FETCH_COLUMN, 0) as $name) {
            $letters[] = DnsIdnService::getFirstLetter($name);
        }

        $letters = array_values(array_unique(array_filter($letters, fn($letter) => $letter !== '')));
        sort($letters, SORT_STRING);

        return $letters;
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
        bool $showTemplate = false,
        bool $includeHealth = true,
        bool $includeRecordCount = true
    ) {
        $showSignedSerial = $this->config->get('interface', 'display_signed_serial_in_zone_list', false);
        // DNSSEC state costs a per-zone lookup on the PowerDNS side, so only ask
        // for it when a column actually renders it
        $needsDnssec = (bool)$this->config->get('dnssec', 'enabled', false) || $showSignedSerial;

        // Sync local zones table with PowerDNS API before listing so reverse
        // zones are visible on a fresh install without the user having to open
        // the Forward Zones page first. Throttled to once per 5 minutes. Reads
        // the same zone-list variant as the stats call below so both share one
        // response.
        (new ZoneSyncService($this->db, $this->backendProvider))->syncIfStale($needsDnssec);

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
                        WHERE zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :userId_group
                    ))";
                $params[':userId'] = $userId;
                $params[':userId_own'] = $userId;
                $params[':userId_group'] = $userId;
            }

            // Built from the enum so an unknown filter cannot emit an empty AND ()
            $query .= " AND (" . $this->reverseZoneClause($reverseType) . ")) AS distinct_zones";

            $stmt = $this->db->prepare($query);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        // Owners are filled in by enrichZonesWithOwnership() so every assigned user
        // appears in the list, not just the primary owner.
        // Listed under the canonical id, the identifier the rest of the application
        // (links, ownership, zones_groups) uses; the row id only serves the template lookup
        $query = "SELECT z.id, " . CanonicalZoneSql::canonicalIdColumn('z') . " AS canonical_id,
                         z.zone_name as name, z.zone_type as type, z.comment
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
                    WHERE zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :userId_group
                ))";
            $params[':userId'] = $userId;
            $params[':userId_own'] = $userId;
            $params[':userId_group'] = $userId;
        }

        // Built from the enum so an unknown filter cannot emit an empty AND ()
        $query .= " AND (" . $this->reverseZoneClause($reverseType) . ")";

        // Sorting. The Type column is offered as sortable, so it needs its own
        // arm - without one it fell to the default and quietly sorted by name.
        $sortCol = match ($sortBy) {
            'owner' => 'u.username',
            'type' => 'z.zone_type',
            default => "z.zone_name",
        };
        $sortDirection = $this->tableNameService->validateDirection($sortDirection);
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
        $zoneStats = $this->backendProvider->getZoneStats($needsDnssec);
        $zones = [];
        foreach ($results as $row) {
            $name = (string)$row['name'];
            if (!isset($zones[$name])) {
                $apiName = $name . '.';
                $stats = $zoneStats[$apiName] ?? [];
                $kind = $row['type'] ?? 'NATIVE';
                // Both of these read the same zone body, so counting first lets
                // the health check reuse it instead of fetching the zone twice.
                // Bounded by page size, and skipped for callers that render
                // neither (e.g. PTR batch dropdown).
                $countRecords = $includeRecordCount ? $this->backendProvider->countZoneRecords((int)$row['canonical_id']) : 0;
                $soaHealth = $includeHealth
                    ? $this->backendProvider->getZoneSoaHealth($name, $kind)
                    : null;

                $zones[$name] = [
                    'id' => (int)$row['canonical_id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $kind,
                    'count_records' => $countRecords,
                    // A failed lookup is UNKNOWN, not healthy; ?? false used to
                    // render an outage as a green badge.
                    ...ZoneSoaHealth::fromBackend($soaHealth)->toZoneFields(),
                    'comment' => $row['comment'] ?? '',
                    'secured' => $stats['dnssec'] ?? false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
                if ($showSerial) {
                    $serial = (int)($stats['serial'] ?? 0);
                    $zones[$name]['serial'] = $serial > 0 ? (string)$serial : '';
                }
                if ($showSignedSerial) {
                    // Unsigned zones serve the plain serial, so the column stays blank for them
                    $signedSerial = $zones[$name]['secured'] ? ($stats['edited_serial'] ?? null) : null;
                    $zones[$name]['signed_serial'] = $signedSerial > 0 ? (string)$signedSerial : '';
                }
                if ($showTemplate) {
                    $zones[$name]['template'] = $this->resolveTemplateName((int)$row['id']);
                }

                // Pending-NOTIFY state, only meaningful for zones that notify, have
                // a published serial, and run on a server that reports notified_serial
                if (ZoneType::notifies($kind)) {
                    $notifiedSerial = $stats['notified_serial'] ?? null;
                    $currentSerial = (int)($stats['serial'] ?? 0);
                    if ($notifiedSerial !== null && $currentSerial > 0) {
                        $zones[$name]['notified_serial'] = $notifiedSerial;
                        $zones[$name]['notify_pending'] = $currentSerial !== $notifiedSerial;
                    }
                }
            }
        }

        $zones = $this->enrichZonesWithOwnership($zones, true);

        return $zones;
    }

    /**
     * Look up the template name applied to the canonical zones row identified
     * by $zoneId. Returns an empty string when the zone has no template.
     */
    private function resolveTemplateName(int $zoneId): string
    {
        $stmt = $this->db->prepare(
            "SELECT zt.name
             FROM zones z
             JOIN zone_templ zt ON zt.id = z.zone_templ_id
             WHERE z.id = :zone_id AND z.zone_name IS NOT NULL"
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result !== false ? (string)$result : '';
    }

    /**
     * Locate the canonical row for the requested zone, even when a different
     * zone happens to share the same identifier value. Returns null when no
     * zone matches.
     */
    /**
     * The canonical zone id: what API mode hands to callers, and the value extra
     * ownership rows and zones_groups are keyed by. Not the canonical row's own id.
     */
    private static function canonicalIdOf(array $canonical): int
    {
        return (int)($canonical['domain_id'] ?: $canonical['id']);
    }

    private function resolveCanonicalRow(int $zoneId): ?array
    {
        $stmt = $this->db->prepare(CanonicalZoneSql::selectByZoneId(
            'id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id'
        ));
        CanonicalZoneSql::bindZoneId($stmt, $zoneId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * The identifier the rest of the application uses for a resolved row, matching what
     * ApiDnsBackendProvider::getZones() emits. Handing this to the backend provider makes
     * its own resolution land on the row this repository already picked.
     */
    private static function externalZoneId(array $canonical): int
    {
        return (int)($canonical['domain_id'] ?: $canonical['id']);
    }

    /**
     * Fill in every assigned user as an owner for each zone, including
     * additional users beyond the primary owner.
     *
     * @param bool $canonicalIds Whether $zones carry canonical ids rather than zones.id
     */
    private function enrichZonesWithOwnership(array $zones, bool $canonicalIds = false): array
    {
        if (empty($zones)) {
            return $zones;
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
            // Extra ownership rows (no zone_name) always point at the canonical id
            $key = $row['zone_name'] !== null && !$canonicalIds ? (int)$row['id'] : self::canonicalIdOf($row);
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

        return $zones;
    }

    public function countZones(string $permType, ?int $userId, string $letterStart = 'all', string $zoneType = 'forward'): int
    {
        if ($permType !== 'own' && $permType !== 'all') {
            return 0;
        }

        // Only names and ids are counted, so skip the DNSSEC lookup
        $zones = $this->backendProvider->getZones(false);

        if ($permType === 'own') {
            if (!$userId) {
                return 0;
            }
            $ownedIds = $this->ownedCanonicalIds($userId);
            $zones = array_filter($zones, fn($z) => in_array((int)($z['id'] ?? 0), $ownedIds, true));
        }

        if ($letterStart !== 'all') {
            $zones = array_filter($zones, function ($z) use ($letterStart) {
                $name = rtrim($z['name'] ?? '', '.');
                if ($letterStart === '1') {
                    return !empty($name) && is_numeric($name[0]);
                }
                return !empty($name) && strtolower($name[0]) === strtolower($letterStart);
            });
        }

        if ($zoneType === 'forward') {
            $zones = array_filter($zones, fn($z) => !DnsHelper::isReverseZoneName($z['name'] ?? ''));
        } elseif ($zoneType === 'reverse') {
            $zones = array_filter($zones, fn($z) => DnsHelper::isReverseZoneName($z['name'] ?? ''));
        }

        return count($zones);
    }

    /**
     * Canonical ids of the zones a user owns directly or through a group.
     *
     * @return int[]
     */
    private function ownedCanonicalIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT " . CanonicalZoneSql::canonicalIdColumn() . " FROM zones WHERE owner = :uid
             UNION
             SELECT DISTINCT zg.domain_id FROM zones_groups zg
             INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
             WHERE ugm.user_id = :uid2"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getReverseZoneCounts(string $permType, int $userId): array
    {
        $query = "SELECT
                    COUNT(DISTINCT z.id) AS count_all,
                    COUNT(DISTINCT CASE WHEN z.zone_name LIKE '%.in-addr.arpa' THEN z.id END) AS count_ipv4,
                    COUNT(DISTINCT CASE WHEN z.zone_name LIKE '%.ip6.arpa' THEN z.id END) AS count_ipv6
                  FROM zones z";
        if ($permType === 'own') {
            $query .= " LEFT JOIN zones_groups zg ON zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . "";
        }
        $query .= " WHERE z.zone_name IS NOT NULL AND (z.zone_name LIKE '%.in-addr.arpa' OR z.zone_name LIKE '%.ip6.arpa')";
        if ($permType === 'own') {
            $query .= " AND (z.owner = :user_id
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :user_id_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg2
                    INNER JOIN user_group_members ugm ON zg2.group_id = ugm.group_id
                    WHERE zg2.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :user_id_group
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

    public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array
    {
        // Owners are filled in by enrichZonesWithOwnership() so every assigned user
        // appears in the list, not just the primary owner.
        $query = "SELECT z.id, " . CanonicalZoneSql::canonicalIdColumn('z') . " AS canonical_id,
                         z.zone_name as name, z.zone_type as type, z.comment
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
                    WHERE zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :userId_group
                ))";
            $params[':userId'] = $userId;
            $params[':userId_own'] = $userId;
            $params[':userId_group'] = $userId;
        }
        if (isset($filters['type']) && in_array($filters['type'], ZoneKind::basicValues(), true)) {
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
                    'canonical_id' => (int)$row['canonical_id'],
                    'name' => $name,
                    'utf8_name' => DnsIdnService::toUtf8($name),
                    'type' => $row['type'],
                    // One API call per zone - safe because the query above is paged
                    'count_records' => $this->backendProvider->countZoneRecords((int)$row['id']),
                    'comment' => $row['comment'] ?? '',
                    'secured' => $stats['dnssec'] ?? false,
                    'owners' => [],
                    'full_names' => [],
                    'users' => []
                ];
            }
        }

        $zones = $this->enrichZonesWithOwnership($zones);

        return array_values($zones);
    }

    public function getZone(int $zoneId): ?array
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return null;
        }
        $owners = $this->ownersOfCanonical((int)$canonical['id'], self::canonicalIdOf($canonical));
        $usernames = array_column($owners, 'username');
        $zone = $this->coreRow($canonical, $zoneId);
        $zoneInfo = $this->backendProvider->getZoneById($zoneId);

        return $zone + [
            'count_records' => $zone['record_count'],
            'username' => $usernames[0] ?? null,
            'fullname' => $owners[0]['fullname'] ?? null,
            'secured' => $zoneInfo['dnssec'] ?? false,
            'comment' => $canonical['comment'] ?? '',
            'utf8_name' => DnsIdnService::toUtf8($canonical['zone_name']),
            'owners' => $usernames,
            'full_names' => array_map(fn(array $owner) => $owner['fullname'] ?: '', $owners),
            'users' => $usernames,
        ];
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

    public function getOwnerIdsByZoneIds(array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        $canonicalId = CanonicalZoneSql::canonicalIdColumn();
        $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT $canonicalId AS domain_id, owner FROM zones WHERE $canonicalId IN ($placeholders)"
        );
        // The canonical id is an expression with no column affinity, so the ids go in as
        // integers or SQLite compares them as text and matches none
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $stmt->bindValue($i + 1, (int)$zoneId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $owners = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $owners[(int)$row['domain_id']][] = (int)$row['owner'];
        }

        return $owners;
    }

    public function getOwnedZoneIds(int $userId): array
    {
        // API-mode zones with no domain_id are keyed by zones.id, which the canonical
        // expression resolves; zones_groups already stores that same value
        $stmt = $this->db->prepare(
            "SELECT DISTINCT " . CanonicalZoneSql::canonicalIdColumn('z') . " AS zone_id FROM zones z WHERE z.owner = :uid
             UNION
             SELECT DISTINCT zg.domain_id AS zone_id
             FROM zones_groups zg
             INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
             WHERE ugm.user_id = :uid2"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getZoneOwners(int $zoneId): array
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return [];
        }
        return $this->ownersOfCanonical((int)$canonical['id'], self::canonicalIdOf($canonical));
    }

    /**
     * Users owning the canonical row or any extra ownership row keyed by the canonical id.
     *
     * @return array<int, array{id: int|string, username: string, fullname: string|null}>
     */
    private function ownersOfCanonical(int $canonicalRowId, int $canonicalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT u.id, u.username, u.fullname
             FROM zones z
             JOIN users u ON z.owner = u.id
             WHERE z.id = :cid
                OR (z.zone_name IS NULL AND z.domain_id = :cid_e)"
        );
        $stmt->bindValue(':cid', $canonicalRowId, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addOwnerToZone(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $canonicalId = self::canonicalIdOf($canonical);

        $stmt = $this->db->prepare(
            "INSERT INTO zones (domain_id, owner, zone_templ_id)
             VALUES (:domain_id, :owner, :zone_templ_id)"
        );
        $stmt->bindValue(':domain_id', $canonicalId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', (int)($canonical['zone_templ_id'] ?? 0), PDO::PARAM_INT);
        $stmt->execute();

        $added = $stmt->rowCount() > 0;
        if ($added) {
            $this->syncZoneAccount($cid);
        }
        return $added;
    }

    public function removeOwnerFromZone(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $canonicalId = self::canonicalIdOf($canonical);

        // First try to delete extra ownership rows (zone_name IS NULL) to preserve the canonical row
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE zone_name IS NULL AND domain_id = :cid_e AND owner = :owner"
        );
        $stmt->bindValue(':cid_e', $canonicalId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $this->syncZoneAccount($cid);
            return true;
        }

        // If the user is on the canonical row, clear the owner instead of deleting the row
        $stmt = $this->db->prepare(
            "UPDATE zones SET owner = 0 WHERE id = :id AND owner = :owner AND zone_name IS NOT NULL"
        );
        $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $removed = $stmt->rowCount() > 0;
        if ($removed) {
            $this->syncZoneAccount($cid);
        }
        return $removed;
    }

    public function isUserZoneOwner(int $zoneId, int $userId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $canonicalId = self::canonicalIdOf($canonical);
        $stmt = $this->db->prepare(
            "SELECT 1 FROM zones z
             WHERE z.owner = :user_id
               AND (z.id = :cid
                    OR (z.zone_name IS NULL AND z.domain_id = :cid_e))
             LIMIT 1"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function deleteZone(int $zoneId): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        if ($canonical === null) {
            return false;
        }
        $cid = (int)$canonical['id'];
        $canonicalId = self::canonicalIdOf($canonical);
        $zoneName = $canonical['zone_name'] ?? null;

        if ($zoneName) {
            $result = $this->backendProvider->deleteZone($zoneId, $zoneName);
            if (!$result) {
                return false;
            }
        }
        // Group ownership is keyed by the canonical zone id, like the extra ownership
        // rows below - matching on the row's own id leaves the rows behind.
        $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :domain_id");
        $stmt->bindValue(':domain_id', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        // Template mappings key on the canonical zone id too. They cannot carry a foreign
        // key because in API mode that id is not a local domains.id, so delete them here.
        $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :domain_id");
        $stmt->bindValue(':domain_id', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        $stmt = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE domain_id = :domain_id");
        $stmt->bindValue(':domain_id', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        // Delete the canonical row plus any extra ownership rows linked to it
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE id = :cid
                OR (zone_name IS NULL AND domain_id = :cid_e)"
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
        $stmt->bindValue(':cid_e', $canonicalId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateZone(int $zoneId, array $updates): bool
    {
        $canonical = $this->resolveCanonicalRow($zoneId);

        if (isset($updates['name'])) {
            $currentName = $canonical['zone_name'] ?? null;
            if ($currentName !== null && $updates['name'] !== $currentName) {
                throw new \InvalidArgumentException(
                    'Zone renaming is not supported in API backend mode. PowerDNS API does not support zone rename operations.'
                );
            }
            unset($updates['name']);
        }

        if ($canonical === null) {
            return false;
        }

        // Resolve here and pass the resolved identifier down, so the provider's own lookup
        // lands on this row. The provider mirrors the change into the local zones cache
        // itself once PowerDNS accepts it, so this only relays the call and reports failure.
        $externalId = self::externalZoneId($canonical);

        $success = true;
        if (isset($updates['type'])) {
            $success = $this->backendProvider->updateZoneType($externalId, $updates['type']);
        }
        if ($success && isset($updates['master'])) {
            $success = $this->backendProvider->updateZoneMaster($externalId, $updates['master']);
        }
        return $success;
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

        [$conditions, $params] = $this->buildZoneFilterConditions($zoneIds, $userId, $nameFilter);
        $query = "SELECT COUNT(DISTINCT z.id) FROM zones z WHERE z.zone_name IS NOT NULL"
            . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions));

        $stmt = $this->db->prepare($query);
        // Integer binding matters: a text-bound id never equals the COALESCE canonical id.
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Build shared WHERE conditions for the API-backed zone list/count queries.
     * Conditions reference `z` (the Poweradmin-native zones table).
     *
     * @param int[]|null $zoneIds Explicit zone-id allowlist, or null for no id restriction
     * @param int|null $userId Owner to filter by, or null for no ownership restriction
     * @param string|null $nameFilter Optional exact zone-name filter
     * @return array{0: string[], 1: array<string, mixed>} [conditions, bind params]
     */
    private function buildZoneFilterConditions(?array $zoneIds, ?int $userId, ?string $nameFilter): array
    {
        $conditions = [];
        $params = [];

        if ($userId !== null) {
            $conditions[] = "(z.owner = :user_id
                OR EXISTS (SELECT 1 FROM zones z_own WHERE z_own.domain_id IN (z.id, z.domain_id) AND z_own.owner = :user_id_own AND z_own.zone_name IS NULL)
                OR EXISTS (
                    SELECT 1 FROM zones_groups zg
                    INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
                    WHERE zg.domain_id = " . CanonicalZoneSql::canonicalIdColumn('z') . " AND ugm.user_id = :user_id_group
                ))";
            $params[':user_id'] = $userId;
            $params[':user_id_own'] = $userId;
            $params[':user_id_group'] = $userId;
        }

        if ($zoneIds !== null && $zoneIds !== []) {
            // Row ids. API-key scopes are stored as row ids, visible-zone ids are canonical;
            // on a migrated install the two differ. Matching either would widen a scope
            // under an id collision, so this stays narrow until id and canonical_id are one.
            $placeholders = [];
            foreach (array_values($zoneIds) as $i => $zoneId) {
                $placeholders[] = ":zone_id_$i";
                $params[":zone_id_$i"] = (int)$zoneId;
            }
            $conditions[] = "z.id IN (" . implode(', ', $placeholders) . ")";
        }

        if ($nameFilter !== null && $nameFilter !== '') {
            $conditions[] = "z.zone_name = :name_filter";
            $params[':name_filter'] = $nameFilter;
        }

        return [$conditions, $params];
    }

    public function getAllZonesFiltered(?array $zoneIds, ?int $userId = null, ?string $nameFilter = null, ?int $offset = null, ?int $limit = null): array
    {
        if ($zoneIds !== null && empty($zoneIds)) {
            return [];
        }

        [$conditions, $params] = $this->buildZoneFilterConditions($zoneIds, $userId, $nameFilter);
        // canonical_id is what every other endpoint keys on; id stays the row id for
        // one release so API clients can move over before the two are made equal.
        $query = "SELECT z.id, " . CanonicalZoneSql::canonicalIdColumn('z') . " AS canonical_id,
                         z.zone_name as name, z.zone_type as type, z.zone_master as master,
                         COALESCE(z.owner, 0) as owner
                  FROM zones z
                  WHERE z.zone_name IS NOT NULL"
            . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions));
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
            $row['canonical_id'] = (int)$row['canonical_id'];
            $row['record_count'] = 0;
        }
        return $results;
    }

    public function getZoneById(int $zoneId): ?array
    {
        $canonical = $this->resolveCanonicalRow($zoneId);
        return $canonical === null ? null : $this->coreRow($canonical, $zoneId);
    }

    /**
     * The getZoneById() key set, built from a canonical row already in hand.
     */
    private function coreRow(array $canonical, int $zoneId): array
    {
        return [
            'id' => $canonical['id'],
            'name' => $canonical['zone_name'],
            'type' => $canonical['zone_type'],
            'master' => $canonical['zone_master'],
            'owner' => (int)($canonical['owner'] ?? 0),
            'account' => '',
            'record_count' => $this->backendProvider->countZoneRecords($zoneId),
        ];
    }

    private function syncZoneAccount(int $cid): void
    {
        $accountSync = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        if (!$accountSync->isEnabled()) {
            return;
        }
        $canonical = $this->resolveCanonicalRow($cid);
        $accountSync->pushZoneAccount($cid, $canonical === null
            ? null
            : $this->getOldestOwnerUsername((int)$canonical['id'], self::canonicalIdOf($canonical)));
    }

    /**
     * Oldest owner across the canonical zone row and extra ownership rows.
     *
     * Matches the canonical row by primary key: the id spaces overlap (CanonicalZoneSql),
     * so an unrelated native row sharing the canonical id would win the ORDER BY and be
     * pushed as this zone's account. Extra owners stay keyed by canonical id.
     */
    private function getOldestOwnerUsername(int $canonicalRowId, int $canonicalId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT u.username
             FROM zones z
             INNER JOIN users u ON z.owner = u.id
             WHERE z.id = :row_id
                OR (z.zone_name IS NULL AND z.domain_id = :canonical_id)
             ORDER BY z.id
             LIMIT 1"
        );
        $stmt->bindValue(':row_id', $canonicalRowId, PDO::PARAM_INT);
        $stmt->bindValue(':canonical_id', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        $username = $stmt->fetchColumn();
        return $username === false ? null : (string)$username;
    }

    /**
     * OR-joined name predicates for the requested address family. Never empty:
     * an unrecognised filter degrades to ALL rather than producing `AND ()`.
     */
    private function reverseZoneClause(string $reverseType): string
    {
        $filter = ReverseZoneFilter::fromRequest($reverseType);

        $clauses = [];
        if ($filter->includesIpv4()) {
            $clauses[] = "z.zone_name LIKE '%.in-addr.arpa'";
        }
        if ($filter->includesIpv6()) {
            $clauses[] = "z.zone_name LIKE '%.ip6.arpa'";
        }

        return implode(' OR ', $clauses);
    }
}
