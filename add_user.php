<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 * Script that handles requests to add new users
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

echo "  <script type=\"text/javascript\" src=\"inc/helper.js\"></script>";

global $ldap_use;

$username = "";
if ((isset($_POST['username']))) {
    $username = $_POST['username'];
}

$fullname = "";
if ((isset($_POST['fullname']))) {
    $fullname = $_POST['fullname'];
}

$email = "";
if ((isset($_POST['email']))) {
    $email = $_POST['email'];
}

$perm_templ = "1";
if ((isset($_POST['perm_templ']))) {
    $perm_templ = $_POST['perm_templ'];
}

$password = "";
if ((isset($_POST['password']))) {
    $password = $_POST['password'];
}

$description = "";
if ((isset($_POST['descr']))) {
    $description = $_POST['descr'];
}

$active_checked = "checked";
if ((isset($_POST['active']))) {
    $active_checked = $_POST['active'] === "1" ? "checked" : "";
}

$use_ldap_checked = "";
if ((isset($_POST['use_ldap']))) {
    $use_ldap_checked = $_POST['use_ldap'] === "1" ? "checked" : "";
}

if (!do_hook('verify_permission' , 'user_add_new' )) {
    error(ERR_PERM_ADD_USER);
} else {
    if (isset($_POST["commit"])) {
        if (do_hook('add_new_user' , $_POST )) {
            success(SUC_USER_ADD);

            $username = "";
            $fullname = "";
            $password = "";
            $email = "";
            $perm_templ = "1";
            $description = "";
            $active_checked = "checked";
            $use_ldap_checked = "checked";
        }
    }

    echo "     <h2>" . _('Add user') . "</h2>\n";
    echo "     <form method=\"post\" action=\"add_user.php\">\n";
    echo "      <table>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Username') . "</td>\n";
    echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"username\" value=\"" . $username . "\"></td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Fullname') . "</td>\n";
    echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"fullname\" value=\"" . $fullname . "\"></td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Password') . "</td>\n";
    echo "        <td class=\"n\"><input id=\"password\" type=\"password\" class=\"input\" name=\"password\" value=\"" . $password . "\"></td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Email address') . "</td>\n";
    echo "        <td class=\"n\"><input type=\"text\" class=\"input\" name=\"email\" value=\"" . $email . "\"></td>\n";
    echo "       </tr>\n";
    if (do_hook('verify_permission' , 'user_edit_templ_perm' )) {
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('Permission template') . "</td>\n";
        echo "        <td class=\"n\">\n";
        echo "         <select name=\"perm_templ\">\n";
        foreach (do_hook('list_permission_templates' ) as $template) {
            $selected = $perm_templ == $template['id'] ? "selected" : "";
            echo "          <option value=\"" . $template['id'] . "\"" . $selected . ">" . $template['name'] . "</option>\n";
        }
        echo "         </select>\n";
        echo "       </td>\n";
        echo "       </tr>\n";
    }
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Description') . "</td>\n";
    echo "        <td class=\"n\"><textarea rows=\"4\" cols=\"30\" class=\"inputarea\" name=\"descr\">" . $description . "</textarea></td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Enabled') . "</td>\n";
    echo "        <td class=\"n\"><input type=\"checkbox\" class=\"input\" name=\"active\" value=\"1\"" . $active_checked . "></td>\n";
    echo "       </tr>\n";
    if ($ldap_use) {
        echo "       <tr>\n";
        echo "        <td class=\"n\">" . _('LDAP Authentication') . "</td>\n";
        echo "        <td class=\"n\"><input id=\"ldap\" type=\"checkbox\" class=\"input\" name=\"use_ldap\" value=\"1\" onclick=\"disablePasswordField()\" " . $use_ldap_checked . "></td>\n";
        echo "       </tr>\n";
    }
    echo "       <tr>\n";
    echo "        <td class=\"n\">&nbsp;</td>\n";
    echo "        <td class=\"n\"><input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\"></td>\n";
    echo "      </table>\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
