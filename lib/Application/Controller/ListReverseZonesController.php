<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
 * Script that displays reverse zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\ZoneService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ForwardZoneAssociationService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Domain\Service\ZoneSortingService;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;

class ListReverseZonesController extends BaseController
{
    private ZoneService $zoneService;
    private ForwardZoneAssociationService $forwardZoneAssociationService;
    private UserContextService $userContextService;
    private ZoneSortingService $zoneSortingService;

    public function run(): void
    {
        $perm_view_zone_own = UserManager::verifyPermission($this->db, 'zone_content_view_own');
        $perm_view_zone_others = UserManager::verifyPermission($this->db, 'zone_content_view_others');

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        // Initialize repository and services
        $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->zoneService = new ZoneService($zoneRepository);
        $this->forwardZoneAssociationService = new ForwardZoneAssociationService($zoneRepository);
        $this->userContextService = new UserContextService();
        $this->zoneSortingService = new ZoneSortingService();

        $this->listReverseZones();
    }

    private function listReverseZones(): void
    {
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $iface_zonelist_serial = $this->config->get('interface', 'display_serial_in_zone_list', false);
        $iface_zonelist_template = $this->config->get('interface', 'display_template_in_zone_list', false);
        // Get default rows per page from config
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);

        // Create pagination service and get user preference
        $paginationService = new PaginationService();
        $iface_rowamount = $paginationService->getUserRowsPerPage($default_rowamount);

        $row_start = 0;
        if (isset($_GET['start'])) {
            $start = (int)htmlspecialchars($_GET['start']);
            $row_start = ($start - 1) * $iface_rowamount;
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        // Set count_zones_edit to at least 1 to ensure checkboxes are displayed
        // This is needed because in list_reverse_zones.html the checkboxes are conditionally displayed
        // based on count_zones_edit > 0
        $zoneCountService = new ZoneCountService($this->db, $this->getConfig());
        $count_zones_view = $zoneCountService->countZones($perm_view);
        $count_zones_edit = max(1, $zoneCountService->countZones($perm_edit));

        list($zone_sort_by, $zone_sort_direction) = $this->zoneSortingService->getZoneSortOrder('zone_sort_by', ['name', 'type', 'count_records', 'owner']);

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        // Get the reverse zone filter type from the request
        $reverse_zone_type = $this->zoneSortingService->getReverseZoneTypeFilter();

        // Always get the total count of ALL reverse zones (regardless of filter)
        $count_all_reverse_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $this->userContextService->getLoggedInUserId(),
            'all',  // Always count all reverse zones for the total
            $zone_sort_by,
            $zone_sort_direction
        );

        // Get the actual zones for the current page with efficient DB filtering
        $reverse_zones = $this->zoneService->getReverseZones(
            $perm_view,
            $this->userContextService->getLoggedInUserId(),
            $reverse_zone_type,
            $row_start,
            $iface_rowamount,
            $zone_sort_by,
            $zone_sort_direction
        );

        // Apply client-side sorting when sorting by name for additional flexibility
        if ($zone_sort_by === 'name' && !empty($reverse_zones)) {
            $sort_type = $this->config->get('interface', 'reverse_zone_sort', 'natural');
            $reverse_zones = $this->zoneSortingService->applySortingToZones($reverse_zones, $zone_sort_by, $sort_type);
        }

        // Get counts for each type
        $count_ipv4_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $this->userContextService->getLoggedInUserId(),
            'ipv4',
            $zone_sort_by,
            $zone_sort_direction
        );

        $count_ipv6_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $this->userContextService->getLoggedInUserId(),
            'ipv6',
            $zone_sort_by,
            $zone_sort_direction
        );

        // Get associated forward zones (if needed)
        $associatedForwardZones = $this->forwardZoneAssociationService->getAssociatedForwardZones($reverse_zones);

        $this->render('list_reverse_zones.html', [
            'zones' => $reverse_zones,
            'count_zones_view' => $count_zones_view,
            'count_zones_edit' => $count_zones_edit,
            'iface_rowamount' => $iface_rowamount,
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
            'iface_zonelist_serial' => $iface_zonelist_serial,
            'iface_zonelist_template' => $iface_zonelist_template,
            'pdnssec_use' => $pdnssec_use,
            'pagination' => $this->createAndPresentPagination($count_all_reverse_zones, $iface_rowamount),
            'session_userlogin' => $this->userContextService->getLoggedInUsername(),
            'perm_edit' => $perm_edit,
            'perm_zone_master_add' => UserManager::verifyPermission($this->db, 'zone_master_add'),
            'perm_zone_slave_add' => UserManager::verifyPermission($this->db, 'zone_slave_add'),
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
            'whois_enabled' => $this->config->get('whois', 'enabled', false),
            'rdap_enabled' => $this->config->get('rdap', 'enabled', false),
            'reverse_zone_type' => $reverse_zone_type,
            'count_ipv4_zones' => $count_ipv4_zones,
            'count_ipv6_zones' => $count_ipv6_zones,
            'count_all_reverse_zones' => $count_all_reverse_zones,
            'associated_forward_zones' => $associatedForwardZones,
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);

        $paginationUrl = 'index.php?page=list_reverse_zones&start={PageNumber}';

        // Add reverse_type parameter if it exists
        if (isset($_GET['reverse_type'])) {
            $paginationUrl .= '&reverse_type=' . htmlspecialchars($_GET['reverse_type']);
        }

        // Add rows_per_page parameter if it exists
        if (isset($_GET['rows_per_page'])) {
            $paginationUrl .= '&rows_per_page=' . htmlspecialchars($_GET['rows_per_page']);
        }

        $presenter = new PaginationPresenter($pagination, $paginationUrl);

        return $presenter->present();
    }
}
