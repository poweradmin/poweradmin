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
 * Script that displays zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\UserService;
use Poweradmin\Application\Service\ZoneService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListZonesController extends BaseController
{

    public function run(): void
    {
        $perm_view_zone_own = UserManager::verify_permission($this->db, 'zone_content_view_own');
        $perm_view_zone_others = UserManager::verify_permission($this->db, 'zone_content_view_others');

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        $this->listZones();
    }

    private function listZones(): void
    {
        $pdnssec_use = $this->config('pdnssec_use');
        $iface_zonelist_serial = $this->config('iface_zonelist_serial');
        $iface_zonelist_template = $this->config('iface_zonelist_template');
        $iface_rowamount = $this->config('iface_rowamount');

        $row_start = 0;
        if (isset($_GET['start'])) {
            $row_start = (htmlspecialchars($_GET['start']) - 1) * $iface_rowamount;
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        $count_zones_view = DnsRecord::zone_count_ng($this->db, $this->getConfig(), $perm_view);
        $count_zones_edit = DnsRecord::zone_count_ng($this->db, $this->getConfig(), $perm_edit);

        $letter_start = 'all';
        if ($count_zones_view > $iface_rowamount) {
            $letter_start = 'a';
            if (isset($_GET['letter'])) {
                $letter_start = htmlspecialchars($_GET['letter']);
                $_SESSION['letter'] = htmlspecialchars($_GET['letter']);
            } elseif (isset($_SESSION['letter'])) {
                $letter_start = $_SESSION['letter'];
            }
        }

        $count_zones_all_letterstart = DnsRecord::zone_count_ng($this->db, $this->getConfig(), $perm_view, $letter_start);

        list($zone_sort_by, $zone_sort_direction) = $this->getZoneSortOrder('zone_sort_by', ['name', 'type', 'count_records', 'owner']);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if ($count_zones_view <= $iface_rowamount || $letter_start == 'all') {
            $zones = $dnsRecord->get_zones($perm_view, $_SESSION['userid'], 'all', $row_start, $iface_rowamount, $zone_sort_by, $zone_sort_direction);
        } else {
            $zones = $dnsRecord->get_zones($perm_view, $_SESSION['userid'], $letter_start, $row_start, $iface_rowamount, $zone_sort_by, $zone_sort_direction);
        }

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        $this->render('list_zones.html', [
            'zones' => $zones,
            'count_zones_all_letterstart' => $count_zones_all_letterstart,
            'count_zones_view' => $count_zones_view,
            'count_zones_edit' => $count_zones_edit,
            'letter_start' => $letter_start,
            'iface_rowamount' => $iface_rowamount,
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
            'iface_zonelist_serial' => $iface_zonelist_serial,
            'iface_zonelist_template' => $iface_zonelist_template,
            'pdnssec_use' => $pdnssec_use,
            'letters' => $this->getAvailableStartingLetters($letter_start, $_SESSION['userid']),
            'pagination' => $this->createAndPresentPagination($count_zones_all_letterstart, $iface_rowamount),
            'session_userlogin' => $_SESSION['userlogin'],
            'perm_edit' => $perm_edit,
            'perm_zone_master_add' => UserManager::verify_permission($this->db, 'zone_master_add'),
            'perm_zone_slave_add' => UserManager::verify_permission($this->db, 'zone_slave_add'),
        ]);
    }

    private function getAvailableStartingLetters(string $letterStart, int $userId): string
    {
        $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $zoneService = new ZoneService($zoneRepository);

        $userRepository = new DbUserRepository($this->db);
        $userService = new UserService($userRepository);
        $allow_view_others = $userService->canUserViewOthersContent($userId);

        $availableChars = $zoneService->getAvailableStartingLetters($userId, $allow_view_others);
        $digitsAvailable = $zoneService->checkDigitsAvailable($availableChars);

        $presenter = new ZoneStartingLettersPresenter();
        return $presenter->present($availableChars, $digitsAvailable, $letterStart);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $presenter = new PaginationPresenter($pagination, 'index.php?page=list_zones&start={PageNumber}');

        return $presenter->present();
    }

    public function getZoneSortOrder(string $name, array $allowedValues): array
    {
        $zone_sort_by = 'name';
        $zone_sort_direction = 'ASC';

        if (isset($_GET[$name]) && preg_match("/^[a-z_]+$/", $_GET[$name])) {
            $zone_sort_by = htmlspecialchars($_GET[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_GET[$name]);
        } elseif (isset($_POST[$name]) && preg_match("/^[a-z_]+$/", $_POST[$name])) {
            $zone_sort_by = htmlspecialchars($_POST[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_POST[$name]);
        } elseif (isset($_SESSION['list_zone_sort_by'])) {
            $zone_sort_by = $_SESSION['list_zone_sort_by'];
        }

        if (!in_array($zone_sort_by, $allowedValues)) {
            $zone_sort_by = 'name';
        }

        if (isset($_GET[$name . '_direction']) && in_array(strtoupper($_GET[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_GET[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_GET[$name . '_direction']);
        } elseif (isset($_POST[$name . '_direction']) && in_array(strtoupper($_POST[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_POST[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_POST[$name . '_direction']);
        } elseif (isset($_SESSION['list_zone_sort_by_direction'])) {
            $zone_sort_direction = $_SESSION['list_zone_sort_by_direction'];
        }

        return [$zone_sort_by, $zone_sort_direction];
    }
}
