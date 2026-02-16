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
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\PasswordGenerationService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Symfony\Component\Validator\Constraints as Assert;

class AddUserController extends BaseController
{
    private PasswordPolicyService $passwordPolicyService;
    private PasswordGenerationService $passwordGenerationService;
    private MailService $mailService;
    private DbPermissionTemplateRepository $permissionTemplateRepository;
    private DbUserGroupRepository $groupRepository;
    private DbUserGroupMemberRepository $memberRepository;
    private UserContextService $userContextService;
    protected Request $request;


    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $configManager = ConfigurationManager::getInstance();
        $this->passwordPolicyService = new PasswordPolicyService($configManager);
        $this->passwordGenerationService = new PasswordGenerationService($configManager);

        // Initialize mail service
        $this->mailService = new MailService($configManager);

        // Initialize permission template repository
        $this->permissionTemplateRepository = new DbPermissionTemplateRepository($this->db, $this->config);

        // Initialize group repositories for group membership management
        $this->groupRepository = new DbUserGroupRepository($this->db);
        $this->memberRepository = new DbUserGroupMemberRepository($this->db);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->checkPermission('user_add_new', _("You do not have the permission to add a new user."));

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'add_user';

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

        // Validate that the template is a user template
        if (isset($userParams['perm_templ']) && $userParams['perm_templ'] !== '') {
            if (!$this->permissionTemplateRepository->validateTemplateType((int)$userParams['perm_templ'], 'user')) {
                $this->setMessage('add_user', 'error', _('Invalid permission template: must be a user template'));
                $this->renderAddUserForm($policyConfig);
                return;
            }
        }

        // Handle auto-generated password
        $generatedPassword = '';
        if (!$this->request->getPostParam('use_ldap') && $this->request->getPostParam('auto_generate_password')) {
            $generatedPassword = $this->passwordGenerationService->generatePassword();
            $userParams['password'] = $generatedPassword;
        }

        $newUserId = $legacyUsers->addNewUser($userParams);
        if ($newUserId !== false) {
            $successMessage = _('The user has been created successfully.');

            // Handle group membership assignments
            $groupIds = $this->request->getPostParam('add_to_groups', []);
            if (is_array($groupIds) && !empty($groupIds)) {
                $this->assignUserToGroups($newUserId, $groupIds, $userParams['username']);
            }

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
            $this->redirect('/users');
        } else {
            $this->renderAddUserForm($policyConfig);
        }
    }

    private function renderAddUserForm(array $policyConfig): void
    {
        $user_edit_templ_perm = UserManager::verifyPermission($this->db, 'user_edit_templ_perm');
        $user_templates = UserManager::listPermissionTemplates($this->db, 'user');

        $username = $this->request->getPostParam('username', '');
        $fullname = $this->request->getPostParam('fullname', '');
        $email = $this->request->getPostParam('email', '');

        // Use minimal permission template as default (most secure)
        $defaultTemplateId = UserManager::getMinimalPermissionTemplateId($this->db) ?? '1';
        $perm_templ = $this->request->getPostParam('perm_templ', (string)$defaultTemplateId);

        $description = $this->request->getPostParam('descr', '');

        $active_checked = $this->request->getPostParam('active', '1') === '1' ? 'checked' : '';
        $use_ldap_checked = $this->request->getPostParam('use_ldap') === '1' ? 'checked' : '';

        // Check if mail functionality is enabled
        $configManager = ConfigurationManager::getInstance();
        $mail_enabled = $configManager->get('mail', 'enabled', false);

        // Fetch all available groups for group membership assignment
        $allGroups = $this->groupRepository->findAll();
        $availableGroups = array_map(function ($group) {
            return [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription()
            ];
        }, $allGroups);

        // Get previously selected groups (in case of form re-render after validation error)
        $selectedGroups = $this->request->getPostParam('add_to_groups', []);

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
            'ldap_use' => $this->config->get('ldap', 'enabled', false),
            'password_policy' => $policyConfig,
            'mail_enabled' => $mail_enabled,
            'available_groups' => $availableGroups,
            'selected_groups' => $selectedGroups,
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
        ]);
    }

    private function validateInput(): bool
    {
        $constraints = [
            'username' => [
                new Assert\NotBlank()
            ],
            'email' => [
                new Assert\NotBlank(),
                new Assert\Email()
            ]
        ];

        // Add password validation for non-LDAP users (unless auto-generate is checked)
        if (!$this->request->getPostParam('use_ldap') && !$this->request->getPostParam('auto_generate_password')) {
            $constraints['password'] = [
                new Assert\NotBlank()
            ];
        }

        $this->setValidationConstraints($constraints);
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('add_user', 'error', _('Please fill in all required fields correctly.'));
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

    /**
     * Assign the newly created user to selected groups
     *
     * @param int $userId The ID of the newly created user
     * @param array $groupIds Array of group IDs to assign the user to
     * @param string $username The username for logging purposes
     */
    private function assignUserToGroups(int $userId, array $groupIds, string $username): void
    {
        // Only admins can manage group memberships
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            return;
        }

        // Convert to integers
        $groupIds = array_map('intval', $groupIds);

        $membershipService = new GroupMembershipService($this->memberRepository, $this->groupRepository);

        $successfulGroups = [];

        foreach ($groupIds as $groupId) {
            try {
                $membershipService->addUserToGroup($groupId, $userId);

                // Store group info for logging
                $group = $this->groupRepository->findById($groupId);
                if ($group) {
                    $successfulGroups[] = [
                        'id' => $groupId,
                        'name' => $group->getName()
                    ];
                }
            } catch (\Exception $e) {
                // Silently skip failed group assignments (group not found, etc.)
            }
        }

        // Log the additions
        if (!empty($successfulGroups)) {
            $currentUserId = $this->userContextService->getLoggedInUserId();
            $ldapUse = $this->config->get('ldap', 'enabled');
            $currentUsers = UserManager::getUserDetailList($this->db, $ldapUse, $currentUserId);
            $actorUsername = !empty($currentUsers) ? $currentUsers[0]['username'] : "ID: $currentUserId";

            $logger = new \Poweradmin\Infrastructure\Logger\DbGroupLogger($this->db);

            foreach ($successfulGroups as $groupInfo) {
                $logMessage = sprintf(
                    "Added 1 user(s) to group '%s' (ID: %d) by %s: %s",
                    $groupInfo['name'],
                    $groupInfo['id'],
                    $actorUsername,
                    $username
                );
                $logger->doLog($logMessage, $groupInfo['id'], LOG_INFO);
            }
        }
    }
}
