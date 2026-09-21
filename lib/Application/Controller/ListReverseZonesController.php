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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\OwnerGroupColumnPresenter;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Enum\AccessScope;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Zone\ForwardZoneAssociationService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Renders the reverse zone list with pagination and sorting.
 */
class ListReverseZonesController extends BaseController
{
    private DnsDataService $dnsDataService;
    private ForwardZoneAssociationService $forwardZoneAssociationService;
    private UserContextService $userContextService;
    private ZoneSortingService $zoneSortingService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        // Initialize repository and services
        $zoneRepository = $this->services()->zoneRepository();
        $this->dnsDataService = $this->services()->dnsDataService();
        $this->forwardZoneAssociationService = new ForwardZoneAssociationService($zoneRepository);
        $this->userContextService = new UserContextService();
        $this->zoneSortingService = $this->createZoneSortingService();
    }

    public function run(): void
    {
        $perm_view_zone_own = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OWN);
        $perm_view_zone_others = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_reverse_zones');
        $this->setPageTitle(_('Reverse Zones'));

        $this->listReverseZones();
    }

    private function listReverseZones(): void
    {
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $iface_zonelist_fullname = $this->config->get('interface', 'display_fullname_in_zone_list', false);

        // Get user preferences for zone list display
        $userPreferenceService = $this->services()->userPreferenceService();
        $userId = $this->getCurrentUserId();
        $iface_zonelist_serial = $userPreferenceService->getShowZoneSerial($userId);
        $isApiBackend = DnsBackendProviderFactory::isApiBackend($this->getConfig());
        // Signed serial data comes from the PowerDNS API zone list, so SQL backend cannot provide it
        $iface_zonelist_signed_serial = $isApiBackend
            && $this->config->get('interface', 'display_signed_serial_in_zone_list', false);
        $iface_zonelist_template = $userPreferenceService->getShowZoneTemplate($userId);
        $iface_zonelist_record_count = $userPreferenceService->getShowZoneRecordCount($userId);

        $iface_rowamount = $this->resolveRowsPerPage();

        $row_start = 0;
        $start_param = $this->httpRequest->getQueryParam('start');
        if ($start_param !== null) {
            $start = (int)$start_param;
            $row_start = max(0, ($start - 1) * $iface_rowamount);
        }

        $permissionService = $this->services()->permissionService();
        $perm_view = $permissionService->getViewPermissionLevel((int)$userId);
        $perm_edit = $permissionService->getEditPermissionLevel((int)$userId);
        $perm_delete = $permissionService->getDeletePermissionLevel((int)$userId);
        $can_bulk_delete_zones = AccessScope::fromString($perm_delete)->grantsAnything();
        $count_zones_view = $this->dnsDataService->countZones($perm_view, 'all', 'reverse');
        $count_zones_edit = $this->dnsDataService->countZones($perm_edit, 'all', 'reverse');
        $count_zones_delete = $this->dnsDataService->countZones($perm_delete, 'all', 'reverse');

        $ownershipMode = new ZoneOwnershipModeService($this->getConfig());
        $perm_ownership_view = $permissionService->getZoneOwnershipViewPermissionLevel((int)$userId);
        // The full-name column also lists zone owners, so it follows the same gate
        $iface_zonelist_fullname = $iface_zonelist_fullname && $perm_ownership_view !== 'none';
        $showOwnerColumn = $ownershipMode->isUserOwnerAllowed()
            && $this->config->get('interface', 'display_owner_in_zone_list', true)
            && $perm_ownership_view !== 'none';
        // Group sort relies on JOINs against Poweradmin tables, which the API-backed repository can't perform
        $showGroupColumn = $ownershipMode->isGroupOwnerAllowed()
            && $this->config->get('interface', 'display_group_in_zone_list', true)
            && $perm_ownership_view !== 'none';
        // Sorting by owner/group data the user cannot fully see would leak
        // ownership through row order, so it needs "all" scope, or "own" scope
        // with a list that already contains only owned zones.
        $ownershipSortAllowed = $perm_ownership_view === 'all'
            || ($perm_ownership_view === 'own' && $perm_view === 'own');
        $isOwnerSortSupported = $showOwnerColumn && $ownershipSortAllowed;
        $isGroupSortSupported = $showGroupColumn && !$isApiBackend && $ownershipSortAllowed;

        // In API mode record counts are resolved per page, so sorting on them
        // would only order the rows already on screen
        $isRecordCountSortSupported = $iface_zonelist_record_count && !$isApiBackend;

        $allowedSort = ['name', 'type'];
        if ($isRecordCountSortSupported) {
            $allowedSort[] = 'count_records';
        }
        if ($isOwnerSortSupported) {
            $allowedSort[] = 'owner';
        }
        if ($isGroupSortSupported) {
            $allowedSort[] = 'group';
        }

        list($zone_sort_by, $zone_sort_direction) = $this->zoneSortingService->getZoneSortOrder(
            $allowedSort,
            submittedSortBy: $this->httpRequest->getPostParam('zone_sort_by') ?? $this->httpRequest->getQueryParam('zone_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('zone_sort_by_direction') ?? $this->httpRequest->getQueryParam('zone_sort_by_direction')
        );

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        // Get the reverse zone filter type from the request
        $reverse_zone_type = $this->zoneSortingService->getReverseZoneTypeFilter($this->httpRequest->getQueryParam('reverse_type'));
        $loggedInUserId = $this->userContextService->getLoggedInUserId();

        // Get all counts in a single call
        $zoneCounts = $this->dnsDataService->getReverseZoneCounts($perm_view, $loggedInUserId);
        $count_all_reverse_zones = $zoneCounts['count_all'];
        $count_ipv4_zones = $zoneCounts['count_ipv4'];
        $count_ipv6_zones = $zoneCounts['count_ipv6'];

        // Get the actual zones for the current page
        $reverse_zones = $this->dnsDataService->getReverseZones(
            $perm_view,
            $loggedInUserId,
            $reverse_zone_type,
            $row_start,
            $iface_rowamount,
            $zone_sort_by,
            $zone_sort_direction,
            $iface_zonelist_serial,
            $iface_zonelist_template,
            $iface_zonelist_record_count
        );

        // Apply client-side sorting when sorting by name for additional flexibility
        if ($zone_sort_by === 'name' && !empty($reverse_zones)) {
            $sort_type = $this->config->get('interface', 'reverse_zone_sort', 'natural');
            $reverse_zones = $this->zoneSortingService->applySortingToZones($reverse_zones, $zone_sort_by, $sort_type);
        }

        // Get associated forward zones only if enabled (configurable for performance)
        $showForwardZoneAssociations = $this->config->get('interface', 'show_forward_zone_associations', true);
        $associatedForwardZones = $showForwardZoneAssociations
            ? $this->forwardZoneAssociationService->getAssociatedForwardZones($reverse_zones)
            : [];

        // Calculate pagination count based on current filter (using pre-computed counts)
        $pagination_count = match ($reverse_zone_type) {
            'ipv4' => $count_ipv4_zones,
            'ipv6' => $count_ipv6_zones,
            default => $count_all_reverse_zones,
        };

        // Ownership is resolved once for the page: the per-row delete control must
        // mirror the check the delete endpoint runs (ownership direct or via any group).
        $ownership = $this->services()->zoneListPermissionService()->index($loggedInUserId, array_column($reverse_zones, 'id'));
        $groupNames = $ownership->hasGroupOwners() ? OwnerGroupColumnPresenter::namesById($this->services()->userGroupRepository()->findAll()) : [];

        foreach ($reverse_zones as &$zone) {
            // Shorten IPv6 reverse zones for display
            if (isset($zone['utf8_name'])) {
                $zone['utf8_name'] = IpHelper::displayZoneName($zone['utf8_name']);
            }

            $zoneId = (int)$zone['id'];
            $zone['groups'] = array_map(fn(int $groupId): string => $groupNames[$groupId] ?? 'Group #' . $groupId, $ownership->groupIds($zoneId));
            $zone['user_can_delete'] = $ownership->allows($perm_delete, $zoneId);

            // At the "own" ownership view level, owner and group cells stay
            // visible only for zones the user owns directly or via a group.
            if ($perm_ownership_view === 'own' && !$ownership->owns($zoneId)) {
                $zone['owners'] = [];
                $zone['full_names'] = [];
                $zone['groups'] = [];
            }

            $zone['owners_display'] = OwnerGroupColumnPresenter::presentOwners($zone['owners'] ?? [], $zone['full_names'] ?? []);
            $zone['groups_display'] = OwnerGroupColumnPresenter::presentGroups($zone['groups']);
        }
        unset($zone); // Break the reference

        $this->render('list_reverse_zones.html', [
            'zones' => $reverse_zones,
            'pending_change_requests_by_zone' => $this->changeApproval()->pendingByZone($this->getCurrentUserId(), array_map('intval', array_column($reverse_zones, 'id'))),
            'count_zones_view' => $count_zones_view,
            'count_zones_edit' => $count_zones_edit,
            'count_zones_delete' => $count_zones_delete,
            'iface_rowamount' => $iface_rowamount,
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
            'iface_zonelist_serial' => $iface_zonelist_serial,
            'iface_zonelist_signed_serial' => $iface_zonelist_signed_serial,
            'iface_zonelist_template' => $iface_zonelist_template,
            'iface_zonelist_record_count' => $iface_zonelist_record_count,
            'is_record_count_sort_supported' => $isRecordCountSortSupported,
            'iface_zonelist_fullname' => $iface_zonelist_fullname,
            'show_owner_column' => $showOwnerColumn,
            'show_group_column' => $showGroupColumn,
            // Kept for 4.4.0 theme forks; mirror the gated flags so ownership view still applies
            'is_user_owner_allowed' => $showOwnerColumn,
            'is_group_owner_allowed' => $showGroupColumn,
            'is_owner_sort_supported' => $isOwnerSortSupported,
            'is_group_sort_supported' => $isGroupSortSupported,
            'is_api_backend' => $isApiBackend,
            'pdnssec_use' => $pdnssec_use,
            'pagination' => $this->presentPagination($pagination_count, $iface_rowamount, '/zones/reverse?start={PageNumber}', [
                'reverse_type' => $this->httpRequest->getQueryParam('reverse_type'),
            ]),
            'session_userlogin' => $this->userContextService->getLoggedInUsername(),
            'perm_edit' => $perm_edit,
            'perm_delete' => $perm_delete,
            'can_bulk_delete_zones' => $can_bulk_delete_zones,
            'perm_zone_master_add' => $this->hasPermission(Permission::PERM_ZONE_MASTER_ADD),
            'perm_zone_slave_add' => $this->hasPermission(Permission::PERM_ZONE_SLAVE_ADD),
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'reverse_zone_type' => $reverse_zone_type,
            'count_ipv4_zones' => $count_ipv4_zones,
            'count_ipv6_zones' => $count_ipv6_zones,
            'count_all_reverse_zones' => $count_all_reverse_zones,
            'associated_forward_zones' => $associatedForwardZones,
            'show_forward_zone_associations' => $showForwardZoneAssociations,
        ]);
    }
}
