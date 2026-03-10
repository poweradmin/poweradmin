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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Orchestration service for DNS data reads.
 *
 * In SQL mode, delegates to existing repositories (zero behavioral change).
 * In API mode, fetches DNS data from the PowerDNS API via DnsBackendProvider
 * and enriches it with Poweradmin metadata (ownership, comments, templates)
 * from SQL.
 */
class DnsDataService
{
    private DnsBackendProvider $backendProvider;
    private PDO $db;
    private ConfigurationInterface $config;

    public function __construct(
        DnsBackendProvider $backendProvider,
        PDO $db,
        ConfigurationInterface $config
    ) {
        $this->backendProvider = $backendProvider;
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Check if the API backend is active.
     */
    public function isApiBackend(): bool
    {
        return $this->backendProvider->isApiBackend();
    }

    // ---------------------------------------------------------------
    // Forward zone listing
    // ---------------------------------------------------------------

    /**
     * Get forward zones with ownership, pagination, and optional enrichment.
     *
     * In SQL mode, delegates to DomainRepository::getZones().
     * In API mode, fetches from backend and enriches with Poweradmin metadata.
     *
     * @param string $perm 'own' or 'all'
     * @param int $userId Current user ID
     * @param string $letterStart Starting letter filter ('all', single letter, '1' for digits)
     * @param int $rowStart Pagination offset
     * @param int $rowAmount Rows per page
     * @param string $sortBy Sort column
     * @param string $sortDirection 'ASC' or 'DESC'
     * @param bool $showSerial Include SOA serial
     * @param bool $showTemplate Include zone template name
     * @return array Keyed by domain name, matching DomainRepository::getZones() shape
     */
    public function getForwardZones(
        string $perm,
        int $userId,
        string $letterStart,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $showSerial = false,
        bool $showTemplate = false
    ): array {
        if (!$this->backendProvider->isApiBackend()) {
            $domainRepository = new DomainRepository($this->db, $this->config);
            return $domainRepository->getZones(
                $perm,
                $userId,
                $letterStart,
                $rowStart,
                $rowAmount,
                $sortBy,
                $sortDirection,
                true,
                $showSerial,
                $showTemplate
            );
        }

        return $this->getZonesFromApi(
            $perm,
            $userId,
            $letterStart,
            $rowStart,
            $rowAmount,
            $sortBy,
            $sortDirection,
            $showSerial,
            $showTemplate,
            'forward'
        );
    }

    // ---------------------------------------------------------------
    // Reverse zone listing
    // ---------------------------------------------------------------

    /**
     * Get reverse zones with ownership, pagination, and optional enrichment.
     *
     * In SQL mode, delegates to DbZoneRepository::getReverseZones().
     * In API mode, fetches from backend and enriches with Poweradmin metadata.
     *
     * @param string $perm 'own' or 'all'
     * @param int $userId Current user ID
     * @param string $reverseType 'all', 'ipv4', 'ipv6'
     * @param int $offset Pagination offset
     * @param int $limit Rows per page
     * @param string $sortBy Sort column
     * @param string $sortDirection 'ASC' or 'DESC'
     * @param bool $showSerial Include SOA serial
     * @param bool $showTemplate Include zone template name
     * @return array Keyed by domain name, matching DbZoneRepository::getReverseZones() shape
     */
    public function getReverseZones(
        string $perm,
        int $userId,
        string $reverseType,
        int $offset,
        int $limit,
        string $sortBy,
        string $sortDirection,
        bool $showSerial = false,
        bool $showTemplate = false
    ): array {
        if (!$this->backendProvider->isApiBackend()) {
            $zoneRepository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
            return $zoneRepository->getReverseZones(
                $perm,
                $userId,
                $reverseType,
                $offset,
                $limit,
                $sortBy,
                $sortDirection,
                false,
                $showSerial,
                $showTemplate
            );
        }

        return $this->getZonesFromApi(
            $perm,
            $userId,
            'all',
            $offset,
            $limit,
            $sortBy,
            $sortDirection,
            $showSerial,
            $showTemplate,
            'reverse',
            $reverseType
        );
    }

    // ---------------------------------------------------------------
    // Zone counts
    // ---------------------------------------------------------------

    /**
     * Count zones matching criteria.
     *
     * In SQL mode, delegates to ZoneCountService::countZones().
     * In API mode, computes from the full zone list.
     *
     * @param string $perm 'own' or 'all'
     * @param string $letterStart Starting letter filter
     * @param string $zoneType 'forward', 'reverse', or 'all'
     * @return int
     */
    public function countZones(string $perm, string $letterStart = 'all', string $zoneType = 'forward'): int
    {
        if (!$this->backendProvider->isApiBackend()) {
            $zoneCountService = new ZoneCountService($this->db, $this->config);
            return $zoneCountService->countZones($perm, $letterStart, $zoneType);
        }

        $zones = $this->backendProvider->getZones();
        $zones = $this->filterZonesByType($zones, $zoneType);

        if ($perm === 'own') {
            $userId = $_SESSION['userid'] ?? null;
            if ($userId) {
                $zones = $this->filterZonesByOwnership($zones, (int)$userId);
            } else {
                return 0;
            }
        }

        if ($letterStart !== 'all') {
            $zones = ResultPaginator::filterByLetter($zones, $letterStart, 'name');
        }

        return count($zones);
    }

    /**
     * Get reverse zone counts (all, ipv4, ipv6) in a single call.
     *
     * In SQL mode, delegates to DbZoneRepository::getReverseZoneCounts().
     * In API mode, computes from the full zone list.
     *
     * @param string $perm 'own' or 'all'
     * @param int $userId Current user ID
     * @return array{count_all: int, count_ipv4: int, count_ipv6: int}
     */
    public function getReverseZoneCounts(string $perm, int $userId): array
    {
        if (!$this->backendProvider->isApiBackend()) {
            $zoneRepository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
            return $zoneRepository->getReverseZoneCounts($perm, $userId);
        }

        $zones = $this->backendProvider->getZones();

        // Filter to reverse zones only
        $reverseZones = array_filter($zones, function ($zone) {
            $name = $zone['name'] ?? '';
            return str_ends_with($name, '.in-addr.arpa') || str_ends_with($name, '.ip6.arpa');
        });

        if ($perm === 'own') {
            $reverseZones = $this->filterZonesByOwnership($reverseZones, $userId);
        }

        $countIpv4 = 0;
        $countIpv6 = 0;
        foreach ($reverseZones as $zone) {
            $name = $zone['name'] ?? '';
            if (str_ends_with($name, '.in-addr.arpa')) {
                $countIpv4++;
            } elseif (str_ends_with($name, '.ip6.arpa')) {
                $countIpv6++;
            }
        }

        return [
            'count_all' => $countIpv4 + $countIpv6,
            'count_ipv4' => $countIpv4,
            'count_ipv6' => $countIpv6,
        ];
    }

    // ---------------------------------------------------------------
    // Starting letters
    // ---------------------------------------------------------------

    /**
     * Get distinct starting letters for forward zones.
     *
     * In SQL mode, delegates to DbZoneRepository::getDistinctStartingLetters().
     * In API mode, computes from the full zone list.
     *
     * @param int $userId Current user ID
     * @param bool $viewOthers Whether user can view others' zones
     * @return array Unique starting letters
     */
    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array
    {
        if (!$this->backendProvider->isApiBackend()) {
            $zoneRepository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
            return $zoneRepository->getDistinctStartingLetters($userId, $viewOthers);
        }

        $zones = $this->backendProvider->getZones();

        // Filter out reverse zones
        $zones = $this->filterZonesByType($zones, 'forward');

        // Filter by ownership if needed
        if (!$viewOthers) {
            $zones = $this->filterZonesByOwnership($zones, $userId);
        }

        $letters = ResultPaginator::getDistinctLetters($zones, 'name');

        // Filter to only alpha and numeric characters (matching SQL behavior)
        return array_values(array_filter($letters, function ($letter) {
            return ctype_alpha((string)$letter) || is_numeric($letter);
        }));
    }

    // ---------------------------------------------------------------
    // Zone records
    // ---------------------------------------------------------------

    /**
     * Get records for a zone.
     *
     * In SQL mode, delegates to existing record repository methods.
     * In API mode, fetches from backend with in-memory filtering/pagination.
     *
     * @param int $zoneId Zone ID
     * @param string $zoneName Zone name
     * @param int $rowStart Pagination offset
     * @param int $rowAmount Rows per page
     * @param string $sortBy Sort column
     * @param string $sortDirection 'ASC' or 'DESC'
     * @param bool $includeComments Include per-record comments
     * @param string $searchTerm Search in name/content
     * @param string $typeFilter Exact type match
     * @param string $contentFilter Content substring search
     * @return array{records: array, total: int}
     */
    public function getZoneRecords(
        int $zoneId,
        string $zoneName,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $includeComments = false,
        string $searchTerm = '',
        string $typeFilter = '',
        string $contentFilter = ''
    ): array {
        if (!$this->backendProvider->isApiBackend()) {
            return $this->getZoneRecordsSql(
                $zoneId,
                $rowStart,
                $rowAmount,
                $sortBy,
                $sortDirection,
                $includeComments,
                $searchTerm,
                $typeFilter,
                $contentFilter
            );
        }

        return $this->getZoneRecordsApi(
            $zoneId,
            $zoneName,
            $rowStart,
            $rowAmount,
            $sortBy,
            $sortDirection,
            $includeComments,
            $searchTerm,
            $typeFilter,
            $contentFilter
        );
    }

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------

    /**
     * Search zones.
     *
     * In SQL mode, delegates to ZoneSearch.
     * In API mode, uses DnsBackendProvider::searchDnsData() with enrichment.
     */
    public function searchZones(
        array $parameters,
        string $permissionView,
        string $sortBy,
        string $sortDirection,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        if (!$this->backendProvider->isApiBackend()) {
            $dbType = $this->config->get('database', 'type', 'mysql');
            $zoneSearch = new \Poweradmin\Application\Query\ZoneSearch($this->db, $this->config, $dbType);
            return $zoneSearch->searchZones(
                $parameters,
                $permissionView,
                $sortBy,
                $sortDirection,
                $rowAmount,
                $includeComments,
                $page
            );
        }

        return $this->searchZonesApi($parameters, $permissionView, $sortBy, $sortDirection, $rowAmount, $includeComments, $page);
    }

    /**
     * Get total count of matching zones for search.
     */
    public function searchZonesTotalCount(array $parameters, string $permissionView): int
    {
        if (!$this->backendProvider->isApiBackend()) {
            $dbType = $this->config->get('database', 'type', 'mysql');
            $zoneSearch = new \Poweradmin\Application\Query\ZoneSearch($this->db, $this->config, $dbType);
            return $zoneSearch->getTotalZones($parameters, $permissionView);
        }

        // API mode: get all matching zones (unpaginated) and count
        $allZones = $this->searchZonesApi($parameters, $permissionView, 'name', 'ASC', PHP_INT_MAX, false, 1);
        return count($allZones);
    }

    /**
     * Search records.
     *
     * In SQL mode, delegates to RecordSearch.
     * In API mode, uses DnsBackendProvider::searchDnsData() with enrichment.
     */
    public function searchRecords(
        array $parameters,
        string $permissionView,
        string $sortBy,
        string $sortDirection,
        bool $groupRecords,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        if (!$this->backendProvider->isApiBackend()) {
            $dbType = $this->config->get('database', 'type', 'mysql');
            $recordSearch = new \Poweradmin\Application\Query\RecordSearch($this->db, $this->config, $dbType);
            return $recordSearch->searchRecords(
                $parameters,
                $permissionView,
                $sortBy,
                $sortDirection,
                $groupRecords,
                $rowAmount,
                $includeComments,
                $page
            );
        }

        return $this->searchRecordsApi($parameters, $permissionView, $sortBy, $sortDirection, $groupRecords, $rowAmount, $includeComments, $page);
    }

    /**
     * Get total count of matching records for search.
     */
    public function searchRecordsTotalCount(array $parameters, string $permissionView, bool $groupRecords): int
    {
        if (!$this->backendProvider->isApiBackend()) {
            $dbType = $this->config->get('database', 'type', 'mysql');
            $recordSearch = new \Poweradmin\Application\Query\RecordSearch($this->db, $this->config, $dbType);
            return $recordSearch->getTotalRecords($parameters, $permissionView, $groupRecords);
        }

        $allRecords = $this->searchRecordsApi($parameters, $permissionView, 'name', 'ASC', $groupRecords, PHP_INT_MAX, false, 1);
        return count($allRecords);
    }

    // ---------------------------------------------------------------
    // Private: API-mode zone fetching
    // ---------------------------------------------------------------

    /**
     * Fetch zones from API backend with enrichment, filtering, sorting, pagination.
     */
    private function getZonesFromApi(
        string $perm,
        int $userId,
        string $letterStart,
        int $offset,
        int $limit,
        string $sortBy,
        string $sortDirection,
        bool $showSerial,
        bool $showTemplate,
        string $zoneType,
        string $reverseType = 'all'
    ): array {
        $allZones = $this->backendProvider->getZones();

        // Filter by zone type (forward/reverse)
        $allZones = $this->filterZonesByType($allZones, $zoneType, $reverseType);

        // Enrich with Poweradmin metadata (ownership, comments, record counts, DNSSEC)
        $allZones = $this->enrichZonesWithOwnership($allZones);

        // Filter by permission
        if ($perm === 'own') {
            $allZones = $this->filterZonesByOwnership($allZones, $userId);
        } elseif ($perm !== 'all') {
            return [];
        }

        // Apply letter filter (forward zones only)
        if ($zoneType === 'forward' && $letterStart !== 'all') {
            $allZones = ResultPaginator::filterByLetter($allZones, $letterStart, 'name');
        }

        // Map sortBy to match data keys
        $apiSortBy = $sortBy;
        if ($sortBy === 'owner') {
            $apiSortBy = 'owner_username';
        }

        // Sort
        $allZones = ResultPaginator::sort($allZones, $apiSortBy, $sortDirection);

        // Paginate
        $paginatedZones = ResultPaginator::paginate($allZones, $offset, $limit);

        // Enrich with serial and template if requested
        if ($showSerial) {
            $this->enrichZonesWithSerial($paginatedZones);
        }
        if ($showTemplate) {
            $this->enrichZonesWithTemplate($paginatedZones);
        }

        // Convert to expected output shape (keyed by domain name)
        $result = [];
        foreach ($paginatedZones as $zone) {
            $name = $zone['name'];
            $utf8Name = DnsIdnService::toUtf8($name);

            $result[$name] = [
                'id' => $zone['id'] ?? 0,
                'name' => $name,
                'utf8_name' => $utf8Name,
                'type' => $zone['type'] ?? 'NATIVE',
                'count_records' => $zone['count_records'] ?? 0,
                'comment' => $zone['comment'] ?? '',
                'secured' => $zone['dnssec'] ?? false,
                'owners' => $zone['owners'] ?? [],
                'full_names' => $zone['full_names'] ?? [],
                'users' => $zone['owners'] ?? [],
            ];

            if ($showSerial) {
                $result[$name]['serial'] = $zone['serial'] ?? '';
            }
            if ($showTemplate) {
                $result[$name]['template'] = $zone['template'] ?? '';
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Private: Zone filtering
    // ---------------------------------------------------------------

    /**
     * Filter zones by forward/reverse type.
     */
    private function filterZonesByType(array $zones, string $zoneType, string $reverseType = 'all'): array
    {
        if ($zoneType === 'forward') {
            return array_values(array_filter($zones, function ($zone) {
                $name = $zone['name'] ?? '';
                return !str_ends_with($name, '.in-addr.arpa') && !str_ends_with($name, '.ip6.arpa');
            }));
        }

        if ($zoneType === 'reverse') {
            $filtered = array_filter($zones, function ($zone) use ($reverseType) {
                $name = $zone['name'] ?? '';
                if ($reverseType === 'ipv4') {
                    return str_ends_with($name, '.in-addr.arpa');
                }
                if ($reverseType === 'ipv6') {
                    return str_ends_with($name, '.ip6.arpa');
                }
                return str_ends_with($name, '.in-addr.arpa') || str_ends_with($name, '.ip6.arpa');
            });
            return array_values($filtered);
        }

        return $zones;
    }

    /**
     * Filter zones to only those owned by a user (direct or group ownership).
     */
    private function filterZonesByOwnership(array $zones, int $userId): array
    {
        // Get all domain IDs this user owns (directly or via groups)
        $ownedDomainIds = $this->getOwnedDomainIds($userId);

        return array_values(array_filter($zones, function ($zone) use ($ownedDomainIds) {
            $id = $zone['id'] ?? 0;
            return in_array($id, $ownedDomainIds, true);
        }));
    }

    /**
     * Filter records to only those in zones owned by a user.
     */
    private function filterRecordsByZoneOwnership(array $records, int $userId): array
    {
        $ownedDomainIds = $this->getOwnedDomainIds($userId);

        return array_values(array_filter($records, function ($record) use ($ownedDomainIds) {
            $domainId = $record['domain_id'] ?? 0;
            return in_array($domainId, $ownedDomainIds, true);
        }));
    }

    /**
     * Get all domain IDs owned by a user (direct or group ownership).
     *
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

    // ---------------------------------------------------------------
    // Private: Zone enrichment
    // ---------------------------------------------------------------

    /**
     * Enrich zones with Poweradmin ownership, comments, and record counts.
     */
    private function enrichZonesWithOwnership(array $zones): array
    {
        if (empty($zones)) {
            return $zones;
        }

        // Query Poweradmin's zones table for ownership and comments
        $stmt = $this->db->query(
            "SELECT z.domain_id, z.owner, z.comment, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id"
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domainId = (int)$row['domain_id'];
            // Match by ID
            foreach ($zones as $i => &$zone) {
                if (($zone['id'] ?? 0) === $domainId) {
                    if (!isset($zone['owners'])) {
                        $zone['owners'] = [];
                        $zone['full_names'] = [];
                        $zone['owner_ids'] = [];
                        $zone['comment'] = $row['comment'] ?? '';
                    }
                    if ($row['username'] !== null) {
                        $zone['owners'][] = $row['username'];
                        $zone['full_names'][] = $row['fullname'] ?: '';
                        $zone['owner_ids'][] = (int)$row['owner'];
                    }
                }
            }
            unset($zone);
        }

        // Ensure all zones have defaults and set owner_username for sorting
        foreach ($zones as &$zone) {
            if (!isset($zone['owners'])) {
                $zone['owners'] = [];
                $zone['full_names'] = [];
                $zone['owner_ids'] = [];
            }
            $zone['owner_username'] = $zone['owners'][0] ?? '';
        }
        unset($zone);

        // Enrich with record counts from the records table
        $zoneIds = array_filter(array_map(fn($z) => $z['id'] ?? 0, $zones));
        if (!empty($zoneIds)) {
            if ($this->backendProvider->isApiBackend()) {
                $recordCounts = [];
                foreach ($zoneIds as $zid) {
                    $recordCounts[$zid] = $this->backendProvider->countZoneRecords($zid);
                }
            } else {
                $tableNameService = new TableNameService($this->config);
                $recordsTable = $tableNameService->getTable(PdnsTable::RECORDS);
                $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));
                $stmt = $this->db->prepare(
                    "SELECT domain_id, COUNT(*) as count_records FROM $recordsTable
                     WHERE domain_id IN ($placeholders) AND type IS NOT NULL AND type != ''
                     GROUP BY domain_id"
                );
                $stmt->execute(array_values($zoneIds));

                $recordCounts = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $recordCounts[(int)$row['domain_id']] = (int)$row['count_records'];
                }
            }

            foreach ($zones as &$zone) {
                $zone['count_records'] = $recordCounts[$zone['id'] ?? 0] ?? 0;
            }
            unset($zone);
        }

        return $zones;
    }

    /**
     * Enrich zones with SOA serial numbers.
     */
    private function enrichZonesWithSerial(array &$zones): void
    {
        if (empty($zones)) {
            return;
        }

        $recordRepository = new RecordRepository($this->db, $this->config, $this->backendProvider);
        $zoneIds = array_map(fn($zone) => $zone['id'] ?? 0, $zones);
        $serials = $recordRepository->getSerialsByZoneIds($zoneIds);

        foreach ($zones as &$zone) {
            $zone['serial'] = $serials[$zone['id'] ?? 0] ?? '';
        }
        unset($zone);
    }

    /**
     * Enrich zones with template names.
     */
    private function enrichZonesWithTemplate(array &$zones): void
    {
        foreach ($zones as &$zone) {
            $zone['template'] = ZoneTemplate::getZoneTemplName($this->db, $zone['id'] ?? 0);
        }
        unset($zone);
    }

    // ---------------------------------------------------------------
    // Private: SQL-mode record fetching
    // ---------------------------------------------------------------

    /**
     * Get zone records using existing SQL repositories.
     *
     * @return array{records: array, total: int}
     */
    private function getZoneRecordsSql(
        int $zoneId,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $includeComments,
        string $searchTerm,
        string $typeFilter,
        string $contentFilter
    ): array {
        $recordRepository = new RecordRepository($this->db, $this->config);

        if (!empty($searchTerm) || !empty($typeFilter) || !empty($contentFilter)) {
            $records = $recordRepository->getFilteredRecords(
                $zoneId,
                $rowStart,
                $rowAmount,
                $sortBy,
                $sortDirection,
                $includeComments,
                $searchTerm,
                $typeFilter,
                $contentFilter
            );
            $total = $recordRepository->getFilteredRecordCount(
                $zoneId,
                $includeComments,
                $searchTerm,
                $typeFilter,
                $contentFilter
            );
        } else {
            $dbType = $this->config->get('database', 'type', 'mysql');
            $dnsRecord = new \Poweradmin\Domain\Service\DnsRecord($this->db, $this->config);
            $records = $dnsRecord->getRecordsFromDomainId(
                $dbType,
                $zoneId,
                $rowStart,
                $rowAmount,
                $sortBy,
                $sortDirection,
                $includeComments
            );
            $total = $dnsRecord->countZoneRecords($zoneId);
        }

        return ['records' => $records, 'total' => $total];
    }

    // ---------------------------------------------------------------
    // Private: API-mode record fetching
    // ---------------------------------------------------------------

    /**
     * Get zone records from API backend with in-memory filtering/pagination.
     *
     * @return array{records: array, total: int}
     */
    private function getZoneRecordsApi(
        int $zoneId,
        string $zoneName,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $includeComments,
        string $searchTerm,
        string $typeFilter,
        string $contentFilter
    ): array {
        $records = $this->backendProvider->getZoneRecords($zoneId, $zoneName);

        // Apply filters
        if (!empty($searchTerm)) {
            $records = ResultPaginator::filterByPattern($records, $searchTerm, ['name', 'content']);
        }
        if (!empty($typeFilter)) {
            $records = ResultPaginator::filterByValue($records, 'type', $typeFilter);
        }
        if (!empty($contentFilter)) {
            $records = ResultPaginator::filterByPattern($records, $contentFilter, ['content']);
        }

        $total = count($records);

        // Sort
        $records = ResultPaginator::sort($records, $sortBy, $sortDirection);

        // Paginate
        $records = ResultPaginator::paginate($records, $rowStart, $rowAmount);

        // Enrich with comments if requested
        if ($includeComments && !empty($records)) {
            $this->enrichRecordsWithComments($records);
        }

        return ['records' => $records, 'total' => $total];
    }

    /**
     * Enrich records with comments from Poweradmin DB.
     */
    private function enrichRecordsWithComments(array &$records): void
    {
        $recordIds = array_filter(array_map(fn($r) => $r['id'] ?? 0, $records));
        if (empty($recordIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT rcl.record_id, rc.comment
             FROM record_comment_links rcl
             INNER JOIN record_comments rc ON rcl.comment_id = rc.id
             WHERE rcl.record_id IN ($placeholders)"
        );
        $stmt->execute(array_values($recordIds));

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comments[(int)$row['record_id']] = $row['comment'];
        }

        foreach ($records as &$record) {
            $rid = $record['id'] ?? 0;
            $record['comment'] = $comments[$rid] ?? '';
        }
        unset($record);
    }

    // ---------------------------------------------------------------
    // Private: API-mode search
    // ---------------------------------------------------------------

    /**
     * Preprocess search query for API mode: punycode normalization and reverse IP expansion.
     *
     * Mirrors the preprocessing done by BaseSearch::buildSearchString() for SQL mode,
     * so API-mode searches handle IDN domains and reverse lookups consistently.
     */
    private function preprocessSearchQuery(array $parameters): array
    {
        $query = trim($parameters['query'] ?? '');

        // Punycode normalization (matches BaseSearch line 78)
        $query = DnsIdnService::toPunycode($query);

        $parameters['query'] = $query;

        // Reverse IP expansion (matches BaseSearch lines 61-71)
        $reverseQuery = '';
        if (!empty($parameters['reverse'])) {
            $ipValidator = new IPAddressValidator();
            if ($ipValidator->isValidIPv4($query)) {
                $reverseQuery = implode('.', array_reverse(explode('.', $query)));
            } elseif ($ipValidator->isValidIPv6($query)) {
                $hex = unpack('H*hex', inet_pton($query));
                $reverseQuery = implode('.', array_reverse(str_split($hex['hex'])));
            }
        }
        $parameters['reverse_query'] = $reverseQuery;

        return $parameters;
    }

    /**
     * Search zones via API backend with enrichment, filtering, sorting, pagination.
     */
    private function searchZonesApi(
        array $parameters,
        string $permissionView,
        string $sortBy,
        string $sortDirection,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        $query = $parameters['query'] ?? '';
        if (empty($query) || !$parameters['zones']) {
            return [];
        }

        // Preprocess: punycode + reverse IP expansion
        $parameters = $this->preprocessSearchQuery($parameters);
        $query = $parameters['query'];

        $results = $this->backendProvider->searchDnsData($query, 'zone', 0);
        $zones = $results['zones'] ?? [];

        // If reverse query exists, issue second search and merge unique results
        $reverseQuery = $parameters['reverse_query'] ?? '';
        if (!empty($reverseQuery)) {
            $reverseResults = $this->backendProvider->searchDnsData($reverseQuery, 'zone', 0);
            $reverseZones = $reverseResults['zones'] ?? [];
            if (!empty($reverseZones)) {
                $seenNames = array_flip(array_column($zones, 'name'));
                foreach ($reverseZones as $rz) {
                    if (!isset($seenNames[$rz['name'] ?? ''])) {
                        $zones[] = $rz;
                    }
                }
            }
        }

        if (empty($zones)) {
            return [];
        }

        // If wildcard is explicitly off, post-filter for exact match
        // Also allow matches against the reverse query (e.g. reverse-IP zone names)
        if (isset($parameters['wildcard']) && !$parameters['wildcard']) {
            $zones = array_values(array_filter($zones, function ($zone) use ($query, $reverseQuery) {
                $name = $zone['name'] ?? '';
                return strcasecmp($name, $query) === 0
                    || (!empty($reverseQuery) && strcasecmp($name, $reverseQuery) === 0);
            }));
            if (empty($zones)) {
                return [];
            }
        }

        // Enrich with ownership, record counts
        $zones = $this->enrichZonesWithOwnership($zones);

        // Filter by permission
        if ($permissionView === 'own') {
            $userId = $_SESSION['userid'] ?? null;
            if ($userId) {
                $zones = $this->filterZonesByOwnership($zones, (int)$userId);
            } else {
                return [];
            }
        }

        // Map sortBy to data keys
        $apiSortBy = $sortBy;
        if ($sortBy === 'fullname') {
            $apiSortBy = 'owner_username';
        }

        // Sort
        $zones = ResultPaginator::sort($zones, $apiSortBy, $sortDirection);

        // Paginate
        $offset = ($page - 1) * $rowAmount;
        $zones = ResultPaginator::paginate($zones, $offset, $rowAmount);

        // Format to match template shape
        $result = [];
        foreach ($zones as $zone) {
            $formatted = [
                'id' => $zone['id'] ?? 0,
                'name' => DnsIdnService::toUtf8($zone['name'] ?? ''),
                'type' => $zone['type'] ?? '',
                'count_records' => $zone['count_records'] ?? 0,
                'user_id' => $zone['owner_ids'][0] ?? 0,
                'fullname' => $this->formatOwnerFullnames($zone),
                'owner_fullnames' => $zone['full_names'] ?? [],
                'owner_usernames' => $zone['owners'] ?? [],
            ];

            if ($includeComments) {
                $formatted['comment'] = $zone['comment'] ?? '';
            }

            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * Search records via API backend with enrichment, filtering, sorting, pagination.
     */
    private function searchRecordsApi(
        array $parameters,
        string $permissionView,
        string $sortBy,
        string $sortDirection,
        bool $groupRecords,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        $query = $parameters['query'] ?? '';
        if (empty($query) || !$parameters['records']) {
            return [];
        }

        // Preprocess: punycode + reverse IP expansion
        $parameters = $this->preprocessSearchQuery($parameters);
        $query = $parameters['query'];

        $results = $this->backendProvider->searchDnsData($query, 'record', 0);
        $records = $results['records'] ?? [];

        // If reverse query exists, issue second search and merge unique results
        $reverseQuery = $parameters['reverse_query'] ?? '';
        if (!empty($reverseQuery)) {
            $reverseResults = $this->backendProvider->searchDnsData($reverseQuery, 'record', 0);
            $reverseRecords = $reverseResults['records'] ?? [];
            if (!empty($reverseRecords)) {
                $seenKeys = [];
                foreach ($records as $r) {
                    $seenKeys[($r['name'] ?? '') . '|' . ($r['type'] ?? '') . '|' . ($r['content'] ?? '')] = true;
                }
                foreach ($reverseRecords as $rr) {
                    $key = ($rr['name'] ?? '') . '|' . ($rr['type'] ?? '') . '|' . ($rr['content'] ?? '');
                    if (!isset($seenKeys[$key])) {
                        $records[] = $rr;
                    }
                }
            }
        }

        if (empty($records)) {
            return [];
        }

        // If wildcard is explicitly off, post-filter for exact match on name or content
        // Also allow matches against the reverse query (e.g. reverse PTR lookups from an IP)
        if (isset($parameters['wildcard']) && !$parameters['wildcard']) {
            $records = array_values(array_filter($records, function ($record) use ($query, $reverseQuery) {
                $name = $record['name'] ?? '';
                $content = $record['content'] ?? '';
                return strcasecmp($name, $query) === 0
                    || strcasecmp($content, $query) === 0
                    || (!empty($reverseQuery) && (strcasecmp($name, $reverseQuery) === 0 || strcasecmp($content, $reverseQuery) === 0));
            }));
            if (empty($records)) {
                return [];
            }
        }

        // Apply type filter
        $typeFilter = $parameters['type_filter'] ?? '';
        if (!empty($typeFilter)) {
            $records = ResultPaginator::filterByValue($records, 'type', strtoupper($typeFilter));
        }

        // Apply content filter
        $contentFilter = $parameters['content_filter'] ?? '';
        if (!empty($contentFilter)) {
            $records = ResultPaginator::filterByPattern($records, $contentFilter, ['content']);
        }

        // Filter by permission (via zone ownership)
        if ($permissionView === 'own') {
            $userId = $_SESSION['userid'] ?? null;
            if ($userId) {
                $records = $this->filterRecordsByZoneOwnership($records, (int)$userId);
            } else {
                return [];
            }
        }

        // Enrich with zone ownership data for template
        $this->enrichRecordsWithZoneOwnership($records);

        // Group by name|content if requested (deduplicate)
        if ($groupRecords) {
            $seen = [];
            $records = array_values(array_filter($records, function ($record) use (&$seen) {
                $key = ($record['name'] ?? '') . '|' . ($record['content'] ?? '');
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                return true;
            }));
        }

        // Sort
        $records = ResultPaginator::sort($records, $sortBy, $sortDirection);

        // Paginate
        $offset = ($page - 1) * $rowAmount;
        $records = ResultPaginator::paginate($records, $offset, $rowAmount);

        // Format to match template shape
        $result = [];
        foreach ($records as $record) {
            $formatted = [
                'id' => $record['id'] ?? 0,
                'domain_id' => $record['domain_id'] ?? 0,
                'name' => DnsIdnService::toUtf8($record['name'] ?? ''),
                'type' => $record['type'] ?? '',
                'content' => $record['content'] ?? '',
                'ttl' => $record['ttl'] ?? 0,
                'prio' => $record['prio'] ?? 0,
                'disabled' => ($record['disabled'] ?? 0) == 1 ? _('Yes') : _('No'),
                'user_id' => $record['zone_owner_id'] ?? 0,
                'fullname' => $record['zone_owner_fullname'] ?? '',
            ];

            $result[] = $formatted;
        }

        // Bulk enrich with comments in a single query instead of per-record
        if ($includeComments) {
            $this->enrichRecordsWithComments($result);
        }

        return $result;
    }

    /**
     * Enrich records with zone ownership data for search results.
     */
    private function enrichRecordsWithZoneOwnership(array &$records): void
    {
        if (empty($records)) {
            return;
        }

        // Get unique domain IDs
        $domainIds = array_unique(array_filter(array_map(fn($r) => $r['domain_id'] ?? 0, $records)));
        if (empty($domainIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT z.domain_id, z.owner, u.id as user_id, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id
             WHERE z.domain_id IN ($placeholders)"
        );
        $stmt->execute(array_values($domainIds));

        $ownershipMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $did = (int)$row['domain_id'];
            $ownershipMap[$did] = [
                'zone_owner_id' => (int)($row['user_id'] ?? 0),
                'zone_owner_fullname' => $row['fullname'] ?? '',
                'zone_owner_username' => $row['username'] ?? '',
            ];
        }

        foreach ($records as &$record) {
            $did = $record['domain_id'] ?? 0;
            if (isset($ownershipMap[$did])) {
                $record['zone_owner_id'] = $ownershipMap[$did]['zone_owner_id'];
                $record['zone_owner_fullname'] = $ownershipMap[$did]['zone_owner_fullname'];
            } else {
                $record['zone_owner_id'] = 0;
                $record['zone_owner_fullname'] = '';
            }
        }
        unset($record);
    }

    /**
     * Format owner fullnames for display (matching ZoneSearch format).
     */
    private function formatOwnerFullnames(array $zone): string
    {
        $owners = $zone['owners'] ?? [];
        $fullNames = $zone['full_names'] ?? [];
        $parts = [];
        foreach ($owners as $i => $username) {
            $fullname = $fullNames[$i] ?? '';
            $parts[] = $fullname ? "$fullname ($username)" : $username;
        }
        return implode(', ', $parts);
    }
}
