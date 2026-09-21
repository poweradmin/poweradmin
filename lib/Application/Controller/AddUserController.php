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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\PasswordGenerationService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserFormMessages;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionTemplateAssignmentGuard;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-user form: guards the template choice, creates the user, assigns groups and mails credentials.
 */
class AddUserController extends BaseController
{
    private PasswordPolicyService $passwordPolicyService;
    private PasswordGenerationService $passwordGenerationService;
    private MailService $mailService;
    private DbPermissionTemplateRepository $permissionTemplateRepository;
    private UserGroupLookupInterface $groupRepository;
    private UserGroupMemberRepositoryInterface $memberRepository;


    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->passwordPolicyService = new PasswordPolicyService($this->config);
        $this->passwordGenerationService = new PasswordGenerationService($this->config);

        // Initialize mail service
        $this->mailService = new MailService($this->config, $this->logger);

        // Initialize permission template repository
        $this->permissionTemplateRepository = $this->services()->permissionTemplateRepository();

        // Initialize group repositories for group membership management
        $this->groupRepository = $this->services()->userGroupRepository();
        $this->memberRepository = $this->services()->userGroupMemberRepository();
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_USER_ADD_NEW, _("You do not have the permission to add a new user."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_user');
        $this->setPageTitle(_('Add user'));

        $policyConfig = $this->passwordPolicyService->getPolicyConfig();

        if ($this->isPost()) {
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

        $userParams = $this->httpRequest->getPostParams();
        $callerId = (int)$this->getCurrentUserId();

        // The template picker is hidden when access templates are disabled or the
        // caller lacks user_edit_templ_perm; those callers get the minimal one.
        $showUserAccessTemplates = $this->config->get('permissions', 'show_user_access_templates', true);
        $canChooseTemplate = $showUserAccessTemplates && $this->hasPermission(Permission::PERM_USER_EDIT_TEMPL_PERM);
        $input = [
            'username' => (string)($userParams['username'] ?? ''),
            'fullname' => (string)($userParams['fullname'] ?? ''),
            'email' => (string)($userParams['email'] ?? ''),
            'description' => (string)($userParams['descr'] ?? ''),
            'active' => ($userParams['active'] ?? '') == 1 ? 1 : 0,
            'use_ldap' => ($userParams['use_ldap'] ?? '') == 1,
            'password' => (string)($userParams['password'] ?? ''),
            'perm_templ' => $canChooseTemplate && ($userParams['perm_templ'] ?? '') !== '' ? $userParams['perm_templ'] : null,
        ];

        // Same gate as the API: the template must stay within the caller's own authority,
        // and an omitted one falls back to the minimal template rather than Administrator.
        $templateError = PermissionTemplateAssignmentGuard::apply(
            $this->services()->permissionService(),
            $this->permissionTemplateRepository->getMinimalPermissionTemplateId('user'),
            $callerId,
            $input,
            null
        );
        if ($templateError !== null) {
            $this->setMessage('add_user', 'error', UserFormMessages::templateAssignmentError($templateError));
            $this->renderAddUserForm($policyConfig);
            return;
        }

        // Handle auto-generated password
        $generatedPassword = '';
        if (!$input['use_ldap'] && $this->httpRequest->getPostParam('auto_generate_password')) {
            $generatedPassword = $this->passwordGenerationService->generatePassword();
            $input['password'] = $generatedPassword;
        }

        $created = $this->services()->userManagementService()->createUser($input);
        if ($created['success']) {
            $newUserId = (int)$created['user_id'];
            $successMessage = _('The user has been created successfully.');

            // Handle group membership assignments
            $groupIds = $this->httpRequest->getPostParam('add_to_groups', []);
            if (is_array($groupIds) && !empty($groupIds)) {
                $this->assignUserToGroups($newUserId, $groupIds, $input['username']);
            }

            // Handle generated password and email sending
            if (!empty($generatedPassword)) {
                $showGeneratedPasswords = $this->config->get('misc', 'show_generated_passwords', true);

                // Display the generated password to the admin if allowed by configuration
                if ($showGeneratedPasswords) {
                    $successMessage .= ' ' . sprintf(_('Generated password: %s'), '<strong>' . $generatedPassword . '</strong>');
                }

                // Send email with credentials if mail is enabled and checkbox is checked
                $mailEnabled = $this->config->get('mail', 'enabled', false);

                if ($mailEnabled && $input['email'] && $this->httpRequest->getPostParam('send_email')) {
                    $emailSent = $this->mailService->sendNewAccountEmail(
                        $input['email'],
                        $input['username'],
                        $generatedPassword,
                        $input['fullname']
                    );

                    if ($emailSent) {
                        $successMessage .= ' ' . _('Login details have been sent to the user via email.');
                    } else {
                        $successMessage .= ' ' . _('NOTE: Failed to send login details via email.');
                    }
                }

                // If password is not shown to admin and not sent by email, inform admin
                if (!$showGeneratedPasswords && !($mailEnabled && $input['email'] && $this->httpRequest->getPostParam('send_email'))) {
                    $successMessage .= ' ' . _('A password was generated but is not displayed for security reasons.');
                }
            }

            $this->services()->auditService()->logUserAdd((string)$input['username'], (string)$input['email']);

            $this->setMessage('users', 'success', $successMessage);
            $this->redirect('/users');
        } else {
            $this->setMessage('add_user', 'error', UserFormMessages::errorMessage($created));
            $this->renderAddUserForm($policyConfig);
        }
    }

    private function renderAddUserForm(array $policyConfig): void
    {
        $user_edit_templ_perm = $this->hasPermission(Permission::PERM_USER_EDIT_TEMPL_PERM);
        $user_templates = $this->permissionTemplateRepository->listPermissionTemplates('user');

        $username = $this->httpRequest->getPostParam('username', '');
        $fullname = $this->httpRequest->getPostParam('fullname', '');
        $email = $this->httpRequest->getPostParam('email', '');

        // Use minimal permission template as default (most secure); preselect
        // nothing rather than falling back to template id 1 (Administrator).
        $defaultTemplateId = $this->permissionTemplateRepository->getMinimalPermissionTemplateId('user') ?? '';
        $perm_templ = $this->httpRequest->getPostParam('perm_templ', (string)$defaultTemplateId);

        $description = $this->httpRequest->getPostParam('descr', '');

        $active_checked = $this->httpRequest->getPostParam('active', '1') === '1' ? 'checked' : '';
        $use_ldap_checked = $this->httpRequest->getPostParam('use_ldap') === '1' ? 'checked' : '';

        // Check if mail functionality is enabled
        $mail_enabled = $this->config->get('mail', 'enabled', false);

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
        $selectedGroups = $this->httpRequest->getPostParam('add_to_groups', []);

        $this->render('add_user.html', [
            'username' => $username,
            'fullname' => $fullname,
            'email' => $email,
            'perm_templ' => $perm_templ,
            'description' => $description,
            'active_checked' => $active_checked,
            'use_ldap_checked' => $use_ldap_checked,
            Permission::PERM_USER_EDIT_TEMPL_PERM => $user_edit_templ_perm,
            'user_templates' => $user_templates,
            'ldap_use' => $this->config->get('ldap', 'enabled', false),
            'password_policy' => $policyConfig,
            'mail_enabled' => $mail_enabled,
            'available_groups' => $availableGroups,
            'selected_groups' => $selectedGroups,
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
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

        $this->setValidationConstraints($constraints);
        $data = $this->httpRequest->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('add_user', 'error', _('Please fill in all required fields correctly.'));
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
        if (!$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
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

        $audit = $this->services()->auditService();
        foreach ($successfulGroups as $groupInfo) {
            $audit->logGroupMembersAdd($groupInfo['id'], $groupInfo['name'], [$username]);
        }
    }
}
