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
use Poweradmin\Application\Presenter\OwnerGroupColumnPresenter;
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Domain\Enum\AccessScope;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Renders the forward zone list with pagination, letter filter and sorting; also handles the API sync action.
 */
class ListForwardZonesController extends BaseController
{
    private ZoneSortingService $zoneSortingService;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);
        $this->zoneSortingService = $this->createZoneSortingService();
    }

    public function run(): void
    {
        $perm_view_zone_own = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OWN);
        $perm_view_zone_others = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_forward_zones');
        $this->setPageTitle(_('Forward Zones'));

        if ($this->isPost() && $this->getSafeRequestValue('action') === 'sync') {
            $this->forceSyncFromApi();
            return;
        }

        $this->listForwardZones();
    }

    private function forceSyncFromApi(): void
    {
        if (!$this->backendCapabilities()->syncsZoneListFromServer()) {
            $this->redirect('/zones/forward');
            return;
        }

        if (!$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            $this->setMessage('list_forward_zones', 'error', _('You do not have permission to sync zones from PowerDNS.'));
            $this->redirect('/zones/forward');
            return;
        }

        $backendProvider = $this->services()->dnsBackendProvider();
        $syncService = new ZoneSyncService($this->db, $backendProvider, 300, $this->logger);

        try {
            $result = $syncService->sync();
            $this->setMessage('list_forward_zones', 'success', sprintf(
                _('Zones synced from PowerDNS: %d added, %d updated, %d removed.'),
                $result['added'],
                $result['updated'],
                $result['removed']
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Forced zone sync failed: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('list_forward_zones', 'error', sprintf(
                _('Zone sync failed: %s'),
                $e->getMessage()
            ));
        }

        $this->redirect('/zones/forward');
    }

    private function listForwardZones(): void
    {
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $iface_zonelist_fullname = $this->config->get('interface', 'display_fullname_in_zone_list', false);

        // Get user preferences for zone list display
        $userPreferenceService = $this->services()->userPreferenceService();
        $userId = $this->getCurrentUserId();
        $iface_zonelist_serial = $userPreferenceService->getShowZoneSerial($userId);
        $backend = $this->backendCapabilities();
        $isApiBackend = $backend->isApiBackend();
        $iface_zonelist_signed_serial = $backend->providesSignedSerial()
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
        $dnsDataService = $this->services()->dnsDataService();

        $count_zones_view = $dnsDataService->countZones($perm_view);
        $count_zones_edit = $dnsDataService->countZones($perm_edit);
        $count_zones_delete = $dnsDataService->countZones($perm_delete);

        $letter_start = 'all';
        if ($count_zones_view > $iface_rowamount) {
            $letter_start = 'a';
            $letter = $this->httpRequest->getQueryParam('letter');
            if ($letter !== null) {
                $letter_start = $letter;
                $_SESSION[SessionKeys::LETTER] = $letter;
            } elseif (isset($_SESSION[SessionKeys::LETTER])) {
                $letter_start = $_SESSION[SessionKeys::LETTER];
            }
        }

        $count_zones_all_letterstart = $dnsDataService->countZones($perm_view, $letter_start);

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
        $isGroupSortSupported = $showGroupColumn && $backend->supportsGroupSort() && $ownershipSortAllowed;
        $isRecordCountSortSupported = $iface_zonelist_record_count && $backend->supportsRecordCountSort();

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

        $effectiveLetterStart = ($count_zones_view <= $iface_rowamount || $letter_start == 'all') ? 'all' : $letter_start;
        $zones = $dnsDataService->getForwardZones(
            $perm_view,
            (int)$this->getCurrentUserId(),
            $effectiveLetterStart,
            $row_start,
            $iface_rowamount,
            $zone_sort_by,
            $zone_sort_direction,
            $iface_zonelist_serial,
            $iface_zonelist_template,
            $iface_zonelist_record_count
        );

        // Ownership is resolved once for the page: the per-row delete control must
        // mirror the check the delete endpoint runs (ownership direct or via any group).
        $ownership = $this->services()->zoneListPermissionService()->index($userId, array_column($zones, 'id'));
        $groupNames = $ownership->hasGroupOwners() ? OwnerGroupColumnPresenter::namesById($this->services()->userGroupRepository()->findAll()) : [];

        foreach ($zones as &$zone) {
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

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        $this->render('list_forward_zones.html', [
            'zones' => $zones,
            'pending_change_requests_by_zone' => $this->changeApproval()->pendingByZone($this->getCurrentUserId(), array_map('intval', array_column($zones, 'id'))),
            'count_zones_all_letterstart' => $count_zones_all_letterstart,
            'count_zones_view' => $count_zones_view,
            'count_zones_edit' => $count_zones_edit,
            'count_zones_delete' => $count_zones_delete,
            'letter_start' => $letter_start,
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
            'pdnssec_use' => $pdnssec_use,
            ...$this->getAvailableStartingLetters($letter_start, (int)$this->getCurrentUserId(), $dnsDataService),
            ...$this->paginationVariables($count_zones_all_letterstart, $iface_rowamount, '/zones/forward?start={PageNumber}'),
            'session_userlogin' => $this->getUserContextService()->getLoggedInUsername(),
            'perm_edit' => $perm_edit,
            'perm_delete' => $perm_delete,
            'can_bulk_delete_zones' => $can_bulk_delete_zones,
            'perm_zone_master_add' => $this->hasPermission(Permission::PERM_ZONE_MASTER_ADD),
            'perm_zone_slave_add' => $this->hasPermission(Permission::PERM_ZONE_SLAVE_ADD),
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'is_api_backend' => $isApiBackend,
        ]);
    }

    /**
     * @return array{letters: string, letters_items: list<array<string, mixed>>}
     */
    private function getAvailableStartingLetters(string $letterStart, int $userId, DnsDataService $dnsDataService): array
    {
        $allow_view_others = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);
        $availableChars = $dnsDataService->getDistinctStartingLetters($userId, $allow_view_others);

        $digitsAvailable = (bool)array_filter($availableChars, 'is_numeric');

        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $presenter = new ZoneStartingLettersPresenter();
        $rowsPerPage = $this->httpRequest->getRowsPerPage();

        return [
            'letters' => $presenter->present($availableChars, $digitsAvailable, $letterStart, $baseUrlPrefix, $rowsPerPage),
            'letters_items' => $presenter->items($availableChars, $digitsAvailable, $letterStart, $baseUrlPrefix, $rowsPerPage),
        ];
    }
}
