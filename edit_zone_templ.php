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
 * Script that handles zone templates editing
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$iface_rowamount = $app->config('iface_rowamount');

$row_start = 0;
if (isset($_GET["start"])) {
    $row_start = ($_GET["start"] - 1) * $iface_rowamount;
}

$record_sort_by = 'name';
if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    $record_sort_by = $_GET["record_sort_by"];
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    $record_sort_by = $_POST["record_sort_by"];
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    $record_sort_by = $_SESSION["record_sort_by"];
}

$zone_templ_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $zone_templ_id = htmlspecialchars($_GET['id']);
}

if ($zone_templ_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

$owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST['commit']) && $owner) {
    success(SUC_ZONE_TEMPL_UPD);
    foreach ($_POST['record'] as $record) {
        ZoneTemplate::edit_zone_templ_record($record);
    }
}

if (isset($_POST['edit']) && $owner) {
    if (!isset($_POST['templ_name']) || $_POST['templ_name'] == "") {
        error(ERR_INV_INPUT);
        include_once('inc/footer.inc.php');
        exit;
    }
    ZoneTemplate::edit_zone_templ($_POST, $zone_templ_id);
}

if (isset($_POST['save_as'])) {
    if (ZoneTemplate::zone_templ_name_exists($_POST['templ_name'])) {
        error(ERR_ZONE_TEMPL_EXIST);
    } elseif ($_POST['templ_name'] == '') {
        error(ERR_ZONE_TEMPL_IS_EMPTY);
    } else {
        success(SUC_ZONE_TEMPL_ADD);
        $templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
        ZoneTemplate::add_zone_templ_save_as($_POST['templ_name'], $_POST['templ_descr'], $_SESSION['userid'], $_POST['record']);
    }
}

if (isset($_POST['update_zones'])) {
    $zones = ZoneTemplate::get_list_zone_use_templ($zone_templ_id, $_SESSION['userid']);
    success(SUC_ZONES_UPD);
    foreach ($zones as $zone) {
        DnsRecord::update_zone_records($zone['id'], $zone_templ_id);
    }
}

if (!(do_hook('verify_permission', 'zone_master_add')) || !$owner) {
    error(ERR_PERM_EDIT_ZONE_TEMPL);
    include_once("inc/footer.inc.php");
    exit;
}

if (ZoneTemplate::zone_templ_id_exists($zone_templ_id) == "0") {
    error(ERR_ZONE_TEMPL_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit;
}

$record_count = ZoneTemplate::count_zone_templ_records($zone_templ_id);
$templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);

$app->render('edit_zone_templ.html', [
    'templ_details' => $templ_details,
    'pagination' => show_pages($record_count, $iface_rowamount, $zone_templ_id),
    'records' => $records = ZoneTemplate::get_zone_templ_records($zone_templ_id, $row_start, $iface_rowamount, $record_sort_by),
    'zone_templ_id' => $zone_templ_id,
]);

include_once("inc/footer.inc.php");
