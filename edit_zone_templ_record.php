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
 * Script that handles records editing in zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\RecordType;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$record_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $record_id = htmlspecialchars($_GET['id']);
}

$zone_templ_id = "-1";
if (isset($_GET['zone_templ_id']) && Validation::is_number($_GET['zone_templ_id'])) {
    $zone_templ_id = htmlspecialchars($_GET['zone_templ_id']);
}

$owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST["commit"])) {
    if (!(do_hook('verify_permission' , 'zone_master_add' )) || !$owner) {
        error(ERR_PERM_EDIT_RECORD);
    } else {
        $ret_val = ZoneTemplate::edit_zone_templ_record($_POST);
        if ($ret_val == "1") {
            success(SUC_RECORD_UPD);
        } else {
            echo "     <div class=\"alert alert-danger\">" . $ret_val . "</div>\n";
        }
    }
}

$templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
echo "    <h5 class=\"mb-3\">" . _('Edit record in zone template') . " \"" . $templ_details['name'] . "\"</h5>\n";

if (!(do_hook('verify_permission' , 'zone_master_add' )) || !$owner) {
    error(ERR_PERM_VIEW_RECORD);
} else {
    $record = ZoneTemplate::get_zone_templ_record_from_id($record_id);
    echo "     <form class=\"needs-validation\" method=\"post\" action=\"edit_zone_templ_record.php?zone_templ_id=" . $zone_templ_id . "&id=" . $record_id . "\" novalidate>\n";
    echo "      <table class=\"table table-striped table-hover table-sm\">\n";
    echo "       <tr>\n";
    echo "        <th>" . _('Name') . "</td>\n";
    echo "        <th>&nbsp;</td>\n";
    echo "        <th>" . _('Type') . "</td>\n";
    echo "        <th>" . _('Content') . "</td>\n";
    echo "        <th>" . _('Priority') . "</td>\n";
    echo "        <th>" . _('TTL') . "</td>\n";
    echo "       </tr>\n";
    echo "      <input type=\"hidden\" name=\"rid\" value=\"" . $record_id . "\">\n";
    echo "      <input type=\"hidden\" name=\"zid\" value=\"" . $zone_templ_id . "\">\n";
    echo "      <tr>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"name\" value=\"" . htmlspecialchars($record["name"]) . "\" required>";
    echo "       <div class=\"invalid-feedback\">" . _('Provide name') . "</div>";
    echo "       </td>\n";
    echo "       <td>IN</td>\n";
    echo "       <td>\n";
    echo "        <select class=\"form-select form-select-sm\" name=\"type\">\n";
    $found_selected_type = false;
    foreach (RecordType::getTypes() as $type_available) {
        if ($type_available == $record["type"]) {
            $add = " SELECTED";
            $found_selected_type = true;
        } else {
            $add = "";
        }
        echo "         <option" . $add . " value=\"" . $type_available . "\" >" . $type_available . "</option>\n";
    }
    if (!$found_selected_type)
        echo "         <option SELECTED value=\"" . htmlspecialchars($record['type']) . "\"><i>" . $record['type'] . "</i></option>\n";
    echo "        </select>\n";
    echo "       </td>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"content\" value=\"" . htmlspecialchars($record['content']) . "\" required>\n";
    echo "       <div class=\"invalid-feedback\">" . _('Provide content') . "</div>";
    echo "       </td>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"prio\" value=\"" . htmlspecialchars($record["prio"]) . "\"></td>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"ttl\" value=\"" . htmlspecialchars($record["ttl"]) . "\"></td>\n";
    echo "      </tr>\n";
    echo "      </table>\n";
    echo "      <p class=\"pt-3\">\n";
    echo "       <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\">&nbsp;&nbsp;\n";
    echo "       <input class=\"btn btn-secondary btn-sm\" type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\">&nbsp;&nbsp;\n";
    echo "      </p>\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
