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
 * Script that handles user deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\DnsRecord;
use Poweradmin\User;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$perm_edit_others = do_hook('verify_permission', 'user_edit_others');
$perm_is_godlike = do_hook('verify_permission', 'user_is_ueberuser');

if (!(isset($_GET['id']) && Validation::is_number($_GET['id']))) {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

$uid = htmlspecialchars($_GET['id']);

if (isset($_POST['commit'])) {
    if (do_hook('is_valid_user', $uid)) {
        $zones = array();
        if (isset($_POST['zone'])) {
            $zones = $_POST['zone'];
        }

        if (do_hook('delete_user', $uid, $zones)) {
            success(SUC_USER_DEL);
        }
    } else {
        header("Location: users.php");
        exit;
    }
    include_once("inc/footer.inc.php");
    exit;
}

if (($uid != $_SESSION['userid'] && !$perm_edit_others) || ($uid == $_SESSION['userid'] && !$perm_is_godlike)) {
    error(ERR_PERM_DEL_USER);
    include_once("inc/footer.inc.php");
    exit;
}

$name = do_hook('get_fullname_from_userid', $uid);
if (!$name) {
    $name = User::get_username_by_id($uid);
}
$zones = DnsRecord::get_zones("own", $uid);
$user = [];
if (count($zones) > 0) {
    $users = do_hook('show_users');
}

echo "     <h5 class=\"mb-3\">" . _('Delete user') . " \"" . $name . "\"</h5>\n";
echo "     <form method=\"post\" action=\"\">\n";
echo "      <table class=\"table table-striped table-sm\">\n";

if (count($zones) > 0) {
    echo "       <tr>\n";
    echo "        <td colspan=\"5\">\n";

    echo "         " . _('You are about to delete a user. This user is owner for a number of zones. Please decide what to do with these zones.') . "\n";
    echo "        </td>\n";
    echo "       </tr>\n";

    echo "       <tr>\n";
    echo "        <th>" . _('Zone') . "</th>\n";
    echo "        <th>" . _('Delete') . "</th>\n";
    echo "        <th>" . _('Leave') . "</th>\n";
    echo "        <th>" . _('Add new owner') . "</th>\n";
    echo "        <th>" . _('Owner to be added') . "</th>\n";
    echo "       </tr>\n";

    foreach ($zones as $zone) {
        echo "       <input type=\"hidden\" name=\"zone[" . $zone['id'] . "][zid]\" value=\"" . $zone['id'] . "\">\n";
        echo "       <tr>\n";
        echo "        <td>" . $zone['name'] . "</td>\n";
        echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"delete\"></td>\n";
        echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"leave\" CHECKED></td>\n";
        echo "        <td><input type=\"radio\" name=\"zone[" . $zone['id'] . "][target]\" value=\"new_owner\"></td>\n";
        echo "        <td>\n";
        echo "         <select class=\"form-select form-select-sm\" name=\"zone[" . $zone['id'] . "][newowner]\">\n";

        foreach ($users as $user) {
            echo "          <option value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
        }

        echo "         </select>\n";
        echo "        </td>\n";
        echo "       </tr>\n";
    }
}
echo "       <tr>\n";
echo "        <td colspan=\"5\">\n";

echo "         " . _('Are you sure?') . "\n";
echo "        </td>\n";
echo "       </tr>\n";

echo "      </table>\n";
echo "     <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Yes') . "\">\n";
echo "     <input class=\"btn btn-secondary btn-sm\" type=\"button\" onClick=\"location.href='users.php'\" value=\"" . _('No') . "\">\n";
echo "     </form>\n";

include_once("inc/footer.inc.php");
