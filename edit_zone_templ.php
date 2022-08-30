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
use Poweradmin\RecordType;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$iface_rowamount = $app->config('iface_rowamount');

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    define('ROWSTART', 0);
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

$zone_templ_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $zone_templ_id = htmlspecialchars($_GET['id']);
}

if ($zone_templ_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

/*
  Check permissions
 */
$owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST['commit']) && $owner) {
    success(SUC_ZONE_TEMPL_UPD);
    foreach ($_POST['record'] as $record) {
        ZoneTemplate::edit_zone_templ_record($record);
    }
}

if (isset($_POST['edit']) && $owner) {
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

if (!(do_hook('verify_permission' , 'zone_master_add' )) || !$owner) {
    error(ERR_PERM_EDIT_ZONE_TEMPL);
} else {
    if (ZoneTemplate::zone_templ_id_exists($zone_templ_id) == "0") {
        error(ERR_ZONE_TEMPL_NOT_EXIST);
    } else {
        $record_count = ZoneTemplate::count_zone_templ_records($zone_templ_id);
        $templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
        echo "   <h2>" . _('Edit zone template') . " \"" . $templ_details['name'] . "\"</h2>\n";

        echo "   <div>\n";
        show_pages($record_count, $iface_rowamount, $zone_templ_id);
        echo "   </div>\n";

        $records = ZoneTemplate::get_zone_templ_records($zone_templ_id, ROWSTART, $iface_rowamount, RECORD_SORT_BY);
        if ($records == "-1") {
            echo " <p>" . _("This template zone does not have any records yet.") . "</p>\n";
        } else {
            echo "   <form method=\"post\" action=\"\">\n";
            echo "   <table>\n";
            echo "    <tr>\n";
            echo "     <th>&nbsp;</th>\n";
            echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&amp;record_sort_by=name\">" . _('Name') . "</a></th>\n";
            echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&amp;record_sort_by=type\">" . _('Type') . "</a></th>\n";
            echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&amp;record_sort_by=content\">" . _('Content') . "</a></th>\n";
            echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&amp;record_sort_by=prio\">" . _('Priority') . "</a></th>\n";
            echo "     <th><a href=\"edit_zone_templ.php?id=" . $zone_templ_id . "&amp;record_sort_by=ttl\">" . _('TTL') . "</a></th>\n";
            echo "    </tr>\n";
            foreach ($records as $r) {
                echo "    <tr>\n";
                echo "     <td class=\"n\">\n";
                echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][rid]\" value=\"" . $r['id'] . "\">\n";
                echo "      <a class=\"btn btn-outline-primary btn-sm\" href=\"edit_zone_templ_record.php?id=" . $r['id'] . "&amp;zone_templ_id=" . $zone_templ_id . "\">
						<i class=\"bi bi-pencil-square\"></i>" . _('Edit record') . "</a>\n";
                echo "      <a class=\"btn btn-outline-danger btn-sm\" href=\"delete_zone_templ_record.php?id=" . $r['id'] . "&amp;zone_templ_id=" . $zone_templ_id . "\">
						<i class=\"bi bi-trash\"></i>" . _('Delete record') . "</a>\n";
                echo "     </td>\n";
                echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][name]\" value=\"" . $r['name'] . "\"></td>\n";
                echo "      <td class=\"u\">\n";
                echo "       <select name=\"record[" . $r['id'] . "][type]\">\n";
                $found_selected_type = false;
                foreach (RecordType::getTypes() as $type_available) {
                    if ($type_available == $r['type']) {
                        $add = " SELECTED";
                        $found_selected_type = true;
                    } else {
                        $add = "";
                    }
                    echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
                }
                if (!$found_selected_type) {
                    echo "         <option SELECTED value=\"" . htmlspecialchars($r['type']) . "\"><i>" . $r['type'] . "</i></option>\n";
                }
                echo "       </select>\n";
                echo "      </td>\n";
                echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][content]\" value='" . $r['content'] . "'></td>\n";
                if ($r['type'] == "MX" || $r['type'] == "SRV") {
                    echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][prio]\" value=\"" . $r['prio'] . "\"></td>\n";
                } else {
                    echo "      <td class=\"n\">&nbsp;</td>\n";
                }
                echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][ttl]\" value=\"" . $r['ttl'] . "\"></td>\n";
                echo "     </tr>\n";
            }
            echo "     <tr>\n";
            echo "      <td colspan=\"6\"><br><b>" . _('Hint:') . "</b></td>\n";
            echo "     </tr>\n";
            echo "     <tr>\n";
            echo "      <td colspan=\"6\">" . _('The following placeholders can be used in template records') . "</td>\n";
            echo "     </tr>\n";
            echo "     <tr>\n";
            echo "      <td colspan=\"6\"><br>&nbsp;&nbsp;&nbsp;&nbsp; * [ZONE] - " . _('substituted with current zone name') . "<br>";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [SERIAL] - " . _('substituted with current date and 2 numbers') . " (YYYYMMDD + 00)<br>\n";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [NS1] - " . _('substituted with 1st name server') . "<br>\n";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [NS2] - " . _('substituted with 2nd name server') . "<br>\n";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [NS3] - " . _('substituted with 3rd name server') . "<br>\n";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [NS4] - " . _('substituted with 4th name server') . "<br>\n";
            echo "&nbsp;&nbsp;&nbsp;&nbsp; * [HOSTMASTER] - " . _('substituted with hostmaster') . "</td>\n";
            echo "     </tr>\n";
            echo "     <tr>\n";
            echo "      <td colspan=\"6\"><br><b>" . _('Examples:') . "</b></td>\n";
            echo "     </tr>\n";
            echo "     <tr>\n";
            echo "      <td colspan=\"6\">" . _('To add a subdomain foo in a zonetemplate you would put foo.[ZONE] into the name field.') . "<br>";
            echo "      " . _('To add a wildcard record put *.[ZONE] in the name field.') . "<br>";
            echo "      " . _('Use just [ZONE] to have the domain itself return a value.') . "<br>";
            echo "      " . _('For the SOA record, place [NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400 in the content field.') . "</td>";
            echo "     </tr>\n";
            echo "      <td colsn=\"6\"><br>Save as new template:</td>\n";
            echo "     </tr>\n";
            echo "      <tr>\n";
            echo "       <th>" . _('Template Name') . "</th>\n";
            echo "       <td><input type=\"text\" name=\"templ_name\" value=\"\"></td>\n";
            echo "      </tr>\n";
            echo "      <tr>\n";
            echo "       <th>" . _('Template Description') . "</th>\n";
            echo "       <td><input type=\"text\" name=\"templ_descr\" value=\"\"></td>\n";
            echo "      </tr>\n";
            echo "    </table>\n";
            echo "     <input type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
            echo "     <input type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\">\n";
            echo "     <input type=\"submit\" name=\"save_as\" value=\"" . _('Save as template') . "\">\n";
            echo "     <input type=\"submit\" name=\"update_zones\" value=\"" . _('Update zones') . "\">\n";
            echo "    </form>";
        }

        echo "    <form method=\"post\" action=\"\">\n";
        echo "     <table>\n";
        echo "      <tr>\n";
        echo "       <td width=\"150\">" . _('Name') . "</td>\n";
        echo "       <td><input type=\"text\" name=\"templ_name\" value=\"" . $templ_details['name'] . "\"></td>\n";
        echo "      </tr>\n";
        echo "      <tr>\n";
        echo "       <td>" . _('Description') . "</td>\n";
        echo "       <td><input type=\"text\" name=\"templ_descr\" value=\"" . $templ_details['descr'] . "\"></td>\n";
        echo "      </tr>\n";
        echo "     </table>\n";
        echo "     <input type=\"submit\" name=\"edit\" value=\"" . _('Commit changes') . "\">\n";
        echo "     </form>\n";
        echo "    <input type=\"button\" onClick=\"location.href='add_zone_templ_record.php?id=" . $zone_templ_id . "'\" value=\"" . _('Add record') . "\">&nbsp;&nbsp\n";
        echo "    <input type=\"button\" onClick=\"location.href='delete_zone_templ.php?id=" . $zone_templ_id . "'\" value=\"" . _('Delete zone template') . "\">\n";
    }
}

include_once("inc/footer.inc.php");
