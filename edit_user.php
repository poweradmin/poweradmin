<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Script that handles user editing requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$edit_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $edit_id = $_GET['id'];
}

verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0";
verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0";

if ($edit_id == "-1") {
    error(ERR_INV_INPUT);
} elseif (($edit_id == $_SESSION["userid"] && $perm_edit_own == "1") || ($edit_id != $_SESSION["userid"] && $perm_edit_others == "1" )) {

    if (isset($_POST["commit"])) {

        $i_username = "-1";
        $i_fullname = "-1";
        $i_email = "-1";
        $i_description = "-1";
        $i_password = "-1";
        $i_perm_templ = "0";
        $i_active = "0";

        if (isset($_POST['username'])) {
            $i_username = $_POST['username'];
        }

        if (isset($_POST['fullname'])) {
            $i_fullname = $_POST['fullname'];
        }

        if (isset($_POST['email'])) {
            $i_email = $_POST['email'];
        }

        if (isset($_POST['description'])) {
            $i_description = $_POST['description'];
        }

        if (isset($_POST['password'])) {
            $i_password = $_POST['password'];
        }

        if (isset($_POST['perm_templ']) && v_num($_POST['perm_templ'])) {
            $i_perm_templ = $_POST['perm_templ'];
        }

        if (isset($_POST['active']) && v_num($_POST['active'])) {
            $i_active = $_POST['active'];
        }

        if ($i_username == "-1" || $i_fullname == "-1" || $i_email < "1" || $i_description == "-1" || $i_password == "-1") {
            error(ERR_INV_INPUT);
        } else {
            if ($i_username != "" && $i_perm_templ > "0" && $i_fullname) {
                if (!isset($i_active)) {
                    $active = 0;
                } else {
                    $active = 1;
                }
                if (edit_user($edit_id, $i_username, $i_fullname, $i_email, $i_perm_templ, $i_description, $active, $i_password)) {
                    success(SUC_USER_UPD);
                }
            }
        }
    }

    $users = get_user_detail_list($edit_id);

    foreach ($users as $user) {

        (($user['active']) == "1") ? $check = " CHECKED" : $check = "";

        echo "     <h2>" . _('Edit user') . " \"" . $user['fullname'] . "\"</h2>\n";
        echo "     <form method=\"post\" action=\"\">\n";
        echo "      <input type=\"hidden\" name=\"number\" value=\"" . $edit_id . "\">\n";
        echo "      <table>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Username') . "</td>\n";
        echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"username\" value=\"" . $user['username'] . "\"></td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Fullname') . "</td>\n";
        echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"fullname\" value=\"" . $user['fullname'] . "\"></td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Password') . "</td>\n";
        echo "        <td class=\"n\"><input type=\"password\" class=\"input\" name=\"password\"></td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Email address') . "</td>\n";
        echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"email\" value=\"" . $user['email'] . "\"></td>\n";
        echo "       </tr>\n";
        if (verify_permission('user_edit_templ_perm')) {
            echo "       <tr>\n";
            echo "        <td class=\"n\">" . _('Permission template') . "</td>\n";
            echo "        <td class=\"n\">\n";
            echo "         <select name=\"perm_templ\">\n";
            foreach (list_permission_templates() as $template) {
                ($template['id'] == $user['tpl_id']) ? $select = " SELECTED" : $select = "";
                echo "          <option value=\"" . $template['id'] . "\"" . $select . ">" . $template['name'] . "</option>\n";
            }
            echo "         </select>\n";
            echo "       </td>\n";
        }
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Description') . "</td>\n";
        echo "        <td class=\"n\"><textarea rows=\"4\" cols=\"30\" class=\"inputarea\" name=\"description\">" . $user['descr'] . "</textarea></td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Enabled') . "</td>\n";
        echo "        <td class=\"n\"><input type=\"checkbox\" class=\"input\" name=\"active\" value=\"1\"" . $check . "></td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td class=\"n\">&nbsp;</td>\n";
        echo "        <td class=\"n\"><input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
        echo "        <input type=\"reset\" class=\"button\" name=\"reset\" value=\"" . _('Reset changes') . "\"></td>\n";
        echo "      </table>\n";
        echo "     </form>\n";

        echo "     <p>\n";
        printf(_('This user has been assigned the permission template "%s".'), $user['tpl_name']);
        if ($user['tpl_descr'] != "") {
            echo " " . _('The description for this template is') . ": \"" . $user['tpl_descr'] . "\".";
        }
        echo " " . _('Based on this template, this user has the following permissions') . ":";
        echo "     </p>\n";
        echo "     <ul>\n";
        foreach (get_permissions_by_template_id($user['tpl_id']) as $item) {
            echo "      <li>" . _($item['descr']) . " (" . $item['name'] . ")</li>\n";
        }
        echo "     </ul>\n";
    }
} else {
    error(ERR_PERM_EDIT_USER);
}

include_once("inc/footer.inc.php");
