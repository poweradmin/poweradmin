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
use Poweradmin\Application\Service\ResultPaginator;
use Poweradmin\Application\Service\ZoneSyncService;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * API-backend domain repository.
 * Fetches zone data via PowerDNS REST API, uses Poweradmin DB for ownership.
 */
class ApiDomainRepository implements DomainRepositoryInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private DnsBackendProvider $backendProvider;

    public function __construct(PDO $db, ConfigurationManager $config, DnsBackendProvider $backendProvider)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->backendProvider = $backendProvider;
    }

    public function zoneIdExists(int $zid): int
    {
        $zone = $this->backendProvider->getZoneById($zid);
        return $zone !== null ? 1 : 0;
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

    public function getZoneIdFromName(string $zname): ?int
    {
        if (empty($zname)) {
            return null;
        }
        return $this->backendProvider->getZoneIdByName($zname);
    }

    public function getDomainType(int $id): string
    {
        return $this->backendProvider->getZoneTypeById($id);
    }

    public function getDomainSlaveMaster(int $id): ?string
    {
        return $this->backendProvider->getZoneMasterById($id);
    }

    public function domainExists(string $domain): bool
    {
        if (!$this->hostnameValidator->isValid($domain)) {
            $this->messageService->addSystemError(_('This is an invalid zone name.'));
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
        ?bool $showTemplate = null
    ): array {
        $allowedSortColumns = ['name', 'type', 'count_records', 'owner'];
        $tableNameService = new TableNameService($this->config);
        $sortby = $tableNameService->validateOrderBy($sortby, $allowedSortColumns);
        $sortDirection = $tableNameService->validateDirection($sortDirection);

        if ($perm !== 'own' && $perm !== 'all') {
            return [];
        }

        $iface_zonelist_serial = $showSerial ?? $this->config->get('interface', 'display_serial_in_zone_list');
        $iface_zonelist_template = $showTemplate ?? $this->config->get('interface', 'display_template_in_zone_list');

        // Sync local zones table with PowerDNS API before listing
        $syncService = new ZoneSyncService($this->db, $this->backendProvider);
        $syncService->syncIfStale();

        $allZones = $this->backendProvider->getZones();

        // Filter reverse zones if requested
        if ($excludeReverse) {
            $allZones = array_values(array_filter($allZones, function ($zone) {
                $name = $zone['name'] ?? '';
                return !str_ends_with($name, '.in-addr.arpa') && !str_ends_with($name, '.ip6.arpa');
            }));
        }

        // Enrich with ownership from local tables
        $allZones = $this->enrichZonesWithOwnership($allZones);

        // Enrich with record counts from API
        $this->enrichWithRecordCounts($allZones);

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

        // Convert to expected output shape (keyed by domain name)
        $result = [];
        foreach ($allZones as $zone) {
            $name = $zone['name'];
            $utf8Name = DnsIdnService::toUtf8($name);

            $result[$name] = [
                'id' => $zone['id'] ?? 0,
                'name' => $name,
                'utf8_name' => $utf8Name,
                'type' => $zone['type'] ?? 'NATIVE',
                'count_records' => $zone['count_records'] ?? 0,
                'comment' => $zone['comment'] ?? '',
                'owners' => $zone['owners'] ?? [],
                'full_names' => $zone['full_names'] ?? [],
                'users' => $zone['owners'] ?? [],
            ];

            if (isset($zone['secured'])) {
                $result[$name]['secured'] = $zone['secured'];
            }

            if ($iface_zonelist_serial) {
                $recordRepository = new ApiRecordRepository($this->backendProvider);
                $result[$name]['serial'] = $recordRepository->getSerialByZid($zone['id'] ?? 0);
            }

            if ($iface_zonelist_template) {
                $result[$name]['template'] = ZoneTemplate::getZoneTemplName($this->db, $zone['id'] ?? 0);
            }
        }

        return $result;
    }

    public function getZoneInfoFromId(int $zid): array
    {
        $perm_view = Permission::getViewPermission($this->db);

        if ($perm_view == "none") {
            $this->messageService->addSystemError(_("You do not have the permission to view this zone."));
            return [];
        }

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
        $zone_infos = array();
        foreach ($zones as $zone) {
            $zone_info = $this->getZoneInfoFromId($zone);
            $zone_infos[] = $zone_info;
        }
        return $zone_infos;
    }

    public function getBestMatchingZoneIdFromName(string $domain): int
    {
        return $this->backendProvider->getBestMatchingReverseZoneId($domain);
    }

    private function enrichWithRecordCounts(array &$zones): void
    {
        foreach ($zones as &$zone) {
            $zone['count_records'] = $this->backendProvider->countZoneRecords($zone['id'] ?? 0);
        }
        unset($zone);
    }

    private function enrichZonesWithOwnership(array $zones): array
    {
        if (empty($zones)) {
            return $zones;
        }

        $stmt = $this->db->query(
            "SELECT z.domain_id, z.owner, z.comment, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id"
        );

        $ownershipMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domainId = (int)$row['domain_id'];
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
            "SELECT DISTINCT domain_id FROM zones WHERE owner = :uid
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
}
