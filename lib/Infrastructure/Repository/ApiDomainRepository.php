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
use Poweradmin\Infrastructure\Utility\ResultPaginator;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\ZoneReadBackendInterface;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\CanonicalZoneSql;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Enum\ZoneSoaHealth;

/**
 * Domain lookups for the API backend mode, read through PowerDNS.
 */
final class ApiDomainRepository implements DomainRepositoryInterface
{
    private PDO $db;
    private ConfigurationInterface $config;
    private HostnameValidator $hostnameValidator;
    private ZoneReadBackendInterface&BackendCapabilitiesInterface $backendProvider;

    public function __construct(PDO $db, ConfigurationInterface $config, ZoneReadBackendInterface&BackendCapabilitiesInterface $backendProvider)
    {
        $this->db = $db;
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($config));
        $this->backendProvider = $backendProvider;
    }

    public function zoneIdExists(int $zid): bool
    {
        // Answered from the local zones table only; no round trip to PowerDNS.
        return $this->backendProvider->getZoneNameById($zid) !== null;
    }

    public function getDomainNameById(int $id): ?string
    {
        return $this->backendProvider->getZoneNameById($id);
    }

    public function getDomainIdByName(string $name): ?int
    {
        if (empty($name)) {
            return null;
        }
        return $this->backendProvider->getZoneIdByName($name);
    }

    public function getDomainType(int $id): string
    {
        return $this->backendProvider->getZoneTypeById($id);
    }

    public function getDomainMaster(int $id): ?string
    {
        return $this->backendProvider->getZoneMasterById($id);
    }

    public function domainExists(string $domain): bool
    {
        if (!$this->hostnameValidator->isValid($domain)) {
            return false;
        }
        return $this->backendProvider->zoneExists($domain);
    }

    public function getZones(
        string $perm,
        int $userid = 0,
        string $letterstart = 'all',
        int $rowstart = 0,
        int $rowamount = Constants::DEFAULT_MAX_ROWS,
        string $sortby = 'name',
        string $sortDirection = 'ASC',
        bool $excludeReverse = false,
        ?bool $showSerial = null,
        ?bool $showTemplate = null,
        bool $includeHealth = true,
        bool $includeRecordCount = true
    ): array {
        // Record counts are resolved per page, so sorting on them would only
        // order the rows already on screen. Fall back rather than reject: a
        // session carried over from SQL mode can still ask for this column.
        if ($sortby === 'count_records') {
            $sortby = 'name';
        }

        $allowedSortColumns = ['name', 'type', 'owner'];
        $tableNameService = new TableNameService($this->config);
        $sortby = $tableNameService->validateOrderBy($sortby, $allowedSortColumns);
        $sortDirection = $tableNameService->validateDirection($sortDirection);

        if ($perm !== 'own' && $perm !== 'all') {
            return [];
        }

        $iface_zonelist_serial = $showSerial ?? $this->config->get('interface', 'display_serial_in_zone_list');
        $iface_zonelist_signed_serial = $this->config->get('interface', 'display_signed_serial_in_zone_list', false);
        $iface_zonelist_template = $showTemplate ?? $this->config->get('interface', 'display_template_in_zone_list');

        // DNSSEC state costs a per-zone lookup on the PowerDNS side, so only ask
        // for it when a column actually renders it. The zone list feeds the
        // DNSSEC column; the stats call additionally feeds the signed serial.
        $needsDnssec = (bool)$this->config->get('dnssec', 'enabled', false) || $iface_zonelist_signed_serial;
        $needsEditedSerial = (bool)$iface_zonelist_signed_serial;

        // Sync local zones table with PowerDNS API before listing
        $syncService = new ZoneSyncService($this->db, $this->backendProvider);
        $syncService->syncIfStale($needsDnssec);

        $allZones = $this->backendProvider->getZones($needsDnssec);

        // Filter reverse zones if requested
        if ($excludeReverse) {
            $allZones = array_values(array_filter($allZones, function ($zone) {
                return !DnsHelper::isReverseZoneName($zone['name'] ?? '');
            }));
        }

        // Enrich with ownership from local tables
        $allZones = $this->enrichZonesWithOwnership($allZones);

        // Filter by ownership
        if ($perm === 'own') {
            $ownedDomainIds = $this->getOwnedDomainIds($userid);
            $allZones = array_values(array_filter($allZones, function ($zone) use ($ownedDomainIds) {
                return in_array($zone['id'] ?? 0, $ownedDomainIds, true);
            }));
        }

        // Apply letter filter
        if ($letterstart !== 'all') {
            $allZones = ResultPaginator::filterByLetter($allZones, $letterstart, 'name');
        }

        // Map sortBy for API data keys
        $apiSortBy = $sortby;
        if ($sortby === 'owner') {
            $apiSortBy = 'owner_username';
        }

        // Sort
        $allZones = ResultPaginator::sort($allZones, $apiSortBy, $sortDirection);

        // Paginate
        if ($rowamount < Constants::DEFAULT_MAX_ROWS) {
            $allZones = ResultPaginator::paginate($allZones, $rowstart, $rowamount);
        }

        $zoneStats = ($iface_zonelist_serial || $iface_zonelist_signed_serial)
            ? $this->backendProvider->getZoneStats($needsDnssec)
            : [];
        $templateMap = $iface_zonelist_template ? $this->fetchTemplateNames($allZones) : [];

        // Convert to expected output shape (keyed by domain name)
        $result = [];
        foreach ($allZones as $zone) {
            $name = $zone['name'];
            $utf8Name = DnsIdnService::toUtf8($name);
            $zoneId = (int)($zone['id'] ?? 0);

            // Both of these read the same zone body, so counting first lets the
            // health check reuse it instead of fetching the zone twice. Bounded
            // by page size, and skipped for callers that render neither
            // (DeleteUser, log iteration, etc).
            $countRecords = $includeRecordCount ? $this->backendProvider->countZoneRecords($zoneId) : 0;
            $soaHealth = $includeHealth
                ? $this->backendProvider->getZoneSoaHealth($name, $zone['type'] ?? 'NATIVE')
                : null;

            $result[$name] = [
                'id' => $zoneId,
                'name' => $name,
                'utf8_name' => $utf8Name,
                'type' => $zone['type'] ?? 'NATIVE',
                'count_records' => $countRecords,
                // A failed lookup is UNKNOWN, not healthy; ?? false used to
                // render an outage as a green badge.
                ...ZoneSoaHealth::fromBackend($soaHealth)->toZoneFields(),
                'comment' => $zone['comment'] ?? '',
                'secured' => $zone['dnssec'] ?? $zone['secured'] ?? false,
                'owners' => $zone['owners'] ?? [],
                'full_names' => $zone['full_names'] ?? [],
                'users' => $zone['owners'] ?? [],
            ];

            if ($iface_zonelist_serial) {
                $serial = $zoneStats[$name . '.']['serial'] ?? 0;
                $result[$name]['serial'] = $serial > 0 ? (string)$serial : '';
            }

            if ($iface_zonelist_signed_serial) {
                // Unsigned zones serve the plain serial, so the column stays blank for them
                $signedSerial = $result[$name]['secured'] ? ($zoneStats[$name . '.']['edited_serial'] ?? null) : null;
                $result[$name]['signed_serial'] = $signedSerial > 0 ? (string)$signedSerial : '';
            }

            if ($iface_zonelist_template) {
                $result[$name]['template'] = $templateMap[$zoneId] ?? '';
            }

            // Pending-NOTIFY state, only meaningful for zones that notify, have
            // a published serial, and run on a server that reports notified_serial
            if (ZoneType::notifies($result[$name]['type'])) {
                $notifiedSerial = $zoneStats[$name . '.']['notified_serial'] ?? null;
                $currentSerial = (int)($zoneStats[$name . '.']['serial'] ?? 0);
                if ($notifiedSerial !== null && $currentSerial > 0) {
                    $result[$name]['notified_serial'] = $notifiedSerial;
                    $result[$name]['notify_pending'] = $currentSerial !== $notifiedSerial;
                }
            }
        }

        return array_map(ZoneSummary::fromRow(...), $result);
    }

    /**
     * Batch-fetch template names for all zones in a single query.
     * Matches on either z.id or z.domain_id because API-mode zone rows
     * can have domain_id = NULL while SQL-mode zones use domain_id.
     *
     * @param array<int, array{id?: int}> $zones
     * @return array<int, string> Map of zone id → template name
     */
    private function fetchTemplateNames(array $zones): array
    {
        if (empty($zones)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn($z) => (int)($z['id'] ?? 0),
            $zones
        ))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT z.id, z.domain_id, zt.name
             FROM zones z
             JOIN zone_templ zt ON zt.id = z.zone_templ_id
             WHERE z.id IN ($placeholders) OR z.domain_id IN ($placeholders)"
        );
        $params = array_merge($ids, $ids);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['domain_id'] ?: $row['id']);
            $map[$id] = (string)$row['name'];
        }
        return $map;
    }

    public function getZoneInfoFromId(int $zid): array
    {
        $zone = $this->backendProvider->getZoneById($zid);
        if ($zone === null) {
            return [];
        }
        return [
            'id' => $zid,
            'name' => $zone['name'],
            'type' => $zone['type'],
            'master_ip' => $zone['master'],
            'record_count' => $this->backendProvider->countZoneRecords($zid),
        ];
    }

    public function getZoneInfoFromIds(array $zones): array
    {
        if (empty($zones)) {
            return [];
        }

        // One bulk zone-list fetch for name/type/master instead of a per-zone
        // zone-body fetch. Record counts have no bulk equivalent in the API.
        $byId = [];
        foreach ($this->backendProvider->getZones() as $zone) {
            $byId[(int)($zone['id'] ?? 0)] = $zone;
        }

        $zone_infos = [];
        foreach ($zones as $zid) {
            $zid = (int)$zid;
            $zone = $byId[$zid] ?? null;
            if ($zone === null) {
                continue;
            }
            $zone_infos[] = [
                'id' => $zid,
                'name' => $zone['name'],
                'type' => $zone['type'],
                'master_ip' => $zone['master'],
                'record_count' => $this->backendProvider->countZoneRecords($zid),
            ];
        }
        return $zone_infos;
    }

    public function listZoneNames(): array
    {
        // Read the live list rather than the local zones table, which lags until sync runs
        $zones = [];
        foreach ($this->backendProvider->getZones() as $zone) {
            $id = (int)($zone['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $zones[] = [
                'id' => $id,
                'name' => rtrim((string)($zone['name'] ?? ''), '.'),
                'type' => (string)($zone['type'] ?? $zone['kind'] ?? ''),
            ];
        }
        usort($zones, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $zones;
    }

    public function getBestMatchingZoneIdFromName(string $domain): int
    {
        return $this->backendProvider->getBestMatchingReverseZoneId($domain);
    }

    private function enrichZonesWithOwnership(array $zones): array
    {
        if (empty($zones)) {
            return $zones;
        }

        // The map is keyed by canonical id because that is how the zone list is keyed;
        // reading the raw column collapsed every unresolved row onto key 0.
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('z', $this->backendProvider->allocatesZoneIdsLocally());
        $stmt = $this->db->query(
            "SELECT $canonicalId AS canonical_id, z.owner, z.comment, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id"
        );

        $ownershipMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domainId = (int)$row['canonical_id'];
            if (!isset($ownershipMap[$domainId])) {
                $ownershipMap[$domainId] = [
                    'owners' => [],
                    'full_names' => [],
                    'comment' => $row['comment'] ?? '',
                ];
            }
            if ($row['username'] !== null) {
                $ownershipMap[$domainId]['owners'][] = $row['username'];
                $ownershipMap[$domainId]['full_names'][] = $row['fullname'] ?: '';
            }
        }

        foreach ($zones as &$zone) {
            $id = $zone['id'] ?? 0;
            if (isset($ownershipMap[$id])) {
                $zone['owners'] = $ownershipMap[$id]['owners'];
                $zone['full_names'] = $ownershipMap[$id]['full_names'];
                $zone['comment'] = $ownershipMap[$id]['comment'];
                $zone['owner_username'] = $ownershipMap[$id]['owners'][0] ?? '';
            } else {
                $zone['owners'] = [];
                $zone['full_names'] = [];
                $zone['comment'] = '';
                $zone['owner_username'] = '';
            }
        }
        unset($zone);

        return $zones;
    }

    /**
     * @return int[]
     */
    private function getOwnedDomainIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT " . CanonicalZoneSql::canonicalIdColumn('', $this->backendProvider->allocatesZoneIdsLocally()) . " FROM zones WHERE owner = :uid
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

    /**
     * Answered from zones.zone_name, which Poweradmin writes synchronously on create;
     * zones created out-of-band in PowerDNS are absent, and those have no owner to compare.
     */
    public function findZoneIdsByNames(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $idCol = CanonicalZoneSql::canonicalIdColumn('', $this->backendProvider->allocatesZoneIdsLocally());
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        // LOWER(zone_name) so the match is case-insensitive on every backend; the
        // names are already lowercased.
        $stmt = $this->db->prepare("SELECT $idCol AS id, zone_name AS name FROM zones WHERE LOWER(zone_name) IN ($placeholders)");
        $stmt->execute($names);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['name']] = (int)$row['id'];
        }

        return $result;
    }

    public function findZonesUnder(string $suffix): array
    {
        $idCol = CanonicalZoneSql::canonicalIdColumn('', $this->backendProvider->allocatesZoneIdsLocally());

        // Escape LIKE wildcards with '=' (not backslash, which MySQL mangles in
        // string literals) so an underscore matches literally; escape '=' first.
        $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $suffix);
        $pattern = '%.' . $escaped;

        $stmt = $this->db->prepare(
            "SELECT $idCol AS id, zone_name AS name FROM zones WHERE LOWER(zone_name) LIKE :pattern ESCAPE '=' ORDER BY zone_name"
        );
        $stmt->execute([':pattern' => $pattern]);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        return $result;
    }
}
