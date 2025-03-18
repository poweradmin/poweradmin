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
 * Script that handles requests to add new users
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
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\PasswordPolicyConfig;
use Valitron\Validator;

class AddUserController extends BaseController
{
    private PasswordPolicyService $passwordPolicyService;
    private Request $request;

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

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->passwordPolicyService = new PasswordPolicyService(new PasswordPolicyConfig());
    }

    public function run(): void
    {
        $this->checkPermission('user_add_new', _("You do not have the permission to add a new user."));

        $policyConfig = $this->passwordPolicyService->getPolicyConfig();

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->addUser($policyConfig);
        } else {
            $this->renderAddUserForm($policyConfig);
        }
    }

    private function addUser(array $policyConfig): void
    {
        if (!$this->validateInput()) {
            $this->renderAddUserForm($policyConfig);
            return;
        }

        if (!$this->validatePasswordPolicy()) {
            $this->renderAddUserForm($policyConfig);
            return;
        }

        $legacyUsers = new UserManager($this->db, $this->getConfig());
        if ($legacyUsers->add_new_user($this->request->getPostParams())) {
            $this->setMessage('users', 'success', _('The user has been created successfully.'));
            $this->redirect('index.php', ['page' => 'users']);
        } else {
            $this->renderAddUserForm($policyConfig);
        }
    }

    private function renderAddUserForm(array $policyConfig): void
    {
        $user_edit_templ_perm = UserManager::verify_permission($this->db, 'user_edit_templ_perm');
        $user_templates = UserManager::list_permission_templates($this->db);

        $username = $this->request->getPostParam('username', '');
        $fullname = $this->request->getPostParam('fullname', '');
        $email = $this->request->getPostParam('email', '');
        $perm_templ = $this->request->getPostParam('perm_templ', '1');
        $description = $this->request->getPostParam('descr', '');

        $active_checked = $this->request->getPostParam('active') === '1' ? 'checked' : '';
        $use_ldap_checked = $this->request->getPostParam('use_ldap', '1') === '1' ? 'checked' : '';

        $this->render('add_user.html', [
            'username' => $username,
            'fullname' => $fullname,
            'email' => $email,
            'perm_templ' => $perm_templ,
            'description' => $description,
            'active_checked' => $active_checked,
            'use_ldap_checked' => $use_ldap_checked,
            'user_edit_templ_perm' => $user_edit_templ_perm,
            'user_templates' => $user_templates,
            'ldap_use' => $this->config('ldap_use'),
            'password_policy' => $policyConfig,
        ]);
    }

    private function validateInput(): bool {
        $validator = new Validator($this->request->getPostParams());
        $validator->rules(self::VALIDATION_CONFIG['rules']);
        $validator->labels(self::VALIDATION_CONFIG['labels']);

        // Add password validation for non-LDAP users
        if (!$this->request->getPostParam('use_ldap')) {
            $validator->rule('required', 'password');
        }

        if (!$validator->validate()) {
            $validationErrors = $validator->errors();
            $firstError = reset($validationErrors);
            $errorMessage = is_array($firstError) ? reset($firstError) : $firstError;
            $this->setMessage('add_user', 'error', $errorMessage);
            return false;
        }

        return true;
    }

    private function validatePasswordPolicy(): bool
    {
        if ($this->request->getPostParam('use_ldap')) {
            return true;
        }

        $password = $this->request->getPostParam('password');
        $policyErrors = $this->passwordPolicyService->validatePassword($password);

        if (!empty($policyErrors)) {
            $this->setMessage('add_user', 'error', array_shift($policyErrors));
            return false;
        }

        return true;
    }
}
