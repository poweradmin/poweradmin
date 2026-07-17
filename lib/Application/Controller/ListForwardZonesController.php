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
 * Script that displays forward zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\UserService;
use Poweradmin\Application\Service\ZoneService;
use Poweradmin\Application\Service\ZoneSyncService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneSortingService;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Poweradmin\Domain\Service\SessionKeys;

class ListForwardZonesController extends BaseController
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
        $perm_view_zone_own = $this->hasPermission('zone_content_view_own');
        $perm_view_zone_others = $this->hasPermission('zone_content_view_others');

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_forward_zones');
        $this->setPageTitle(_('Forward Zones'));

        if ($this->isPost() && $this->getSafeRequestValue('action') === 'sync') {
            $this->validateCsrfToken();
            $this->forceSyncFromApi();
            return;
        }

        $this->listForwardZones();
    }

    private function forceSyncFromApi(): void
    {
        if (!DnsBackendProviderFactory::isApiBackend($this->getConfig())) {
            $this->redirect('/zones/forward');
            return;
        }

        if (!$this->hasPermission('user_is_ueberuser')) {
            $this->setMessage('list_forward_zones', 'error', _('You do not have permission to sync zones from PowerDNS.'));
            $this->redirect('/zones/forward');
            return;
        }

        $backendProvider = DnsBackendProviderFactory::create($this->db, $this->getConfig(), $this->logger);
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
        $userPreferenceService = $this->createUserPreferenceService();
        $userId = $this->getCurrentUserId();
        $iface_zonelist_serial = $userPreferenceService->getShowZoneSerial($userId);
        $isApiBackend = DnsBackendProviderFactory::isApiBackend($this->getConfig());
        // Signed serial data comes from the PowerDNS API zone list, so SQL backend cannot provide it
        $iface_zonelist_signed_serial = $isApiBackend
            && $this->config->get('interface', 'display_signed_serial_in_zone_list', false);
        $iface_zonelist_template = $userPreferenceService->getShowZoneTemplate($userId);
        $iface_zonelist_record_count = $userPreferenceService->getShowZoneRecordCount($userId);

        // Create pagination service and get user preference
        $paginationService = $this->createPaginationService();
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);
        $iface_rowamount = $paginationService->getUserRowsPerPage($default_rowamount, $userId);

        $row_start = 0;
        $start_param = $this->request->getQueryParam('start');
        if ($start_param !== null) {
            $start = (int)htmlspecialchars($start_param);
            $row_start = max(0, ($start - 1) * $iface_rowamount);
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);
        $perm_delete = Permission::getDeletePermission($this->db);
        $dnsDataService = $this->createDnsDataService();

        $count_zones_view = $dnsDataService->countZones($perm_view);
        $count_zones_edit = $dnsDataService->countZones($perm_edit);
        $count_zones_delete = $dnsDataService->countZones($perm_delete);

        $letter_start = 'all';
        if ($count_zones_view > $iface_rowamount) {
            $letter_start = 'a';
            $letter = $this->request->getQueryParam('letter');
            if ($letter !== null) {
                $letter_start = htmlspecialchars($letter);
                $_SESSION[SessionKeys::LETTER] = htmlspecialchars($letter);
            } elseif (isset($_SESSION[SessionKeys::LETTER])) {
                $letter_start = $_SESSION[SessionKeys::LETTER];
            }
        }

        $count_zones_all_letterstart = $dnsDataService->countZones($perm_view, $letter_start);

        $ownershipMode = new ZoneOwnershipModeService($this->getConfig());
        $perm_ownership_view = Permission::getZoneOwnershipViewPermission($this->db);
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

        $allowedSort = ['name', 'type'];
        if ($iface_zonelist_record_count) {
            $allowedSort[] = 'count_records';
        }
        if ($isOwnerSortSupported) {
            $allowedSort[] = 'owner';
        }
        if ($isGroupSortSupported) {
            $allowedSort[] = 'group';
        }

        list($zone_sort_by, $zone_sort_direction) = $this->zoneSortingService->getZoneSortOrder('zone_sort_by', $allowedSort);

        $effectiveLetterStart = ($count_zones_view <= $iface_rowamount || $letter_start == 'all') ? 'all' : $letter_start;
        $zones = $dnsDataService->getForwardZones(
            $perm_view,
            $_SESSION[SessionKeys::USERID],
            $effectiveLetterStart,
            $row_start,
            $iface_rowamount,
            $zone_sort_by,
            $zone_sort_direction,
            $iface_zonelist_serial,
            $iface_zonelist_template,
            $iface_zonelist_record_count
        );

        // Augment zones with group information
        $zoneGroupRepo = $this->createZoneGroupRepository();
        $userGroupRepo = $this->createUserGroupRepository();
        $memberRepo = $this->createUserGroupMemberRepository();
        $allGroups = $userGroupRepo->findAll();

        // Resolve where the user can delete (direct vs. which groups grant it). Two
        // queries up front lets the per-row decision below stay in PHP, instead of
        // running canUserPerformZoneAction once per rendered zone.
        $hybridPermissions = new HybridPermissionService($this->db, $userGroupRepo, $memberRepo);
        $deleteSources = $perm_delete === 'own'
            ? $hybridPermissions->getPermissionSourcesForUser($userId, 'zone_delete_own')
            : ['has_direct' => false, 'group_ids' => []];
        $username = $_SESSION[SessionKeys::USERLOGIN];

        $userGroupIds = $perm_ownership_view === 'own'
            ? $userGroupRepo->getGroupIdsForUser($userId)
            : [];

        foreach ($zones as &$zone) {
            $groupOwnerships = $zoneGroupRepo->findByDomainId($zone['id']);
            $zoneGroupIds = array_map(fn($zg) => $zg->getGroupId(), $groupOwnerships);
            $zone['groups'] = array_map(function ($zg) use ($allGroups) {
                $groupId = $zg->getGroupId();
                foreach ($allGroups as $group) {
                    if ($group->getId() === $groupId) {
                        return $group->getName();
                    }
                }
                return 'Group #' . $groupId;
            }, $groupOwnerships);

            // Delete eligibility for the per-row delete control: must mirror the
            // hybrid check the delete endpoint runs so the button only appears when
            // the action will actually be permitted.
            if ($perm_delete === 'all') {
                $zone['user_can_delete'] = true;
            } elseif ($perm_delete === 'own') {
                $directGrants = $deleteSources['has_direct']
                    && in_array($username, $zone['users'] ?? [], true);
                $groupGrants = !empty(array_intersect($deleteSources['group_ids'], $zoneGroupIds));
                $zone['user_can_delete'] = $directGrants || $groupGrants;
            } else {
                $zone['user_can_delete'] = false;
            }

            // At the "own" ownership view level, owner and group cells stay
            // visible only for zones the user owns directly or via a group.
            if ($perm_ownership_view === 'own') {
                $ownsDirect = in_array($username, $zone['users'] ?? [], true);
                $ownsViaGroup = !empty(array_intersect($userGroupIds, $zoneGroupIds));
                if (!$ownsDirect && !$ownsViaGroup) {
                    $zone['owners'] = [];
                    $zone['full_names'] = [];
                    $zone['groups'] = [];
                }
            }
        }
        unset($zone); // Break the reference

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        $this->render('list_forward_zones.html', [
            'zones' => $zones,
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
            'iface_zonelist_fullname' => $iface_zonelist_fullname,
            'show_owner_column' => $showOwnerColumn,
            'show_group_column' => $showGroupColumn,
            'is_owner_sort_supported' => $isOwnerSortSupported,
            'is_group_sort_supported' => $isGroupSortSupported,
            'pdnssec_use' => $pdnssec_use,
            'letters' => $this->getAvailableStartingLetters($letter_start, $_SESSION[SessionKeys::USERID]),
            'pagination' => $this->createAndPresentPagination($count_zones_all_letterstart, $iface_rowamount),
            'session_userlogin' => $_SESSION[SessionKeys::USERLOGIN],
            'perm_edit' => $perm_edit,
            'perm_delete' => $perm_delete,
            'perm_zone_master_add' => $this->hasPermission('zone_master_add'),
            'perm_zone_slave_add' => $this->hasPermission('zone_slave_add'),
            'perm_is_godlike' => $this->hasPermission('user_is_ueberuser'),
            'is_api_backend' => $isApiBackend,
        ]);
    }

    private function getAvailableStartingLetters(string $letterStart, int $userId): string
    {
        $userRepository = $this->createUserRepository();
        $userService = new UserService($userRepository);
        $allow_view_others = $userService->canUserViewOthersContent($userId);

        $dnsDataService = $this->createDnsDataService();
        $availableChars = $dnsDataService->getDistinctStartingLetters($userId, $allow_view_others);

        $zoneRepository = $this->createZoneRepository();
        $zoneService = new ZoneService($zoneRepository);
        $digitsAvailable = $zoneService->checkDigitsAvailable($availableChars);

        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $presenter = new ZoneStartingLettersPresenter();
        return $presenter->present($availableChars, $digitsAvailable, $letterStart, $baseUrlPrefix);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $presenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/zones/forward?start={PageNumber}');

        return $presenter->present();
    }
}
