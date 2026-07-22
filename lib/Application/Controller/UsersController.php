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
 * Script that handles requests to update and list users
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

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

        // Set the current page for navigation highlighting
        $this->setCurrentPage('users');
        $this->setPageTitle(_('Users'));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateUsers();
        }
        $this->showUsers();
    }

    private function updateUsers(): void
    {
        $success = false;
        $blocked = false;
        $currentIsSuperuser = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        foreach ($_POST['user'] as $user) {
            if (!is_array($user)) {
                continue;
            }
            // A delegated admin (non-ueberuser) must not modify a superuser account;
            // untouched superuser rows posted by the bulk form are skipped silently.
            if (!$currentIsSuperuser && UserManager::isUserSuperuser($this->db, (int)$user['uid'])) {
                if ($this->superuserRowEdited($user)) {
                    $blocked = true;
                }
                continue;
            }
            $legacyUsers = new UserManager($this->db, $this->getConfig());
            $result = $legacyUsers->updateUserDetails($user);
            if ($result) {
                $success = true;
            }
        }
        if ($success) {
            $this->setMessage('users', 'success', _('User details updated'));
        }
        if ($blocked) {
            $this->setMessage('users', 'error', _('You do not have permission to edit a superuser account.'));
        }
    }

    private function superuserRowEdited(array $posted): bool
    {
        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $current = $userRepository->getUserById((int)($posted['uid'] ?? 0));
        if ($current === null) {
            return true;
        }

        $postedActive = isset($posted['active']) && $posted['active'] == 'on' ? 1 : 0;
        $postedUseLdap = isset($posted['use_ldap']) && $posted['use_ldap'] == '1' ? 1 : 0;

        return ($posted['username'] ?? '') != $current['username']
            || ($posted['fullname'] ?? '') != ($current['fullname'] ?? '')
            || ($posted['email'] ?? '') != ($current['email'] ?? '')
            || (isset($posted['templ_id']) && (int)$posted['templ_id'] != (int)$current['perm_templ'])
            || $postedActive != (int)$current['active']
            || $postedUseLdap != (int)$current['use_ldap'];
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

        // Pagination setup
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();
        $rowsPerPage = $this->config->get('interface', 'rows_per_page', 50);

        $paginationService = $this->createPaginationService();
        $rowsPerPage = $paginationService->getUserRowsPerPage($rowsPerPage, $this->getCurrentUserId());

        // Get total count and paginated users
        $totalUsers = UserManager::countUsers($this->db);
        $offset = ($currentPage - 1) * $rowsPerPage;
        $users = UserManager::getUserDetailList(
            $this->db,
            $this->config->get('ldap', 'enabled', false),
            null,
            $rowsPerPage,
            $offset
        );

        // Create pagination
        $pagination = $paginationService->createPagination($totalUsers, $rowsPerPage, $currentPage);
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $paginationPresenter = new PaginationPresenter($pagination, $baseUrlPrefix . '/users?start={PageNumber}');

        $this->render('users.html', [
            'permissions' => $permissions,
            'perm_templates' => UserManager::listPermissionTemplates($this->db, 'user'),
            'users' => $users,
            'session_userid' => $_SESSION["userid"],
            'perm_add_new' => UserManager::verifyPermission($this->db, 'user_add_new'),
            'pagination' => $paginationPresenter->present(),
            'total_users' => $totalUsers,
            'rows_per_page' => $rowsPerPage,
            'mfa_enabled' => $this->config->get('security', 'mfa.enabled', false),
        ]);
    }
}
