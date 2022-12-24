<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Permission;

require_once 'inc/toolkit.inc.php';

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
        $searchResult = ['zones' => null, 'records' => null];

        $zone_sort_by = $this->getFromRequestOrSession('zone_sort_by');
        $record_sort_by = $this->getFromRequestOrSession('record_sort_by');

        $_SESSION['zone_sort_by'] = $zone_sort_by;
        $_SESSION['record_sort_by'] = $record_sort_by;

        if ($this->isPost()) {
            $parameters['query'] = !empty($_POST['query']) ? htmlspecialchars($_POST['query']) : '';

            $parameters['zones'] = $_POST['zones'] ?? false;
            $parameters['records'] = $_POST['records'] ?? false;
            $parameters['wildcard'] = $_POST['wildcard'] ?? false;
            $parameters['reverse'] = $_POST['reverse'] ?? false;

            $searchResult = DnsRecord::search_zone_and_record(
                $parameters,
                Permission::getViewPermission(),
                $zone_sort_by,
                $record_sort_by
            );
        }

        $this->showSearchForm($parameters, $searchResult, $zone_sort_by, $record_sort_by);
    }

    private function showSearchForm($parameters, $searchResult, $zone_sort_by, $record_sort_by)
    {
        $this->render('search.html', [
            'zone_sort_by' => $zone_sort_by,
            'record_sort_by' => $record_sort_by,
            'query' => $parameters['query'],
            'search_by_zones' => $parameters['zones'],
            'search_by_records' => $parameters['records'],
            'search_by_wildcard' => $parameters['wildcard'],
            'search_by_reverse' => $parameters['reverse'],
            'zones_found' => is_array($searchResult['zones']),
            'records_found' => is_array($searchResult['records']),
            'search_result' => $searchResult,
            'edit_permission' => Permission::getEditPermission(),
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
