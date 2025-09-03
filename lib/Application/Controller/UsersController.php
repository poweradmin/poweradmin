<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
 * Script that handles requests to update and list users
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;

class UsersController extends BaseController
{

    public function run(): void
    {
        // Check if user has permission to view or edit other users before processing
        $canViewOthers = UserManager::verifyPermission($this->db, 'user_view_others');
        $canEditOthers = UserManager::verifyPermission($this->db, 'user_edit_others');

        // If user doesn't have permissions to view/edit others, redirect to home
        if (!$canViewOthers && !$canEditOthers) {
            $this->setMessage('index', 'error', _('You do not have permission to view the users list.'));
            $this->redirect('/');
            return;
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateUsers();
        }
        $this->showUsers();
    }

    private function updateUsers(): void
    {
        $success = false;
        foreach ($_POST['user'] as $user) {
            $legacyUsers = new UserManager($this->db, $this->getConfig());
            $result = $legacyUsers->updateUserDetails($user);
            if ($result) {
                $success = true;
            }
        }
        if ($success) {
            $this->setMessage('users', 'success', _('User details updated'));
        }
    }

    private function showUsers(): void
    {
        $permissions = Permission::getPermissions(
            $this->db,
            [
                'user_view_others',
                'user_edit_own',
                'user_edit_others',
                'user_edit_templ_perm',
                'user_is_ueberuser'
            ]
        );

        $this->render('users.html', [
            'permissions' => $permissions,
            'perm_templates' => UserManager::listPermissionTemplates($this->db),
            'users' => UserManager::getUserDetailList($this->db, $this->config->get('ldap', 'enabled', false)),
            'ldap_use' => $this->config->get('ldap', 'enabled', false),
            'session_userid' => $_SESSION["userid"],
            'perm_add_new' => UserManager::verifyPermission($this->db, 'user_add_new'),
        ]);
    }
}
