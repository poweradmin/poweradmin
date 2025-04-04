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
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\PasswordGenerationService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Valitron\Validator;

class AddUserController extends BaseController
{
    private PasswordPolicyService $passwordPolicyService;
    private PasswordGenerationService $passwordGenerationService;
    private MailService $mailService;
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
        $configManager = ConfigurationManager::getInstance();
        $this->passwordPolicyService = new PasswordPolicyService($configManager);
        $this->passwordGenerationService = new PasswordGenerationService($configManager);

        // Initialize mail service
        $this->mailService = new MailService($configManager);
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
        $userParams = $this->request->getPostParams();

        // Handle auto-generated password
        $generatedPassword = '';
        if (!$this->request->getPostParam('use_ldap') && $this->request->getPostParam('auto_generate_password')) {
            $generatedPassword = $this->passwordGenerationService->generatePassword();
            $userParams['password'] = $generatedPassword;
        }

        if ($legacyUsers->add_new_user($userParams)) {
            $successMessage = _('The user has been created successfully.');

            // Handle generated password and email sending
            if (!empty($generatedPassword)) {
                $configManager = ConfigurationManager::getInstance();
                $showGeneratedPasswords = $configManager->get('interface', 'show_generated_passwords', false);

                // Display the generated password to the admin if allowed by configuration
                if ($showGeneratedPasswords) {
                    $successMessage .= ' ' . sprintf(_('Generated password: %s'), '<strong>' . $generatedPassword . '</strong>');
                }

                // Send email with credentials if mail is enabled and checkbox is checked
                $configManager = ConfigurationManager::getInstance();
                $mailEnabled = $configManager->get('mail', 'enabled', false);

                if ($mailEnabled && $userParams['email'] && $this->request->getPostParam('send_email')) {
                    $emailSent = $this->mailService->sendNewAccountEmail(
                        $userParams['email'],
                        $userParams['username'],
                        $generatedPassword,
                        $userParams['fullname'] ?? ''
                    );

                    if ($emailSent) {
                        $successMessage .= ' ' . _('Login details have been sent to the user via email.');
                    } else {
                        $successMessage .= ' ' . _('NOTE: Failed to send login details via email.');
                    }
                }

                // If password is not shown to admin and not sent by email, inform admin
                if (!$showGeneratedPasswords && !($mailEnabled && $userParams['email'] && $this->request->getPostParam('send_email'))) {
                    $successMessage .= ' ' . _('A password was generated but is not displayed for security reasons.');
                }
            }

            $this->setMessage('users', 'success', $successMessage);
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

        $active_checked = $this->request->getPostParam('active', '1') === '1' ? 'checked' : '';
        $use_ldap_checked = $this->request->getPostParam('use_ldap') === '1' ? 'checked' : '';

        // Check if mail functionality is enabled
        $configManager = ConfigurationManager::getInstance();
        $mail_enabled = $configManager->get('mail', 'enabled', false);

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
            'mail_enabled' => $mail_enabled,
        ]);
    }

    private function validateInput(): bool
    {
        $validator = new Validator($this->request->getPostParams());
        $validator->rules(self::VALIDATION_CONFIG['rules']);
        $validator->labels(self::VALIDATION_CONFIG['labels']);

        // Add password validation for non-LDAP users (unless auto-generate is checked)
        if (!$this->request->getPostParam('use_ldap') && !$this->request->getPostParam('auto_generate_password')) {
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

        // Skip validation if we're auto-generating a password
        if ($this->request->getPostParam('auto_generate_password')) {
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
