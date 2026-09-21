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

namespace Poweradmin\Application\Controller\User;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\UserCommandFactory;
use Poweradmin\Application\Service\UserFormMessages;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionTemplateAssignmentGuard;
use Poweradmin\Domain\Service\Auth\SelfEditFieldGuard;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Renders the users list and saves the inline edits submitted from it.
 */
class UsersController extends BaseController
{

    public function run(): void
    {
        // Check if user has permission to view or edit other users before processing
        $canViewOthers = $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS);
        $canEditOthers = $this->hasPermission(Permission::PERM_USER_EDIT_OTHERS);

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
            $this->updateUsers();
        }
        $this->showUsers();
    }

    private function updateUsers(): void
    {
        $success = false;
        $blocked = false;
        $currentIsSuperuser = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $permissionService = $this->services()->permissionService();
        foreach ($this->httpRequest->getPostParam('user') as $user) {
            if (!is_array($user)) {
                continue;
            }
            // A delegated admin (non-ueberuser) must not modify a superuser account;
            // untouched superuser rows posted by the bulk form are skipped silently.
            if (!$currentIsSuperuser && $permissionService->isAdmin((int)$user['uid'])) {
                if ($this->superuserRowEdited($user)) {
                    $blocked = true;
                }
                continue;
            }
            if ($this->updateUserRow($user)) {
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

    /**
     * Save one row of the bulk edit form. Fields the caller may not change are
     * left out so the stored values stay; refusals are flashed and the row skipped.
     *
     * @param array<string, mixed> $posted
     */
    private function updateUserRow(array $posted): bool
    {
        $callerId = (int)$this->getCurrentUserId();
        $targetId = (int)($posted['uid'] ?? 0);
        if (!$this->services()->apiPermissionService()->canEditUser($callerId, $targetId)) {
            $this->setMessage('users', 'error', _('You do not have the permission to edit this user.'));
            return false;
        }

        // The same address rule as the single-user form
        $email = (string)($posted['email'] ?? '');
        $this->setValidationConstraints(['email' => [new Assert\NotBlank(), new Assert\Email()]]);
        if (!$this->doValidateRequest(['email' => $email])) {
            $this->setMessage('users', 'error', _('Enter a valid email address.'));
            return false;
        }

        $input = [
            'username' => (string)($posted['username'] ?? ''),
            'fullname' => (string)($posted['fullname'] ?? ''),
            'email' => $email,
            'active' => ($posted['active'] ?? '') == 'on' ? 1 : 0,
        ];
        // Auth-critical fields are not self-service (#1327), as on the single-user form
        if ($targetId === $callerId && !$this->hasPermission(Permission::PERM_USER_EDIT_OTHERS)) {
            $input = array_diff_key($input, array_flip(SelfEditFieldGuard::RESTRICTED_FIELDS));
        }
        if ($this->hasPermission(Permission::PERM_USER_EDIT_TEMPL_PERM) && isset($posted['templ_id'])) {
            $input['perm_templ'] = $posted['templ_id'];
            $templateError = PermissionTemplateAssignmentGuard::apply($this->services()->permissionService(), null, $callerId, $input, $targetId);
            if ($templateError !== null) {
                $this->setMessage('users', 'error', UserFormMessages::templateAssignmentError($templateError));
                return false;
            }
        }

        $updated = UserCommandFactory::update($input);
        if (!is_array($updated)) {
            $updated = $this->services()->userManagementService()->updateUser($targetId, $updated);
        }
        if (!$updated['success']) {
            $this->setMessage('users', 'error', UserFormMessages::errorMessage($updated));
            return false;
        }

        return true;
    }

    private function superuserRowEdited(array $posted): bool
    {
        $userRepository = $this->services()->userRepository();
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
        $permissions = $this->services()->permissionService()->getPermissionFlags(
            (int)$this->getCurrentUserId(),
            [
                Permission::PERM_USER_VIEW_OTHERS,
                Permission::PERM_USER_EDIT_OWN,
                Permission::PERM_USER_EDIT_OTHERS,
                Permission::PERM_USER_EDIT_TEMPL_PERM,
                Permission::PERM_USER_IS_UEBERUSER
            ]
        );

        $currentPage = $this->httpRequest->getPage();
        $rowsPerPage = $this->resolveRowsPerPage(50);

        // Get total count and paginated users; both restricted to the user's own
        // account when they lack the permission to view other users
        $userRepository = $this->services()->userRepository();
        $restrictToUserId = $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS) ? null : ($this->getCurrentUserId() ?? 0);
        // ?search[]=x arrives as an array; treat anything non-string as no filter.
        $searchParam = $this->httpRequest->getQueryParam('search', '');
        $searchTerm = is_string($searchParam) ? trim($searchParam) : '';
        $totalUsers = $userRepository->getTotalUserCount($restrictToUserId, $searchTerm);
        $offset = ($currentPage - 1) * $rowsPerPage;
        $users = $userRepository->getUserDetailList(
            $this->config->get('ldap', 'enabled', false),
            $restrictToUserId,
            null,
            $rowsPerPage,
            $offset,
            $searchTerm
        );

        // Row flags follow the rule updateUserRow() applies, so a delegated admin
        // sees a ueberuser row read-only instead of a refusal on save
        $callerId = (int)$this->getCurrentUserId();
        $apiPermissions = $this->services()->apiPermissionService();
        $this->services()->permissionService()->primeAdminFlags(array_map(static fn(array $user): int => (int)$user['uid'], $users));
        foreach ($users as &$user) {
            $user['can_edit'] = $apiPermissions->canEditUser($callerId, (int)$user['uid']);
            $user['can_change_template'] = $user['can_edit'] && $permissions[Permission::PERM_USER_EDIT_TEMPL_PERM];
        }
        unset($user);

        $this->render('users.html', [
            'permissions' => $permissions,
            'perm_templates' => $this->services()->permissionTemplateRepository()->listPermissionTemplates('user'),
            'users' => $users,
            'session_userid' => $this->getCurrentUserId(),
            'perm_add_new' => $this->hasPermission(Permission::PERM_USER_ADD_NEW),
            'perm_is_godlike' => $permissions[Permission::PERM_USER_IS_UEBERUSER],
            'perm_user_logs_view' => $this->hasPermission(Permission::PERM_USER_LOGS_VIEW),
            'dblog_use' => $this->config->get('logging', 'database_enabled', false),
            ...$this->paginationVariables($totalUsers, $rowsPerPage, '/users?start={PageNumber}', ['search' => $searchTerm]),
            'total_users' => $totalUsers,
            'search_term' => $searchTerm,
            'rows_per_page' => $rowsPerPage,
            'mfa_enabled' => $this->config->get('security', 'mfa.enabled', false),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
        ]);
    }
}
