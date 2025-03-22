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
 * Script that handles user editing requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\PasswordPolicyConfig;
use Valitron\Validator;

class EditUserController extends BaseController
{
    private Request $request;
    private PasswordPolicyService $policyService;

    private const VALIDATION_CONFIG = [
        'rules' => [
            'required' => [
                ['username'],
                ['email'],
            ]
        ],
        'labels' => [
            'username' => 'Username',
            'email' => 'Email address'
        ]
    ];
    private readonly UserContextService $userContextService;

    public function __construct(
        array $request
    ) {
        parent::__construct($request);

        $this->request = new Request();
        $this->policyService = new PasswordPolicyService(new PasswordPolicyConfig());
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $editId = $this->request->getQueryParam('id');
        if (!is_numeric($editId)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $this->checkEditPermissions($editId);

        $policyConfig = $this->policyService->getPolicyConfig();

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateUser($editId, $policyConfig);
        } else {
            $this->showUserEditForm($editId, $policyConfig);
        }
    }

    private function updateUser(int $editId, array $policyConfig): void
    {
        if (!$this->validateInput()) {
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        if (!$this->validatePasswordPolicy()) {
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        $params = $this->prepareUserData();
        $legacyUsers = new UserManager($this->db, $this->getConfig());

        if (
            $legacyUsers->edit_user(
                $editId,
                $params['username'],
                $params['fullname'],
                $params['email'],
                $params['perm_templ'],
                $params['description'],
                $params['active'],
                $params['password'],
                $params['use_ldap']
            )
        ) {
            $this->setMessage('users', 'success', _('The user has been updated successfully.'));
            $this->redirect('index.php', ['page' => 'users']);
        } else {
            $this->setMessage('edit_user', 'error', _('The user could not be updated.'));
            $this->showUserEditForm($editId, $policyConfig);
        }
    }

    private function validateInput(): bool
    {
        $validator = new Validator($this->request->getPostParams());
        $validator->rules(self::VALIDATION_CONFIG['rules']);
        $validator->labels(self::VALIDATION_CONFIG['labels']);

        if (!$validator->validate()) {
            $validationErrors = $validator->errors();
            $firstError = reset($validationErrors);
            $errorMessage = is_array($firstError) ? reset($firstError) : $firstError;
            $this->setMessage('edit_user', 'error', $errorMessage);
            return false;
        }

        return true;
    }

    private function validatePasswordPolicy(): bool
    {
        $password = $this->request->getPostParam('password');
        if (empty($password) || $this->request->getPostParam('use_ldap')) {
            return true;
        }

        $policyErrors = $this->policyService->validatePassword($password);
        if (!empty($policyErrors)) {
            $this->setMessage('edit_user', 'error', array_shift($policyErrors));
            return false;
        }

        return true;
    }

    private function checkEditPermissions(int $editId): void
    {
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
        $canEditOwn = UserManager::verify_permission($this->db, 'user_edit_own');
        $canEditOthers = UserManager::verify_permission($this->db, 'user_edit_others');

        if ((!$isOwnProfile || !$canEditOwn) && ($isOwnProfile || !$canEditOthers)) {
            $this->showError(_('You do not have the permission to edit this user.'));
        }
    }

    private function prepareUserData(): array
    {
        return [
            'username' => htmlspecialchars($this->request->getPostParam('username')),
            'fullname' => htmlspecialchars($this->request->getPostParam('fullname')),
            'email' => htmlspecialchars($this->request->getPostParam('email')),
            'description' => htmlspecialchars($this->request->getPostParam('description')),
            'password' => $this->request->getPostParam('password', ''),
            'perm_templ' => $this->request->getPostParam('perm_templ'),
            'active' => $this->request->getPostParam('active') === '1',
            'use_ldap' => $this->request->getPostParam('use_ldap') === '1'
        ];
    }

    public function showUserEditForm(int $editId, array $policyConfig): void
    {
        $user = $this->getUserDetails($editId);
        $permissions = $this->getUserPermissions($editId);

        $this->render('edit_user.html', [
            'edit_id' => $editId,
            'name' => $user['fullname'] ?: $user['username'],
            'user' => $user,
            'session_user_id' => $this->userContextService->getLoggedInUserId(),
            'check' => $user['active'] == "1" ? " CHECKED" : "",
            'edit_templ_perm' => $permissions['edit_templ_perm'],
            'edit_own_perm' => $permissions['edit_own'],
            'perm_passwd_edit_others' => $permissions['passwd_edit_others'],
            'permission_templates' => UserManager::list_permission_templates($this->db),
            'user_permissions' => UserManager::get_permissions_by_template_id($this->db, $user['tpl_id']),
            'ldap_use' => $this->config('ldap_use') && !$permissions['is_admin'],
            'use_ldap_checked' => $user['use_ldap'] ? "checked" : "",
            'password_policy' => $policyConfig,
        ]);
    }

    private function getUserPermissions(int $editId): array
    {
        $isCurrentUser = $this->userContextService->getLoggedInUserId() == $editId;

        return [
            'edit_templ_perm' => UserManager::verify_permission($this->db, 'user_edit_templ_perm'),
            'passwd_edit_others' => UserManager::verify_permission($this->db, 'user_passwd_edit_others'),
            'edit_own' => UserManager::verify_permission($this->db, 'user_edit_own'),
            'is_admin' => Permission::getPermissions($this->db, ['user_is_ueberuser'])['user_is_ueberuser']
                && $isCurrentUser
        ];
    }

    private function getUserDetails(int $editId): array
    {
        $users = UserManager::get_user_detail_list($this->db, $this->config('ldap_use'), $editId);

        if (empty($users)) {
            $this->showError(_('User does not exist.'));
        }

        return $users[0];
    }
}
