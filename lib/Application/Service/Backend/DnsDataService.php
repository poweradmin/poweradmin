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

namespace Poweradmin\Application\Service\Backend;

use PDO;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Zone\ZoneCountService;
use Poweradmin\Domain\Port\RecordSearchInterface;
use Poweradmin\Domain\Port\ZoneSearchInterface;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * Orchestration service for DNS data reads.
 *
 * In SQL mode, delegates to existing repositories (zero behavioral change).
 * In API mode, fetches DNS data from the PowerDNS API via DnsBackendProviderInterface
 * and enriches it with Poweradmin metadata (ownership, comments, templates)
 * from SQL.
 */
class DnsDataService
{
    private DnsBackendProviderInterface $backendProvider;
    private ActorInterface $actor;
    private ?ZoneSyncService $zoneSyncService = null;
    private RepositoryFactory $repositoryFactory;
    private ?ZoneSearchInterface $zoneSearch = null;
    private ?RecordSearchInterface $recordSearch = null;

    public function __construct(
        RepositoryFactory $repositoryFactory,
        DnsBackendProviderInterface $backendProvider,
        PDO $db,
        ActorInterface $actor
    ) {
        $this->repositoryFactory = $repositoryFactory;
        $this->backendProvider = $backendProvider;
        $this->actor = $actor;

        if ($backendProvider->isApiBackend()) {
            $this->zoneSyncService = new ZoneSyncService($db, $backendProvider, new PhpSession());
        }
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
     * @return array<string, ZoneSummary> Keyed by domain name
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
        bool $showTemplate = false,
        bool $includeRecordCount = true
    ): array {
        $domainRepository = $this->repositoryFactory->createDomainRepository();
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
            $showTemplate,
            true,
            $includeRecordCount
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
     * @return array<string, ZoneSummary> Keyed by domain name
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
        bool $showTemplate = false,
        bool $includeRecordCount = true
    ): array {
        $zoneRepository = $this->repositoryFactory->createZoneRepository();
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
            $showTemplate,
            true,
            $includeRecordCount
        );
    }

    // ---------------------------------------------------------------
    // Zone counts
    // ---------------------------------------------------------------

    /**
     * Count zones matching criteria.
     *
     * Delegates to the zone repository through ZoneCountService.
     *
     * @param string $perm 'own' or 'all'
     * @param string $letterStart Starting letter filter
     * @param string $zoneType 'forward', 'reverse', or 'all'
     * @return int
     */
    public function countZones(string $perm, string $letterStart = 'all', string $zoneType = 'forward'): int
    {
        $zoneCountService = new ZoneCountService($this->repositoryFactory->createZoneRepository(), $this->actor);
        return $zoneCountService->countZones($perm, $letterStart, $zoneType);
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
        $this->zoneSyncService?->syncIfStale();
        $zoneRepository = $this->repositoryFactory->createZoneRepository();
        return $zoneRepository->getReverseZoneCounts($perm, $userId);
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
        $this->zoneSyncService?->syncIfStale();
        $zoneRepository = $this->repositoryFactory->createZoneRepository();
        return $zoneRepository->getDistinctStartingLetters($userId, $viewOthers);
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
        return $this->getFilteredRecordsForZone(
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

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------

    /**
     * Search zones through the backend's search; an 'own' view is limited to
     * the logged-in user's zones.
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
        return $this->zoneSearch()->searchZones(
            $parameters,
            $permissionView,
            $this->actor->userId(),
            $sortBy,
            $sortDirection,
            $rowAmount,
            $includeComments,
            $page
        );
    }

    /**
     * Get total count of matching zones for search.
     */
    public function searchZonesTotalCount(array $parameters, string $permissionView): int
    {
        return $this->zoneSearch()->getTotalZones($parameters, $permissionView, $this->actor->userId());
    }

    /**
     * Search records through the backend's search. Rows carry `disabled` as a bool
     * in both modes; an 'own' view is limited to the logged-in user's zones.
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
        return $this->recordSearch()->searchRecords(
            $parameters,
            $permissionView,
            $this->actor->userId(),
            $sortBy,
            $sortDirection,
            $groupRecords,
            $rowAmount,
            $includeComments,
            $page
        );
    }

    /**
     * Get total count of matching records for search.
     */
    public function searchRecordsTotalCount(array $parameters, string $permissionView, bool $groupRecords): int
    {
        return $this->recordSearch()->getTotalRecords($parameters, $permissionView, $this->actor->userId(), $groupRecords);
    }

    private function zoneSearch(): ZoneSearchInterface
    {
        return $this->zoneSearch ??= $this->repositoryFactory->createZoneSearch();
    }

    private function recordSearch(): RecordSearchInterface
    {
        return $this->recordSearch ??= $this->repositoryFactory->createRecordSearch();
    }

    // ---------------------------------------------------------------
    // Private: SQL-mode record fetching
    // ---------------------------------------------------------------

    /**
     * Get filtered records for a zone using the repository.
     *
     * @return array{records: array, total: int}
     */
    private function getFilteredRecordsForZone(
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
        $recordRepository = $this->repositoryFactory->createRecordRepository();
        $hasFilters = !empty($searchTerm) || !empty($typeFilter) || !empty($contentFilter);

        if ($hasFilters) {
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
            // Use getRecordsFromDomainId for unfiltered requests to preserve
            // special SOA-first, NS-second, apex-third ordering
            $records = $recordRepository->getRecordsFromDomainId(
                $zoneId,
                $rowStart,
                $rowAmount,
                $sortBy,
                $sortDirection,
                $includeComments
            );
            $total = $recordRepository->countZoneRecords($zoneId);
        }

        return ['records' => $records, 'total' => $total];
    }
}
