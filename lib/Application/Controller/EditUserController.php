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
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\Validator\Constraints as Assert;

class EditUserController extends BaseController
{
    protected Request $request;
    private PasswordPolicyService $policyService;
    private DbPermissionTemplateRepository $permissionTemplateRepository;
    private readonly UserContextService $userContextService;
    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;
    private AuditService $auditService;

    public function __construct(
        array $request
    ) {
        parent::__construct($request);

        $this->request = new Request();
        $this->policyService = new PasswordPolicyService();
        $this->userContextService = new UserContextService();
        $this->permissionTemplateRepository = $this->createPermissionTemplateRepository();
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->auditService = new AuditService($this->db);
    }

    public function run(): void
    {
        $editId = $this->getSafeRequestValue('id');
        if (!is_numeric($editId)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        $editId = (int)$editId;

        $this->checkEditPermissions($editId);

        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit_user');
        $this->setPageTitle(_('Edit User'));

        $policyConfig = $this->policyService->getPolicyConfig();

        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Check if this is a group addition request
            $action = $this->request->getPostParam('action');
            if ($action === 'add_groups') {
                $this->handleAddToGroups($editId);
            } else {
                $this->updateUser($editId, $policyConfig);
            }
        } else {
            $this->showUserEditForm($editId, $policyConfig);
        }
    }

    private function updateUser(int $editId, array $policyConfig): void
    {
        if (!$this->validateInput($editId)) {
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        if (!$this->validatePasswordPolicy($editId)) {
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        try {
            $params = $this->prepareUserData($editId);
        } catch (\InvalidArgumentException $e) {
            $this->setMessage('edit_user', 'error', $e->getMessage());
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        $legacyUsers = new UserManager($this->db, $this->getConfig());

        // Fetch old permission template before edit for audit logging
        $stmt = $this->db->prepare("SELECT perm_templ FROM users WHERE id = :id");
        $stmt->execute([':id' => $editId]);
        $oldPermTempl = (int)($stmt->fetchColumn() ?: 0);

        if (
            $legacyUsers->editUser(
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
            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user:%s operation:edit_user target_user:%s perm_template:%s auth_type:%s',
                $this->ipAddressRetriever->getClientIp(),
                $this->userContextService->getLoggedInUsername(),
                $params['username'],
                $params['perm_templ'],
                $params['use_ldap'] ? 'ldap' : 'sql'
            ));

            if ($oldPermTempl !== (int)$params['perm_templ']) {
                $this->auditService->logPermTemplateChange(
                    $params['username'],
                    $oldPermTempl,
                    (int)$params['perm_templ']
                );
            }

            $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
            $canViewAllUsers = $this->hasPermission('user_view_others');
            $canEditAllUsers = $this->hasPermission('user_edit_others');

            if ($isOwnProfile && !$canViewAllUsers && !$canEditAllUsers) {
                // Limited user edited their own profile - redirect to home
                $this->setMessage('index', 'success', _('Your profile has been updated successfully.'));
                $this->redirect('/');
            } else {
                // User with admin permissions - redirect to users list
                $this->setMessage('users', 'success', _('The user has been updated successfully.'));
                $this->redirect('/users');
            }
        } else {
            $this->setMessage('edit_user', 'error', _('The user could not be updated.'));
            $this->showUserEditForm($editId, $policyConfig);
        }
    }

    private function validateInput(int $editId): bool
    {
        $constraints = [
            'username' => [
                new Assert\NotBlank()
            ]
        ];

        // External-auth users have an IdP-managed (read-only, possibly empty) email,
        // so don't enforce the email constraint here - the submitted value is ignored
        // anyway and requiring it would block all edits when the IdP supplied none.
        // A user being converted to a local account (LDAP unchecked) is no longer
        // managed, so the email requirement applies again.
        $user = $this->getUserDetails($editId);
        $auth = self::resolveAuthFields(
            $user,
            $this->isRestrictedSelfEdit($editId),
            '',
            $this->request->getPostParam('use_ldap') === '1'
        );
        if (!self::isIdpManaged($user['auth_type'] ?? null, $auth['use_ldap'] && $this->isLdapSyncEnabled())) {
            $constraints['email'] = [
                new Assert\NotBlank(),
                new Assert\Email()
            ];
        }

        $this->setValidationConstraints($constraints);
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('edit_user', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }

    private function validatePasswordPolicy(int $editId): bool
    {
        $password = $this->request->getPostParam('password');
        if (empty($password)) {
            return true;
        }

        // Judge by the use_ldap value that will be persisted, not the raw
        // posted flag - a self-editor must not dodge the policy with use_ldap=1.
        $user = $this->getUserDetails($editId);
        $auth = self::resolveAuthFields(
            $user,
            $this->isRestrictedSelfEdit($editId),
            '',
            $this->request->getPostParam('use_ldap') === '1'
        );
        if ($auth['use_ldap']) {
            return true;
        }
        if (in_array($user['auth_type'] ?? 'sql', UserContextService::EXTERNAL_AUTH_METHODS, true)) {
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
        $canEditOwn = $this->hasPermission('user_edit_own');
        $canEditOthers = $this->hasPermission('user_edit_others');

        if ((!$isOwnProfile || !$canEditOwn) && ($isOwnProfile || !$canEditOthers)) {
            $this->showError(_('You do not have the permission to edit this user.'));
        }

        // Prevent non-superusers from editing superuser accounts (privilege escalation protection)
        $targetIsSuperuser = $this->createPermissionService()->isAdmin($editId);
        $currentIsSuperuser = $this->hasPermission('user_is_ueberuser');

        if ($targetIsSuperuser && !$currentIsSuperuser) {
            $this->showError(_('You do not have permission to edit a superuser account.'));
        }
    }

    /**
     * Whether this edit is a limited user maintaining their own account, in
     * which case auth-critical fields (username, LDAP flag) are not editable.
     */
    private function isRestrictedSelfEdit(int $editId): bool
    {
        return $editId === $this->userContextService->getLoggedInUserId()
            && !$this->hasPermission('user_edit_others');
    }

    private function prepareUserData(int $editId): array
    {
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
        $canEditOthers = $this->hasPermission('user_edit_others');
        $userData = null;

        // Force active state to true if user is editing their own profile
        $active = $isOwnProfile ? true : $this->request->getPostParam('active') === '1';

        // Determine permission template
        $permTempl = $this->request->getPostParam('perm_templ');

        // When user permission templates are hidden, always preserve existing template
        $showUserAccessTemplates = $this->config->get('permissions', 'show_user_access_templates', true);
        if (!$showUserAccessTemplates) {
            $userData = $this->getUserDetails($editId);
            $permTempl = $userData['tpl_id'];
        }

        // If editing own profile and not an admin, maintain existing template
        if ($isOwnProfile && !$canEditOthers) {
            $userData = $userData ?? $this->getUserDetails($editId);
            $permTempl = $userData['tpl_id'];
        }

        // Validate that the template is a user template (skip if maintaining existing template)
        if ($permTempl && (!$isOwnProfile || $canEditOthers)) {
            if (!$this->permissionTemplateRepository->validateTemplateType((int)$permTempl, 'user')) {
                throw new \InvalidArgumentException(_('Invalid permission template: must be a user template'));
            }
        }

        // Keep stored username and LDAP flag on self-edit (#1327)
        $userData = $userData ?? $this->getUserDetails($editId);
        $auth = self::resolveAuthFields(
            $userData,
            $isOwnProfile && !$canEditOthers,
            htmlspecialchars($this->request->getPostParam('username')),
            $this->request->getPostParam('use_ldap') === '1'
        );

        // OIDC/SAML users have their identity fields owned by the IdP
        // (overwritten on the next sync), so ignore any submitted changes to them.
        $identity = self::resolveIdentityFields(
            $userData,
            htmlspecialchars($this->request->getPostParam('fullname')),
            htmlspecialchars($this->request->getPostParam('email')),
            $auth['use_ldap'] && $this->isLdapSyncEnabled()
        );

        return [
            'username' => $auth['username'],
            'fullname' => $identity['fullname'],
            'email' => $identity['email'],
            'description' => htmlspecialchars($this->request->getPostParam('description')),
            'password' => $this->request->getPostParam('password', ''),
            'perm_templ' => $permTempl,
            'active' => $active,
            'use_ldap' => $auth['use_ldap']
        ];
    }

    /**
     * Whether a user's identity fields (fullname/email) are owned by an external
     * identity provider, and so must stay read-only.
     *
     * OIDC/SAML sync fullname/email on login and would revert local edits.
     * LDAP accounts are IdP-managed only while LDAP stays enabled for the user
     * AND ldap.sync_user_info is on; callers pass that combined state.
     */
    public static function isIdpManaged(?string $currentAuthMethod, bool $ldapSynced = false): bool
    {
        return in_array($currentAuthMethod, ['oidc', 'saml'], true)
            || ($currentAuthMethod === 'ldap' && $ldapSynced);
    }

    private function isLdapSyncEnabled(): bool
    {
        return (bool)$this->config->get('ldap', 'sync_user_info', false);
    }

    /**
     * Resolve the fullname/email to persist for a user edit.
     *
     * When the account is IdP-managed, the identity provider owns these fields
     * (overwritten on the next sync), so the stored values are kept and
     * submitted changes discarded. Otherwise the submitted values are used.
     *
     * @param array $userData The persisted user record (expects auth_type, fullname, email)
     * @param string $submittedFullname Fullname from the form
     * @param string $submittedEmail Email from the form
     * @return array{fullname: string, email: string}
     */
    /**
     * Resolve the username/use_ldap to persist for a user edit (#1327).
     *
     * On a restricted self-edit (own profile without user_edit_others) the
     * stored username and LDAP flag win over the submitted values - they are
     * auth-critical, not self-service. A row without a use_ldap column
     * (LDAP disabled in config) keeps the submitted flag, as before.
     *
     * @return array{username: string, use_ldap: bool}
     */
    public static function resolveAuthFields(array $userData, bool $restrictedSelfEdit, string $submittedUsername, bool $submittedUseLdap): array
    {
        if (!$restrictedSelfEdit) {
            return [
                'username' => $submittedUsername,
                'use_ldap' => $submittedUseLdap,
            ];
        }

        return [
            'username' => (string)($userData['username'] ?? ''),
            'use_ldap' => array_key_exists('use_ldap', $userData)
                ? (bool)$userData['use_ldap']
                : $submittedUseLdap,
        ];
    }

    public static function resolveIdentityFields(array $userData, string $submittedFullname, string $submittedEmail, bool $ldapSynced = false): array
    {
        if (self::isIdpManaged($userData['auth_type'] ?? null, $ldapSynced)) {
            return [
                'fullname' => (string)($userData['fullname'] ?? ''),
                'email' => (string)($userData['email'] ?? ''),
            ];
        }

        return [
            'fullname' => $submittedFullname,
            'email' => $submittedEmail,
        ];
    }

    public function showUserEditForm(int $editId, array $policyConfig): void
    {
        $user = $this->getUserDetails($editId);
        $permissions = $this->getUserPermissions($editId);

        // Check if password changes should be disabled for external auth users
        $isExternalAuth = in_array($user['auth_type'] ?? 'sql', UserContextService::EXTERNAL_AUTH_METHODS, true);

        // Fetch user's group memberships
        $groupMemberRepo = $this->createUserGroupMemberRepository();
        $userGroupRepo = $this->createUserGroupRepository();

        $memberships = $groupMemberRepo->findByUserId($editId);
        $isAdmin = $this->hasPermission('user_is_ueberuser');
        $currentUserId = $this->userContextService->getLoggedInUserId();
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($currentUserId);

        // Get list of group IDs user is already a member of
        $memberGroupIds = array_map(function ($membership) {
            return $membership->getGroupId();
        }, $memberships);

        $userGroups = array_map(function ($membership) use ($allGroups) {
            $groupId = $membership->getGroupId();
            foreach ($allGroups as $group) {
                if ($group->getId() === $groupId) {
                    return [
                        'id' => $group->getId(),
                        'name' => $group->getName(),
                        'description' => $group->getDescription()
                    ];
                }
            }
            return null;
        }, $memberships);
        $userGroups = array_filter($userGroups); // Remove nulls

        // Get available groups (groups user is NOT a member of)
        $availableGroups = array_filter($allGroups, function ($group) use ($memberGroupIds) {
            return !in_array($group->getId(), $memberGroupIds);
        });

        $availableGroupsArray = array_map(function ($group) {
            return [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription()
            ];
        }, $availableGroups);

        $this->render('edit_user.html', [
            'edit_id' => $editId,
            'name' => $user['fullname'] ?: $user['username'],
            'user' => $user,
            'session_user_id' => $this->userContextService->getLoggedInUserId(),
            'check' => $user['active'] == "1" ? " CHECKED" : "",
            'edit_templ_perm' => $permissions['edit_templ_perm'],
            'edit_own_perm' => $permissions['edit_own'],
            'perm_passwd_edit_others' => $permissions['passwd_edit_others'],
            'permission_templates' => $this->permissionTemplateRepository->listPermissionTemplates('user'),
            'user_permissions' => $this->permissionTemplateRepository->getPermissionsByTemplateId((int)$user['tpl_id']),
            'ldap_use' => $this->config->get('ldap', 'enabled', false) && !$permissions['is_admin'],
            'use_ldap_checked' => $user['use_ldap'] ? "checked" : "",
            'is_external_auth' => $isExternalAuth,
            'is_identity_readonly' => self::isIdpManaged($user['auth_type'] ?? 'sql', $this->isLdapSyncEnabled()),
            'restricted_self_edit' => $this->isRestrictedSelfEdit($editId),
            'password_policy' => $policyConfig,
            'user_groups' => $userGroups,
            'available_groups' => $availableGroupsArray,
            'perm_is_godlike' => $this->hasPermission('user_is_ueberuser'),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
        ]);
    }

    private function getUserPermissions(int $editId): array
    {
        $isCurrentUser = $this->userContextService->getLoggedInUserId() == $editId;

        return [
            'edit_templ_perm' => $this->hasPermission('user_edit_templ_perm'),
            'passwd_edit_others' => $this->hasPermission('user_passwd_edit_others'),
            'edit_own' => $this->hasPermission('user_edit_own'),
            'is_admin' => Permission::getPermissions($this->db, ['user_is_ueberuser'])['user_is_ueberuser']
                && $isCurrentUser
        ];
    }

    private function handleAddToGroups(int $userId): void
    {
        // Only admins can manage group memberships
        if (!$this->hasPermission('user_is_ueberuser')) {
            $this->setMessage('edit_user', 'error', _('You do not have permission to manage group memberships.'));
            $this->showUserEditForm($userId, $this->policyService->getPolicyConfig());
            return;
        }

        $groupIds = $this->request->getPostParam('add_to_groups', []);

        if (!is_array($groupIds) || empty($groupIds)) {
            $this->setMessage('edit_user', 'warning', _('Please select at least one group.'));
            $this->showUserEditForm($userId, $this->policyService->getPolicyConfig());
            return;
        }

        // Convert to integers
        $groupIds = array_map('intval', $groupIds);

        $groupRepository = $this->createUserGroupRepository();
        $memberRepository = $this->createUserGroupMemberRepository();
        $membershipService = new GroupMembershipService($memberRepository, $groupRepository);

        // Get target user details for logging
        $targetUser = $this->getUserDetails($userId);
        $targetUsername = $targetUser['username'];

        $successCount = 0;
        $failedCount = 0;
        $successfulGroups = [];

        foreach ($groupIds as $groupId) {
            try {
                $membershipService->addUserToGroup($groupId, $userId);
                $successCount++;

                // Store both ID and name for accurate logging
                $group = $groupRepository->findById($groupId);
                if ($group) {
                    $successfulGroups[] = [
                        'id' => $groupId,
                        'name' => $group->getName()
                    ];
                }
            } catch (\Exception $e) {
                $failedCount++;
            }
        }

        if ($successCount > 0) {
            $message = sprintf(
                ngettext(
                    'User added to %d group successfully.',
                    'User added to %d groups successfully.',
                    $successCount
                ),
                $successCount
            );
            $this->setMessage('edit_user', 'success', $message);

            // Log the additions for each group
            foreach ($successfulGroups as $groupInfo) {
                $logMessage = sprintf(
                    "client_ip:%s user:%s operation:add_members group:%s group_id:%d count:1 members:%s",
                    $this->ipAddressRetriever->getClientIp(),
                    $this->userContextService->getLoggedInUsername(),
                    str_replace(' ', '_', $groupInfo['name']),
                    $groupInfo['id'],
                    $targetUsername
                );
                $this->auditLogger->logGroupInfo($logMessage, $groupInfo['id']);
            }
        }

        if ($failedCount > 0) {
            $message = sprintf(
                ngettext(
                    'Failed to add user to %d group (already a member or group not found).',
                    'Failed to add user to %d groups (already a member or groups not found).',
                    $failedCount
                ),
                $failedCount
            );
            $this->setMessage('edit_user', 'warning', $message);
        }

        // Redirect back to edit page to show updated memberships
        $this->redirect("/users/$userId/edit");
    }

    private function getUserDetails(int $editId): array
    {
        $users = $this->createUserRepository()->getUserDetailList($this->config->get('ldap', 'enabled', false), null, $editId);

        if (empty($users)) {
            $this->showError(_('User does not exist.'));
        }

        return $users[0];
    }
}
