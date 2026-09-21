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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Presenter\SearchResultPresenter;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\SearchCriteria;
use Poweradmin\Domain\Enum\AccessScope;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Handles the search page: finds zones and records by name or content with paging and sorting.
 */
class SearchController extends BaseController
{
    private ZoneSortingService $zoneSortingService;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);
        $this->zoneSortingService = $this->createZoneSortingService();
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_SEARCH, _("You do not have the permission to perform searches."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('search');
        $this->setPageTitle(_('Search'));

        $criteria = SearchCriteria::fromRequest($this->isPost() ? $this->httpRequest->getPostParams() : null);
        $parameters = $criteria->toArray();

        $totalZones = 0;
        $searchResultZones = [];
        $zones_page = 1;
        $totalRecords = 0;
        $searchResultRecords = [];
        $records_page = 1;

        // Sorting by owner data the user cannot fully see would leak ownership
        // through row order, so it needs "all" scope, or "own" scope with
        // results already limited to owned zones.
        $permissionService = $this->services()->permissionService();
        $userId = (int)$this->getCurrentUserId();
        $ownershipViewPermission = $permissionService->getZoneOwnershipViewPermissionLevel($userId);
        $ownerSortAllowed = $ownershipViewPermission === 'all'
            || ($ownershipViewPermission === 'own' && $permissionService->getViewPermissionLevel($userId) === 'own');
        // In API mode record counts are resolved per page, so sorting on them
        // would only order the rows already on screen
        $isRecordCountSortSupported = !DnsBackendProviderFactory::isApiBackend($this->getConfig());
        $allowedZoneSort = ['name', 'type'];
        if ($isRecordCountSortSupported) {
            $allowedZoneSort[] = 'count_records';
        }
        if ($ownerSortAllowed) {
            $allowedZoneSort[] = 'fullname';
        }

        list($zone_sort_by, $zone_sort_direction) = $this->zoneSortingService->getZoneSortOrder(
            $allowedZoneSort,
            SessionKeys::SEARCH_ZONE_SORT_BY,
            submittedSortBy: $this->httpRequest->getPostParam('zone_sort_by') ?? $this->httpRequest->getQueryParam('zone_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('zone_sort_by_direction') ?? $this->httpRequest->getQueryParam('zone_sort_by_direction')
        );
        list($record_sort_by, $record_sort_direction) = $this->zoneSortingService->getZoneSortOrder(
            ['name', 'type', 'prio', 'content', 'ttl', 'disabled'],
            SessionKeys::SEARCH_RECORD_SORT_BY,
            submittedSortBy: $this->httpRequest->getPostParam('record_sort_by') ?? $this->httpRequest->getQueryParam('record_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('record_sort_by_direction') ?? $this->httpRequest->getQueryParam('record_sort_by_direction')
        );

        $zone_rowamount = $record_rowamount = $this->resolveRowsPerPage();
        if ($this->isPost()) {
            // rows_per_page is the legacy single control and still overrides both when posted.
            $bothRows = PaginationService::acceptedRowsPerPage($this->httpRequest->getPostParam('rows_per_page'));
            $zone_rowamount = $bothRows ?? PaginationService::acceptedRowsPerPage($this->httpRequest->getPostParam('zones_rows_per_page')) ?? $zone_rowamount;
            $record_rowamount = $bothRows ?? PaginationService::acceptedRowsPerPage($this->httpRequest->getPostParam('records_rows_per_page')) ?? $record_rowamount;
        }
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);
        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);

        if ($this->isPost()) {
            $zones_page = max(1, (int)$this->httpRequest->getPostParam('zones_page', 1));

            $permission_view = $permissionService->getViewPermissionLevel($userId);

            $dnsDataService = $this->services()->dnsDataService();

            $searchResultZones = $dnsDataService->searchZones(
                $parameters,
                $permission_view,
                $zone_sort_by,
                $zone_sort_direction,
                $zone_rowamount,
                $iface_zone_comments,
                $zones_page
            );

            $totalZones = $dnsDataService->searchZonesTotalCount($parameters, $permission_view);

            $records_page = max(1, (int)$this->httpRequest->getPostParam('records_page', 1));

            $iface_search_group_records = $this->config->get('interface', 'search_group_records', false);
            $searchResultRecords = $dnsDataService->searchRecords(
                $parameters,
                $permission_view,
                $record_sort_by,
                $record_sort_direction,
                $iface_search_group_records,
                $record_rowamount,
                $iface_record_comments,
                $records_page
            );

            $totalRecords = $dnsDataService->searchRecordsTotalCount($parameters, $permission_view, $iface_search_group_records);

            // Shorten IPv6 addresses in AAAA record content for display
            $searchResultRecords = SearchResultPresenter::records($this->shortenIPv6InRecords($searchResultRecords));
        }

        $editPermission = $permissionService->getEditPermissionLevel($userId);
        $deletePermission = $permissionService->getDeletePermissionLevel($userId);

        // Per-row eligibility must include group ownership; otherwise zones owned only
        // via a group lose their edit/delete buttons even when the user has the action.
        [$searchResultZones, $searchResultRecords] = $this->attachPermissionFlags(
            $searchResultZones,
            $searchResultRecords,
            $userId,
            $editPermission,
            $deletePermission,
            $ownershipViewPermission
        );

        $this->showSearchForm(
            $parameters,
            $searchResultZones,
            $searchResultRecords,
            $zone_sort_by,
            $zone_sort_direction,
            $record_sort_by,
            $record_sort_direction,
            $totalZones,
            $totalRecords,
            $zones_page,
            $records_page,
            $zone_rowamount,
            $record_rowamount,
            $iface_zone_comments,
            $iface_record_comments,
            $editPermission,
            $deletePermission,
            $ownershipViewPermission,
            $ownerSortAllowed,
            $isRecordCountSortSupported
        );
    }

    private function showSearchForm(
        $parameters,
        $searchResultZones,
        $searchResultRecords,
        $zone_sort_by,
        $zone_sort_direction,
        $record_sort_by,
        $record_sort_direction,
        $totalZones,
        $totalRecords,
        $zones_page,
        $records_page,
        $zone_rowamount,
        $record_rowamount,
        $iface_zone_comments,
        $iface_record_comments,
        string $editPermission,
        string $deletePermission,
        string $ownershipViewPermission,
        bool $ownerSortAllowed,
        bool $isRecordCountSortSupported
    ): void {
        // Get all record types for the filter dropdown
        $recordTypeService = new RecordTypeService($this->getConfig());
        $recordTypes = $recordTypeService->getAllTypes($this->getRecordTypeCapabilities());

        $can_bulk_delete_zones = AccessScope::fromString($deletePermission)->grantsAnything();
        $can_bulk_delete_records = AccessScope::fromString($editPermission)->grantsAnything();

        $this->render('search.html', [
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
            'is_record_count_sort_supported' => $isRecordCountSortSupported,
            'record_sort_by' => $record_sort_by,
            'record_sort_direction' => $record_sort_direction,
            'query' => isset($parameters['displayed_query']) ? $parameters['displayed_query'] : $parameters['query'],
            'search_by_zones' => $parameters['zones'],
            'search_by_records' => $parameters['records'],
            'search_by_comments' => $parameters['comments'],
            'search_by_wildcard' => $parameters['wildcard'],
            'search_by_reverse' => $parameters['reverse'],
            'type_filter' => $parameters['type_filter'],
            'content_filter' => $parameters['content_filter'],
            'has_zones' => !empty($searchResultZones),
            'has_records' => !empty($searchResultRecords),
            'found_zones' => $searchResultZones,
            'found_records' => $searchResultRecords,
            'total_zones' => $totalZones,
            'total_records' => $totalRecords,
            'zones_page' => $zones_page,
            'records_page' => $records_page,
            'zones_pager' => PaginationService::pagerWindow($totalZones, $zone_rowamount, $zones_page),
            'records_pager' => PaginationService::pagerWindow($totalRecords, $record_rowamount, $records_page),
            'zone_rowamount' => $zone_rowamount,
            'record_rowamount' => $record_rowamount,
            'iface_zone_comments' => $iface_zone_comments,
            'iface_record_comments' => $iface_record_comments,
            'edit_permission' => $editPermission,
            'delete_permission' => $deletePermission,
            'can_bulk_delete_zones' => $can_bulk_delete_zones,
            'can_bulk_delete_records' => $can_bulk_delete_records,
            'user_id' => $this->getCurrentUserId(),
            'show_zone_owners' => $ownershipViewPermission !== 'none',
            'is_owner_sort_supported' => $ownerSortAllowed,
            'whois_action_patterns' => $this->moduleCapabilityData('whois_lookup'),
            'rdap_action_patterns' => $this->moduleCapabilityData('rdap_lookup'),
            'record_types' => $recordTypes,
        ]);
    }

    /**
     * Stamp `user_can_edit` / `user_can_delete` onto each search result so the
     * template can render per-row controls without re-running permission checks.
     *
     * @return array{0: array, 1: array} Augmented zones and records.
     */
    private function attachPermissionFlags(
        array $zones,
        array $records,
        int $userId,
        string $editPermission,
        string $deletePermission,
        string $ownershipViewPermission
    ): array {
        $ownership = $this->services()->zoneListPermissionService()->index($userId, array_merge(
            array_map(fn($z) => (int)($z['id'] ?? 0), $zones),
            array_map(fn($r) => (int)($r['domain_id'] ?? 0), $records)
        ));

        foreach ($zones as &$zone) {
            $domainId = (int)($zone['id'] ?? 0);
            $zone['user_can_edit'] = $ownership->allows($editPermission, $domainId);
            $zone['user_can_delete'] = $ownership->allows($deletePermission, $domainId);

            // At the "own" ownership view level, owner cells stay visible only
            // for zones the user owns directly or via a group.
            if ($ownershipViewPermission === 'own' && !$ownership->owns($domainId)) {
                unset($zone['owner_fullnames'], $zone['owner_usernames']);
                $zone['fullname'] = '';
            }
        }
        unset($zone);

        foreach ($records as &$record) {
            $record['user_can_edit'] = $ownership->allows($editPermission, (int)($record['domain_id'] ?? 0));
            $record['display_name'] ??= $record['name'] ?? '';
        }
        unset($record);

        return [$zones, $records];
    }

    /**
     * Shorten IPv6 addresses in records for better display
     *
     * - AAAA records: Shortens IPv6 content (e.g., 2001:0db8:0000:... -> 2001:db8::...)
     * - PTR records in ip6.arpa: Shortens the record name display
     *
     * @param array $records Array of record data
     * @return array Records with shortened IPv6 addresses
     */
    private function shortenIPv6InRecords(array $records): array
    {
        foreach ($records as &$record) {
            // Shorten IPv6 addresses in AAAA record content
            if (isset($record['type']) && $record['type'] === 'AAAA' && isset($record['content'])) {
                $record['content'] = IpHelper::shortenIPv6Address($record['content']);
            }

            // Shorten IPv6 reverse zone names (PTR records) for display
            if (isset($record['name'])) {
                $record['display_name'] = IpHelper::displayZoneName($record['name']);
            }
        }
        return $records;
    }
}
