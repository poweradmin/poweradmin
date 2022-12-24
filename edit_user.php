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
 * Script that handles user editing requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class EditUserController extends BaseController
{

    public function run(): void
    {
        $edit_id = "-1";
        if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
            $edit_id = $_GET['id'];
        }

        do_hook('verify_permission', 'user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0";
        do_hook('verify_permission', 'user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0";

        if ($edit_id == "-1") {
            error(_('Invalid or unexpected input given.'));
            include_once("inc/footer.inc.php");
            exit;
        }

        if (($edit_id != $_SESSION["userid"] || $perm_edit_own != "1") && ($edit_id == $_SESSION["userid"] || $perm_edit_others != "1")) {
            error(_("You do not have the permission to edit this user."));
            include_once("inc/footer.inc.php");
            exit;
        }

        if ($this->isPost()) {
            $this->saveUser($edit_id);
        }

        $this->showUserEditForm($edit_id);
    }

    public function saveUser($edit_id): void
    {
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

        if (isset($_POST['perm_templ']) && Validation::is_number($_POST['perm_templ'])) {
            $i_perm_templ = $_POST['perm_templ'];
        }

        if (isset($_POST['active']) && Validation::is_number($_POST['active'])) {
            $i_active = $_POST['active'];
        }

        if ($i_username == "-1" || $i_fullname == "-1" || $i_email < "1" || $i_description == "-1" || $i_password == "-1") {
            error(_('Invalid or unexpected input given.'));
        } else {
            if ($i_username != "" && $i_perm_templ > "0" && $i_fullname) {
                $active = !isset($i_active);
                if (do_hook('edit_user', $edit_id, $i_username, $i_fullname, $i_email, $i_perm_templ, $i_description, $active, $i_password)) {
                    $this->setMessage('users', 'success', _('The user has been updated successfully.'));
                    $this->redirect('users.php');
                }
            }
        }
    }

    public function showUserEditForm($edit_id): void
    {
        $users = do_hook('get_user_detail_list', $edit_id);
        if (empty($users)) {
            error(_('User does not exist.'));
            include_once("inc/footer.inc.php");
            exit;
        }

        $user = $users[0];
        $edit_templ_perm = do_hook('verify_permission', 'user_edit_templ_perm');
        $permission_templates = do_hook('list_permission_templates');
        $user_permissions = do_hook('get_permissions_by_template_id', $user['tpl_id']);

        (($user['active']) == "1") ? $check = " CHECKED" : $check = "";
        $name = $user['fullname'] ?: $user['username'];

        $this->render('edit_user.html', [
            'edit_id' => $edit_id,
            'name' => $name,
            'user' => $user,
            'check' => $check,
            'edit_templ_perm' => $edit_templ_perm,
            'permission_templates' => $permission_templates,
            'user_permissions' => $user_permissions,
        ]);
    }
}

$controller = new EditUserController();
$controller->run();
