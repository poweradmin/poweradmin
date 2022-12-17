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
 * Script that displays list of event logs
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\DbZoneLogger;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';

class ListLogZonesController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', 'You do not have the permission to see any logs');

        $this->showListLogZones();
    }

    private function showListLogZones()
    {
        $selected_page = 1;
        if (isset($_GET['start'])) {
            is_numeric($_GET['start']) ? $selected_page = $_GET['start'] : die("Unknown page.");
            if ($selected_page < 0) die('Unknown page.');
        }

        $logs_per_page = $this->config('iface_rowamount');

        if (isset($_GET['name']) && $_GET['name'] != '') {
            $number_of_logs = DbZoneLogger::count_logs_by_domain($_GET['name']);
            $number_of_pages = ceil($number_of_logs / $logs_per_page);
            if ($number_of_logs != 0 && $selected_page > $number_of_pages) die('Unknown page');
            $logs = DbZoneLogger::get_logs_for_domain($_GET['name'], $logs_per_page, ($selected_page - 1) * $logs_per_page);

        } else {
            $number_of_logs = DbZoneLogger::count_all_logs();
            $number_of_pages = ceil($number_of_logs / $logs_per_page);
            if ($number_of_logs != 0 && $selected_page > $number_of_pages) die('Unknown page');
            $logs = DbZoneLogger::get_all_logs($logs_per_page, ($selected_page - 1) * $logs_per_page);
        }

        $this->render('list_log_zones.html', [
            'number_of_logs' => $number_of_logs,
            'name' => isset($_GET['name']) ? htmlspecialchars($_GET['name']) : null,
            'data' => $logs,
            'selected_page' => $selected_page,
            'logs_per_page' => $logs_per_page,
            'pagination' => show_pages($number_of_logs, $logs_per_page),
        ]);
    }
}

$controller = new ListLogZonesController();
$controller->run();
