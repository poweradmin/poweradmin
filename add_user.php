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
 * Script that handles requests to add new users
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$ldap_use = $app->config('ldap_use');

$username = $_POST['username'] ?? "";
$fullname = $_POST['fullname'] ?? "";
$email = $_POST['email'] ?? "";
$perm_templ = $_POST['perm_templ'] ?? "1";
$password = $_POST['password'] ?? "";
$description = $_POST['descr'] ?? "";

$active_checked = "checked";
if (isset($_POST['active'])) {
    $active_checked = $_POST['active'] === "1" ? "checked" : "";
}

$use_ldap_checked = "";
if (isset($_POST['use_ldap'])) {
    $use_ldap_checked = $_POST['use_ldap'] === "1" ? "checked" : "";
}

if (!do_hook('verify_permission', 'user_add_new')) {
    error(ERR_PERM_ADD_USER);
    include_once("inc/footer.inc.php");
    exit;
}

if (isset($_POST["commit"]) && do_hook('add_new_user', $_POST)) {
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

echo "     <h5 class=\"mb-3\">" . _('Add user') . "</h5>\n";
echo "     <form class=\"needs-validation\" method=\"post\" action=\"add_user.php\" novalidate>\n";
echo "      <table>\n";
echo "       <tr>\n";
echo "        <td>" . _('Username') . "</td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"username\" value=\"" . htmlspecialchars($username) . "\" required>";
echo "        <div class=\"invalid-feedback\">" . _('Provide user name') . "</div>";
echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Fullname') . "</td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"fullname\" value=\"" . htmlspecialchars($fullname) . "\"></td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Password') . "</td>\n";
echo "        <td><input class=\"form-control form-control-sm\" id=\"password\" name=\"password\" value=\"" . $password . "\"></td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Email address') . "</td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"email\" value=\"" . htmlspecialchars($email) . "\" required>\n";
echo "        <div class=\"invalid-feedback\">" . _('Provide email') . "</div>";
echo "        </td>\n";
echo "       </tr>\n";
if (do_hook('verify_permission', 'user_edit_templ_perm')) {
    echo "       <tr>\n";
    echo "        <td>" . _('Permission template') . "</td>\n";
    echo "        <td>\n";
    echo "         <select class=\"form-select form-select-sm\" name=\"perm_templ\">\n";
    foreach (do_hook('list_permission_templates') as $template) {
        $selected = $perm_templ == $template['id'] ? "selected" : "";
        echo "          <option value=\"" . $template['id'] . "\"" . $selected . ">" . $template['name'] . "</option>\n";
    }
    echo "         </select>\n";
    echo "       </td>\n";
    echo "       </tr>\n";
}
echo "       <tr>\n";
echo "        <td>" . _('Description') . "</td>\n";
echo "        <td><textarea class=\"form-control form-control-sm\" rows=\"4\" cols=\"30\" class=\"inputarea\" name=\"descr\">" . htmlspecialchars($description) . "</textarea></td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Enabled') . "</td>\n";
echo "        <td><input class=\"form-check-input\" type=\"checkbox\" name=\"active\" value=\"1\"" . $active_checked . "></td>\n";
echo "       </tr>\n";
if ($ldap_use) {
    echo "       <tr>\n";
    echo "        <td>" . _('LDAP Authentication') . "</td>\n";
    echo "        <td><input class=\"form-check-input\" id=\"ldap\" type=\"checkbox\" name=\"use_ldap\" value=\"1\" onclick=\"disablePasswordField()\" " . $use_ldap_checked . "></td>\n";
    echo "       </tr>\n";
}
echo "       <tr>\n";
echo "        <td>&nbsp;</td>\n";
echo "        <td><input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\"></td>\n";
echo "      </table>\n";
echo "     </form>\n";

include_once('inc/footer.inc.php');
