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
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$master_ip = "";
if (isset($_POST["master_ip"])) {
    $master_ip = $_POST["master_ip"];
}

$ns_name = "";
if (isset($_POST["ns_name"])) {
    $ns_name = $_POST["ns_name"];
}

$account = "";
if (isset($_POST["account"])) {
    $account = $_POST["account"];
}

(verify_permission('supermaster_add')) ? $supermasters_add = "1" : $supermasters_add = "0";
(verify_permission('user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

$error = 0;
if (isset($_POST["submit"])) {
    if (add_supermaster($master_ip, $ns_name, $account)) {
        success(SUC_SM_ADD);
    } else {
        $error = "1";
    }
}

echo "     <h2>" . _('Add supermaster') . "</h2>\n";

if ($supermasters_add != "1") {
    echo "     <p>" . _("You do not have the permission to add a new supermaster.") . "</p>\n";
} else {
    echo "     <form method=\"post\" action=\"add_supermaster.php\">\n";
    echo "      <table>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('IP address of supermaster') . "</td>\n";
    echo "        <td class=\"n\">\n";
    if ($error) {
        echo "         <input type=\"text\" class=\"input\" name=\"master_ip\" value=\"" . $master_ip . "\">\n";
    } else {
        echo "         <input type=\"text\" class=\"input\" name=\"master_ip\" value=\"\">\n";
    }
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Hostname in NS record') . "</td>\n";
    echo "        <td class=\"n\">\n";
    if ($error) {
        echo "         <input type=\"text\" class=\"input\" name=\"ns_name\" value=\"" . $ns_name . "\">\n";
    } else {
        echo "         <input type=\"text\" class=\"input\" name=\"ns_name\" value=\"\">\n";
    }
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Account') . "</td>\n";
    echo "        <td class=\"n\">\n";

    echo "         <select name=\"account\">\n";
    /*
      Display list of users to assign slave zone to if the
      editing user has the permissions to, otherise just
      display the adding users name
     */
    $users = show_users();
    foreach ($users as $user) {
        if ($user['id'] === $_SESSION['userid']) {
            echo "          <option value=\"" . $user['username'] . "\" selected>" . $user['fullname'] . "</option>\n";
        } elseif ($perm_view_others == "1") {
            echo "          <option value=\"" . $user['username'] . "\">" . $user['fullname'] . "</option>\n";
        }
    }
    echo "         </select>\n";

    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">&nbsp;</td>\n";
    echo "        <td class=\"n\">\n";
    echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Add supermaster') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </table>\n";
    echo "     </form>\n";
}
include_once("inc/footer.inc.php");
