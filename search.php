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

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Permission;

require_once 'inc/toolkit.inc.php';
require_once 'inc/header.inc.php';

$app = AppFactory::create();

if (!do_hook('verify_permission', 'search')) {
    error(ERR_PERM_SEARCH);
    require_once 'inc/footer.inc.php';
    exit;
}

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
    $_SESSION["search_zone_sort_by"] = $_GET["zone_sort_by"];
} elseif (isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
    $_SESSION["search_zone_sort_by"] = $_POST["zone_sort_by"];
} elseif (isset($_SESSION["search_zone_sort_by"])) {
    define('ZONE_SORT_BY', $_SESSION["search_zone_sort_by"]);
} else {
    define('ZONE_SORT_BY', "name");
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    define('RECORD_SORT_BY', $_GET["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    define('RECORD_SORT_BY', $_POST["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
    define('RECORD_SORT_BY', "name");
}

$parameters['query'] = isset($_POST['query']) && !empty($_POST['query']) ? htmlspecialchars($_POST['query']) : '';
$parameters['zones'] = !isset($_POST['do_search']) && !isset($_POST['zones']) || isset($_POST['zones']) && $_POST['zones'] == true;
$parameters['records'] = !isset($_POST['do_search']) && !isset($_POST['records']) || isset($_POST['records']) && $_POST['records'] == true;
$parameters['wildcard'] = !isset($_POST['do_search']) && !isset($_POST['wildcard']) || isset($_POST['wildcard']) && $_POST['wildcard'] == true;
$parameters['reverse'] = !isset($_POST['do_search']) && !isset($_POST['reverse']) || isset($_POST['reverse']) && $_POST['reverse'] == true;

$searchResult = [ 'zones' => null, 'records' => null ];

if (isset($_POST['query'])) {
    $searchResult = DnsRecord::search_zone_and_record(
        $parameters,
        Permission::getViewPermission(),
        ZONE_SORT_BY,
        RECORD_SORT_BY
    );
}

$app->render('search.html', [
    'zone_sort_by' => ZONE_SORT_BY,
    'record_sort_by' => RECORD_SORT_BY,
    'query' => $parameters['query'],
    'search_by_zones' => $parameters['zones'] ? 'checked' : '',
    'search_by_records' => $parameters['records'] ? 'checked' : '',
    'search_by_wildcard' => $parameters['wildcard'] ? 'checked' : '',
    'search_by_reverse' => $parameters['reverse'] ? 'checked' : '',
    'zones_found' => is_array($searchResult['zones']),
    'records_found' => is_array($searchResult['records']),
    'searchResult' => $searchResult,
    'edit_permission' => Permission::getEditPermission(),
    'user_id' => $_SESSION['userid'],
]);

require_once 'inc/footer.inc.php';
