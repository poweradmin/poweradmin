<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\Validator;

class EditUserController extends BaseController
{

    public function run(): void
    {
        $edit_id = -1;
        if (isset($_GET['id']) && Validator::is_number($_GET['id'])) {
            $edit_id = (int)$_GET['id'];
        }

        $perm_edit_own = UserManager::verify_permission($this->db, 'user_edit_own');
        $perm_edit_others = UserManager::verify_permission($this->db, 'user_edit_others');

        if ($edit_id == -1) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        if (($edit_id != $_SESSION["userid"] || !$perm_edit_own) && ($edit_id == $_SESSION["userid"] || !$perm_edit_others)) {
            $this->showError(_("You do not have the permission to edit this user."));
        }

        if ($edit_id != $_SESSION["userid"] && UserManager::is_user_superuser($this->db, $edit_id) && !UserManager::verify_permission($this->db, 'user_is_ueberuser')) {
            $this->showError(_('You do not have permission to edit a superuser account.'));
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->saveUser($edit_id);
        }

        $this->showUserEditForm($edit_id);
    }

    public function saveUser(int $edit_id): void
    {
        $i_username = "-1";
        $i_fullname = "-1";
        $i_email = "-1";
        $i_description = "-1";
        $i_password = "";
        $i_perm_templ = "0";

        if (isset($_POST['username'])) {
            $i_username = htmlspecialchars($_POST['username']);
        }

        if (isset($_POST['fullname'])) {
            $i_fullname = htmlspecialchars($_POST['fullname']);
        }

        if (isset($_POST['email'])) {
            $i_email = htmlspecialchars($_POST['email']);
        }

        if (isset($_POST['description'])) {
            $i_description = htmlspecialchars($_POST['description']);
        }

        if (isset($_POST['password'])) {
            $i_password = $_POST['password'];
        }

        if (isset($_POST['perm_templ']) && Validator::is_number($_POST['perm_templ'])) {
            $i_perm_templ = $_POST['perm_templ'];
        } else {
            $user_details = UserManager::get_user_detail_list($this->db, $this->config('ldap_use'), $edit_id);
            $i_perm_templ = $user_details[0]['tpl_id'];
        }

        $i_active = false;
        if (isset($_POST['active']) && Validator::is_number($_POST['active'])) {
            $i_active = true;
        }

        $i_use_ldap = false;
        if (isset($_POST['use_ldap']) && Validator::is_number($_POST['use_ldap'])) {
            $i_use_ldap = true;
        }

        if ($i_username == "-1" || $i_fullname == "-1" || $i_email < "1" || $i_description == "-1") {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        if ($edit_id == $_SESSION["userid"]) {
            // Your own password goes through the change password page, which asks for the current one
            $i_password = "";
            $error = $this->ownIdentityChangeError($edit_id, $i_username, $i_email);
            if ($error !== null) {
                $this->setMessage('edit_user', 'error', $error);
                return;
            }
        }

        if ($i_username != "" && $i_perm_templ > "0" && $i_fullname) {
            $legacyUsers = new UserManager($this->db, $this->getConfig());
            if ($legacyUsers->edit_user($edit_id, $i_username, $i_fullname, $i_email, $i_perm_templ, $i_description, $i_active, $i_password, $i_use_ldap)) {
                $this->setMessage('users', 'success', _('The user has been updated successfully.'));
                $this->redirect('index.php', ['page' => 'users']);
            } else {
                $this->setMessage('edit_user', 'error', _('The user could not be updated.'));
            }
        } else {
            $this->setMessage('edit_user', 'error', _('The user could not be updated.'));
        }
    }

    /**
     * A borrowed session must not redirect the account's mail or rename it without the
     * password. LDAP accounts have no local password to confirm.
     */
    private function ownIdentityChangeError(int $edit_id, string $username, string $email): ?string
    {
        $users = UserManager::get_user_detail_list($this->db, $this->config('ldap_use'), $edit_id);
        $stored = $users[0] ?? null;
        if ($stored === null || !empty($stored['use_ldap'])) {
            return null;
        }

        // The username is only editable with user_edit_others; otherwise the stored one is kept
        $checkUsername = UserManager::verify_permission($this->db, 'user_edit_others');
        if (!self::changesSignInIdentity($stored, $checkUsername ? $username : (string)$stored['username'], $email)) {
            return null;
        }

        $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        if (!UserManager::verify_user_password($this->db, $edit_id, $current)) {
            return _('You did not enter the correct current password.');
        }

        return null;
    }

    /**
     * Whether the submitted username or email differs from the stored one.
     */
    public static function changesSignInIdentity(array $stored, string $username, string $email): bool
    {
        return $username !== (string)($stored['username'] ?? '') || $email !== (string)($stored['email'] ?? '');
    }

    public function showUserEditForm(int $edit_id): void
    {
        $users = UserManager::get_user_detail_list($this->db, $this->config('ldap_use'), $edit_id);
        if (empty($users)) {
            $this->showError(_('User does not exist.'));
        }

        $user = $users[0];
        $edit_templ_perm = UserManager::verify_permission($this->db, 'user_edit_templ_perm');
        $passwd_edit_others_perm = UserManager::verify_permission($this->db, 'user_passwd_edit_others');
        $edit_own_perm = UserManager::verify_permission($this->db, 'user_edit_own');
        $permission_templates = UserManager::list_permission_templates($this->db);
        $user_permissions = UserManager::get_permissions_by_template_id($this->db, $user['tpl_id']);

        ($user['active']) == "1" ? $check = " CHECKED" : $check = "";
        $name = $user['fullname'] ?: $user['username'];

        $use_ldap_checked = "";
        if ($user['use_ldap']) {
            $use_ldap_checked = "checked";
        }

        $permissions = Permission::getPermissions($this->db, ['user_is_ueberuser']);
        $currentUserAdmin = $permissions['user_is_ueberuser'] && $_SESSION['userid'] == $edit_id;

        $this->render('edit_user.html', [
            'edit_id' => $edit_id,
            'name' => $name,
            'user' => $user,
            'session_user_id' => $_SESSION['userid'],
            'check' => $check,
            'edit_templ_perm' => $edit_templ_perm,
            'edit_own_perm' => $edit_own_perm,
            'perm_passwd_edit_others' => $passwd_edit_others_perm,
            'self_edit_locked' => $_SESSION['userid'] == $edit_id && !UserManager::verify_permission($this->db, 'user_edit_others'),
            'permission_templates' => $permission_templates,
            'user_permissions' => $user_permissions,
            'ldap_use' => $this->config('ldap_use') && !$currentUserAdmin,
            'use_ldap_checked' => $use_ldap_checked,
        ]);
    }
}
