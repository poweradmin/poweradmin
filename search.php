<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Application\Query\ZoneSearch;
use Poweradmin\BaseController;
use Poweradmin\Permission;

require_once __DIR__ . '/vendor/autoload.php';

class SearchController extends BaseController
{
    public function run(): void
    {
        $this->checkPermission('search', _("You do not have the permission to perform searches."));

        $parameters = [
            'query' => '',
            'zones' => true,
            'records' => true,
            'wildcard' => true,
            'reverse' => true,
        ];

        $totalZones = 0;
        $searchResultZones = [];
        $zones_page = 1;
        $totalRecords = 0;
        $searchResultRecords = [];
        $records_page = 1;

        $zone_sort_by = $this->getFromRequestOrSession('zone_sort_by');
        $record_sort_by = $this->getFromRequestOrSession('record_sort_by');

        $_SESSION['zone_sort_by'] = $zone_sort_by;
        $_SESSION['record_sort_by'] = $record_sort_by;

        $iface_rowamount = $this->config('iface_rowamount');

        if ($this->isPost()) {
            $parameters['query'] = !empty($_POST['query']) ? htmlspecialchars($_POST['query']) : '';

            $parameters['zones'] = htmlspecialchars($_POST['zones']) ?? false;
            $parameters['records'] = htmlspecialchars($_POST['records']) ?? false;
            $parameters['wildcard'] = htmlspecialchars($_POST['wildcard']) ?? false;
            $parameters['reverse'] = htmlspecialchars($_POST['reverse']) ?? false;

            $zones_page = isset($_POST['zones_page']) ? (int)$_POST['zones_page'] : 1;

            $permission_view = Permission::getViewPermission($this->db);

            $db_type = $this->config('db_type');

            $zoneSearch = new ZoneSearch($this->db, $db_type);
            $searchResultZones = $zoneSearch->searchZones(
                $parameters,
                $permission_view,
                $zone_sort_by,
                $iface_rowamount,
                $zones_page
            );

            $totalZones = $zoneSearch->getTotalZones($parameters, $permission_view);

            $records_page = isset($_POST['records_page']) ? (int)$_POST['records_page'] : 1;

            $iface_search_group_records = $this->config('iface_search_group_records');
            $recordSearch = new RecordSearch($this->db, $db_type);
            $searchResultRecords = $recordSearch->searchRecords(
                $parameters,
                $permission_view,
                $record_sort_by,
                $iface_search_group_records,
                $iface_rowamount,
                $records_page,
            );

            $totalRecords = $recordSearch->getTotalRecords($parameters, $permission_view, $iface_search_group_records);
        }

        $this->showSearchForm($parameters, $searchResultZones, $searchResultRecords, $zone_sort_by, $record_sort_by, $totalZones, $totalRecords, $zones_page, $records_page, $iface_rowamount);
    }

    private function showSearchForm($parameters, $searchResultZones, $searchResultRecords, $zone_sort_by, $record_sort_by, $totalZones, $totalRecords, $zones_page, $records_page, $iface_rowamount): void
    {
        $this->render('search.html', [
            'zone_sort_by' => $zone_sort_by,
            'record_sort_by' => $record_sort_by,
            'query' => $parameters['query'],
            'search_by_zones' => $parameters['zones'],
            'search_by_records' => $parameters['records'],
            'search_by_wildcard' => $parameters['wildcard'],
            'search_by_reverse' => $parameters['reverse'],
            'has_zones' => !empty($searchResultZones),
            'has_records' => !empty($searchResultRecords),
            'found_zones' => $searchResultZones,
            'found_records' => $searchResultRecords,
            'total_zones' => $totalZones,
            'total_records' => $totalRecords,
            'zones_page' => $zones_page,
            'records_page' => $records_page,
            'iface_rowamount' => $iface_rowamount,
            'edit_permission' => Permission::getEditPermission($this->db),
            'user_id' => $_SESSION['userid'],
        ]);
    }

    private function getFromRequestOrSession($name)
    {
        if (isset($_POST[$name])) {
            return $_POST[$name];
        }
        if (isset($_SESSION[$name])) {
            return $_SESSION[$name];
        }
        return 'name';
    }
}

$controller = new SearchController();
$controller->run();
