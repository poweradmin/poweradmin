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

/**
 * Script that handles search requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use PDO;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\ZoneSortingService;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Module\ModuleRegistry;

class SearchController extends BaseController
{
    private ZoneSortingService $zoneSortingService;
    private Request $request;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);
        $this->request = new Request();
        $this->zoneSortingService = new ZoneSortingService();
    }

    public function run(): void
    {
        $this->checkPermission('search', _("You do not have the permission to perform searches."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('search');
        $this->setPageTitle(_('Search'));

        $parameters = [
            'query' => '',
            'zones' => true,
            'records' => true,
            'wildcard' => true,
            'reverse' => true,
            'comments' => false,
            'type_filter' => '',
            'content_filter' => '',
        ];

        $totalZones = 0;
        $searchResultZones = [];
        $zones_page = 1;
        $totalRecords = 0;
        $searchResultRecords = [];
        $records_page = 1;

        list($zone_sort_by, $zone_sort_direction) = $this->zoneSortingService->getZoneSortOrder(
            'zone_sort_by',
            ['name', 'type', 'count_records', 'fullname'],
            SessionKeys::SEARCH_ZONE_SORT_BY
        );
        list($record_sort_by, $record_sort_direction) = $this->zoneSortingService->getZoneSortOrder(
            'record_sort_by',
            ['name', 'type', 'prio', 'content', 'ttl', 'disabled'],
            SessionKeys::SEARCH_RECORD_SORT_BY
        );

        // Get default rows per page from config
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);

        // Create pagination service and get user preference
        $paginationService = $this->createPaginationService();
        $userId = $this->getCurrentUserId();

        // Get zones rows per page
        $zone_rowamount = $paginationService->getUserRowsPerPage($default_rowamount, $userId);
        // Override with POST parameter if available for zones
        $zones_rows_per_page = $this->request->getPostParam('zones_rows_per_page');
        if ($this->isPost() && $zones_rows_per_page !== null && is_numeric($zones_rows_per_page)) {
            $post_rows_per_page = (int)$zones_rows_per_page;
            // Validate against allowed values
            if (in_array($post_rows_per_page, [10, 20, 50, 100])) {
                $zone_rowamount = $post_rows_per_page;
            }
        }

        // Get records rows per page
        $record_rowamount = $paginationService->getUserRowsPerPage($default_rowamount, $userId);
        // Override with POST parameter if available for records
        $records_rows_per_page = $this->request->getPostParam('records_rows_per_page');
        if ($this->isPost() && $records_rows_per_page !== null && is_numeric($records_rows_per_page)) {
            $post_rows_per_page = (int)$records_rows_per_page;
            // Validate against allowed values
            if (in_array($post_rows_per_page, [10, 20, 50, 100])) {
                $record_rowamount = $post_rows_per_page;
            }
        }

        // Backward compatibility
        $rows_per_page = $this->request->getPostParam('rows_per_page');
        if ($this->isPost() && $rows_per_page !== null && is_numeric($rows_per_page)) {
            $post_rows_per_page = (int)$rows_per_page;
            // Validate against allowed values
            if (in_array($post_rows_per_page, [10, 20, 50, 100])) {
                $zone_rowamount = $post_rows_per_page;
                $record_rowamount = $post_rows_per_page;
            }
        }
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);
        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);

        if ($this->isPost()) {
            $this->validateCsrfToken();

            $query = $this->request->getPostParam('query');
            $rawQuery = !empty($query) ? $query : '';

            // Parse query for embedded filters
            list($cleanQuery, $extractedFilters) = $this->parseQueryFilters($rawQuery);

            // Keep the original query for display in the search box
            $displayed_query = $rawQuery;

            // Use the cleaned query (without filters) for actual searching
            $parameters['query'] = htmlspecialchars($cleanQuery);

            // Store the original query for display purposes
            $parameters['displayed_query'] = htmlspecialchars($displayed_query);

            $zones = $this->request->getPostParam('zones');
            $records = $this->request->getPostParam('records');
            $wildcard = $this->request->getPostParam('wildcard');
            $reverse = $this->request->getPostParam('reverse');
            $comments = $this->request->getPostParam('comments');
            $parameters['zones'] = $zones !== null ? htmlspecialchars($zones) : false;
            $parameters['records'] = $records !== null ? htmlspecialchars($records) : false;
            $parameters['wildcard'] = $wildcard !== null ? htmlspecialchars($wildcard) : false;
            $parameters['reverse'] = $reverse !== null ? htmlspecialchars($reverse) : false;
            $parameters['comments'] = $comments !== null ? htmlspecialchars($comments) : false;

            // A bare IP query should always search records and reverse zones, even when
            // the user did not tick those boxes - that is almost certainly a PTR lookup.
            $ipValidator = new IPAddressValidator();
            if ($ipValidator->isValidIPv4($parameters['query']) || $ipValidator->isValidIPv6($parameters['query'])) {
                $parameters['records'] = true;
                $parameters['reverse'] = true;
            }

            // Only use extracted type and content filters from the query string
            // This ensures filters from the search box always take precedence
            if (!empty($extractedFilters['type'])) {
                $parameters['type_filter'] = htmlspecialchars($extractedFilters['type']);
                // Enable records search if type filter is found in query
                $parameters['records'] = true;
            } else {
                // Only use form field if no filter in query string
                $type_filter = $this->request->getPostParam('type_filter');
                $parameters['type_filter'] = $type_filter !== null ? htmlspecialchars($type_filter) : '';
            }

            if (!empty($extractedFilters['content'])) {
                $parameters['content_filter'] = htmlspecialchars($extractedFilters['content']);
                // Enable records search if content filter is found in query
                $parameters['records'] = true;
            } else {
                // Only use form field if no filter in query string
                $content_filter = $this->request->getPostParam('content_filter');
                $parameters['content_filter'] = $content_filter !== null ? htmlspecialchars($content_filter) : '';
            }

            // If records search is disabled, clear the filters
            if (!$parameters['records']) {
                $parameters['type_filter'] = '';
                $parameters['content_filter'] = '';
            }

            $zones_page = (int)$this->request->getPostParam('zones_page', 1);

            $permission_view = Permission::getViewPermission($this->db);

            $dnsDataService = $this->createDnsDataService();

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

            $records_page = (int)$this->request->getPostParam('records_page', 1);

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
            $searchResultRecords = $this->shortenIPv6InRecords($searchResultRecords);
        }

        $editPermission = Permission::getEditPermission($this->db);
        $deletePermission = Permission::getDeletePermission($this->db);

        // Per-row eligibility must include group ownership; otherwise zones owned only
        // via a group lose their edit/delete buttons even when the user has the action.
        [$searchResultZones, $searchResultRecords] = $this->attachPermissionFlags(
            $searchResultZones,
            $searchResultRecords,
            $userId,
            $editPermission,
            $deletePermission
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
            $deletePermission
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
        string $deletePermission
    ): void {
        // Get all record types for the filter dropdown
        $recordTypeService = new RecordTypeService($this->getConfig());
        $recordTypes = $recordTypeService->getAllTypes($this->getRecordTypeCapabilities());

        $this->render('search.html', [
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
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
            'zone_rowamount' => $zone_rowamount,
            'record_rowamount' => $record_rowamount,
            'iface_zone_comments' => $iface_zone_comments,
            'iface_record_comments' => $iface_record_comments,
            'edit_permission' => $editPermission,
            'delete_permission' => $deletePermission,
            'user_id' => $_SESSION[SessionKeys::USERID],
            'whois_action_patterns' => $this->getModuleActionPatterns('whois_lookup'),
            'rdap_action_patterns' => $this->getModuleActionPatterns('rdap_lookup'),
            'record_types' => $recordTypes,
        ]);
    }

    private function getModuleActionPatterns(string $capability): array
    {
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $registry = new ModuleRegistry($this->config);
        $registry->loadModules();
        return $registry->getCapabilityData($capability, [], $isAdmin);
    }

    /**
     * Stamp `user_can_edit` / `user_can_delete` onto each search result so the
     * template can render per-row controls without re-running permission checks.
     *
     * Direct ownership and group ownership are both honored: a user with
     * `*_own` permission via a group whose zone is listed must still see the
     * edit/delete controls. Two upfront SQL queries (zone-group ownership +
     * direct owners) replace per-row hybrid lookups.
     *
     * @return array{0: array, 1: array} Augmented zones and records.
     */
    private function attachPermissionFlags(
        array $zones,
        array $records,
        int $userId,
        string $editPermission,
        string $deletePermission
    ): array {
        $userGroupRepo = new DbUserGroupRepository($this->db);
        $memberRepo = new DbUserGroupMemberRepository($this->db);
        $hybridPermissions = new HybridPermissionService(
            $this->db,
            $userGroupRepo,
            $memberRepo
        );

        // 'own' and 'own_as_client' may come from different sources (e.g. direct
        // template grants one, a group template the other). Union both so a zone
        // is editable whenever any source grants any *_edit_own permission.
        $editSources = ($editPermission === 'own' || $editPermission === 'own_as_client')
            ? $this->mergePermissionSources(
                $hybridPermissions->getPermissionSourcesForUser($userId, 'zone_content_edit_own'),
                $hybridPermissions->getPermissionSourcesForUser($userId, 'zone_content_edit_own_as_client')
            )
            : ['has_direct' => false, 'group_ids' => []];
        $deleteSources = $deletePermission === 'own'
            ? $hybridPermissions->getPermissionSourcesForUser($userId, 'zone_delete_own')
            : ['has_direct' => false, 'group_ids' => []];

        $zoneIds = array_values(array_unique(array_filter(array_merge(
            array_map(fn($z) => (int)($z['id'] ?? 0), $zones),
            array_map(fn($r) => (int)($r['domain_id'] ?? 0), $records)
        ))));
        $zoneGroupMap = $this->fetchZoneGroupOwnership($zoneIds);
        $zoneOwnerMap = $this->fetchDirectZoneOwners($zoneIds);

        foreach ($zones as &$zone) {
            $domainId = (int)($zone['id'] ?? 0);
            $zone['user_can_edit'] = $this->canActOnZone(
                $domainId,
                $userId,
                $editPermission,
                $editSources,
                $zoneOwnerMap,
                $zoneGroupMap
            );
            $zone['user_can_delete'] = $this->canActOnZone(
                $domainId,
                $userId,
                $deletePermission,
                $deleteSources,
                $zoneOwnerMap,
                $zoneGroupMap
            );
        }
        unset($zone);

        foreach ($records as &$record) {
            $domainId = (int)($record['domain_id'] ?? 0);
            $record['user_can_edit'] = $this->canActOnZone(
                $domainId,
                $userId,
                $editPermission,
                $editSources,
                $zoneOwnerMap,
                $zoneGroupMap
            );
        }
        unset($record);

        return [$zones, $records];
    }

    /**
     * @param array{has_direct: bool, group_ids: int[]} $a
     * @param array{has_direct: bool, group_ids: int[]} $b
     * @return array{has_direct: bool, group_ids: int[]}
     */
    private function mergePermissionSources(array $a, array $b): array
    {
        return [
            'has_direct' => $a['has_direct'] || $b['has_direct'],
            'group_ids' => array_values(array_unique(array_merge($a['group_ids'], $b['group_ids']))),
        ];
    }

    /**
     * @param int[] $zoneIds
     * @return array<int, int[]> Map of domain_id => list of group_ids that own it.
     */
    private function fetchZoneGroupOwnership(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT domain_id, group_id FROM zones_groups WHERE domain_id IN ($placeholders)"
        );
        $stmt->execute($zoneIds);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['domain_id']][] = (int)$row['group_id'];
        }
        return $map;
    }

    /**
     * @param int[] $zoneIds
     * @return array<int, int[]> Map of domain_id => list of direct owner user_ids.
     */
    private function fetchDirectZoneOwners(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($zoneIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT domain_id, owner FROM zones WHERE domain_id IN ($placeholders)"
        );
        $stmt->execute($zoneIds);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['domain_id']][] = (int)$row['owner'];
        }
        return $map;
    }

    /**
     * @param array{has_direct: bool, group_ids: int[]} $permissionSources
     * @param array<int, int[]> $zoneOwnerMap
     * @param array<int, int[]> $zoneGroupMap
     */
    private function canActOnZone(
        int $domainId,
        int $userId,
        string $permission,
        array $permissionSources,
        array $zoneOwnerMap,
        array $zoneGroupMap
    ): bool {
        if ($permission === 'all') {
            return true;
        }
        if ($permission !== 'own' && $permission !== 'own_as_client') {
            return false;
        }
        $isDirectOwner = in_array($userId, $zoneOwnerMap[$domainId] ?? [], true);
        if ($permissionSources['has_direct'] && $isDirectOwner) {
            return true;
        }
        $zoneGroupIds = $zoneGroupMap[$domainId] ?? [];
        return !empty(array_intersect($permissionSources['group_ids'], $zoneGroupIds));
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
            if (isset($record['name']) && str_ends_with($record['name'], '.ip6.arpa')) {
                $shortened = IpHelper::shortenIPv6ReverseZone($record['name']);
                if ($shortened !== null) {
                    $record['display_name'] = $shortened;
                }
            }
        }
        return $records;
    }

    /**
     * Parse query string for embedded filters like "type:txt" or "content:spf"
     *
     * @param string $query The search query to parse
     * @return array Array containing the cleaned query and extracted filters
     */
    private function parseQueryFilters(string $query): array
    {
        $filters = [
            'type' => '',
            'content' => '',
        ];

        // Match patterns like "type:txt" or "type: txt" or "type:TXT" (case insensitive)
        if (preg_match('/\btype:\s*([a-z0-9_]+)\b/i', $query, $matches)) {
            $filters['type'] = strtoupper($matches[1]); // Convert to uppercase for consistency
            $query = str_replace($matches[0], '', $query); // Remove from query
        }

        // Match patterns like "content:spf" or "content: value"
        if (preg_match('/\bcontent:\s*([^\s]+)\b/i', $query, $matches)) {
            $filters['content'] = $matches[1];
            $query = str_replace($matches[0], '', $query); // Remove from query
        }

        // Cleanup query (remove extra spaces)
        $query = trim(preg_replace('/\s+/', ' ', $query));

        return [$query, $filters];
    }
}
