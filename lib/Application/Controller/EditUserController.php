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
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserFormMessages;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\PermissionTemplateAssignmentGuard;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Domain\Enum\AuthMethod;

/**
 * Handles the edit-user form: updates the profile, password, template and group memberships of a user.
 */
class EditUserController extends BaseController
{
    private PasswordPolicyService $policyService;
    private DbPermissionTemplateRepository $permissionTemplateRepository;
    private readonly UserContextService $userContextService;
    private AuditService $auditService;

    public function __construct(
        array $request
    ) {
        parent::__construct($request);
        $this->policyService = new PasswordPolicyService();
        $this->userContextService = new UserContextService();
        $this->permissionTemplateRepository = $this->createPermissionTemplateRepository();
        $this->auditService = $this->createAuditService();
    }

    public function run(): void
    {
        $editId = $this->requireNumericParam('id');

        $this->checkEditPermissions($editId);

        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit_user');
        $this->setPageTitle(_('Edit User'));

        $policyConfig = $this->policyService->getPolicyConfig();

        if ($this->isPost()) {
            $this->validateCsrfToken();

            // Check if this is a group addition request
            $action = $this->httpRequest->getPostParam('action');
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
        $stored = $this->getUserDetails($editId);
        if (!$this->validateInput($editId, $stored)) {
            $this->showUserEditForm($editId, $policyConfig);
            return;
        }

        $callerId = (int)$this->getCurrentUserId();
        $input = $this->prepareUserData($editId, $stored, $callerId);

        // Same gate as the API: a chosen template must stay within the caller's own authority.
        if (array_key_exists('perm_templ', $input)) {
            $templateError = PermissionTemplateAssignmentGuard::apply($this->createPermissionService(), null, $callerId, $input, $editId);
            if ($templateError !== null) {
                $this->setMessage('edit_user', 'error', UserFormMessages::templateAssignmentError($templateError));
                $this->showUserEditForm($editId, $policyConfig);
                return;
            }
        }

        $updated = $this->createUserManagementService()->updateUser($editId, $input);
        if ($updated['success']) {
            $username = (string)($input['username'] ?? $stored['username']);
            $oldPermTempl = (int)$stored['tpl_id'];
            $newPermTempl = (int)($input['perm_templ'] ?? $oldPermTempl);
            $this->auditService->logUserEdit($username, $newPermTempl, $this->useLdapAfterEdit($editId, $stored) ? 'ldap' : 'sql');

            if ($oldPermTempl !== $newPermTempl) {
                $this->auditService->logPermTemplateChange($username, $oldPermTempl, $newPermTempl);
            }

            $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
            $canViewAllUsers = $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS);
            $canEditAllUsers = $this->hasPermission(Permission::PERM_USER_EDIT_OTHERS);

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
            $this->setMessage('edit_user', 'error', UserFormMessages::errorMessage($updated));
            $this->showUserEditForm($editId, $policyConfig);
        }
    }

    /**
     * @param array<string, mixed> $stored The user's row as getUserDetails() returns it
     */
    private function validateInput(int $editId, array $stored): bool
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
        if (!self::isIdpManaged($stored['auth_type'] ?? null, $this->useLdapAfterEdit($editId, $stored) && $this->isLdapSyncEnabled())) {
            $constraints['email'] = [
                new Assert\NotBlank(),
                new Assert\Email()
            ];
        }

        $this->setValidationConstraints($constraints);
        $data = $this->httpRequest->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('edit_user', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }

    private function checkEditPermissions(int $editId): void
    {
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
        $canEditOwn = $this->hasPermission(Permission::PERM_USER_EDIT_OWN);
        $canEditOthers = $this->hasPermission(Permission::PERM_USER_EDIT_OTHERS);

        if ((!$isOwnProfile || !$canEditOwn) && ($isOwnProfile || !$canEditOthers)) {
            $this->showError(_('You do not have the permission to edit this user.'));
        }

        // Prevent non-superusers from editing superuser accounts (privilege escalation protection)
        $targetIsSuperuser = $this->createPermissionService()->isAdmin($editId);
        $currentIsSuperuser = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);

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
            && !$this->hasPermission(Permission::PERM_USER_EDIT_OTHERS);
    }

    /**
     * Whether the form offered the LDAP checkbox for this edit: it is hidden while
     * LDAP is off or on a superuser's own profile, and disabled on a restricted
     * self-edit (#1327). An unchecked box and a missing one post the same nothing,
     * so only a shown, enabled box counts as the user's answer.
     */
    private function ldapControlEditable(int $editId): bool
    {
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();

        return $this->config->get('ldap', 'enabled', false)
            && !($isOwnProfile && $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER))
            && !$this->isRestrictedSelfEdit($editId);
    }

    /**
     * The LDAP flag the account will carry after this edit: the stored one unless
     * the form offered the choice.
     *
     * @param array<string, mixed> $stored
     */
    private function useLdapAfterEdit(int $editId, array $stored): bool
    {
        if ($this->ldapControlEditable($editId)) {
            return $this->httpRequest->getPostParam('use_ldap') === '1';
        }

        // With LDAP switched off the row is loaded without use_ldap; auth_type still says
        return (bool)($stored['use_ldap'] ?? (($stored['auth_type'] ?? '') === 'ldap'));
    }

    /**
     * The fields this edit may write, from the form and the caller's rights.
     * Fields the caller may not change are left out so the stored values stay.
     *
     * @param array<string, mixed> $stored The user's row as getUserDetails() returns it
     * @return array<string, mixed>
     */
    private function prepareUserData(int $editId, array $stored, int $callerId): array
    {
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
        $canEditOthers = $this->hasPermission(Permission::PERM_USER_EDIT_OTHERS);
        $restrictedSelfEdit = $isOwnProfile && !$canEditOthers;
        $useLdap = $this->useLdapAfterEdit($editId, $stored);

        // OIDC/SAML users have their identity fields owned by the IdP
        // (overwritten on the next sync), so ignore any submitted changes to them.
        $identity = self::resolveIdentityFields(
            $stored,
            htmlspecialchars($this->httpRequest->getPostParam('fullname')),
            htmlspecialchars($this->httpRequest->getPostParam('email')),
            $useLdap && $this->isLdapSyncEnabled()
        );

        $input = [
            'fullname' => $identity['fullname'],
            'email' => $identity['email'],
            'description' => htmlspecialchars($this->httpRequest->getPostParam('description')),
        ];

        // Username and the LDAP flag are auth-critical, not self-service (#1327)
        if (!$restrictedSelfEdit) {
            $input['username'] = htmlspecialchars($this->httpRequest->getPostParam('username'));
        }
        if ($this->ldapControlEditable($editId)) {
            $input['use_ldap'] = $useLdap;
        }

        // Nobody deactivates themselves from their own profile
        if (!$isOwnProfile) {
            $input['active'] = $this->httpRequest->getPostParam('active') === '1' ? 1 : 0;
        }

        // Changing another user's password needs user_passwd_edit_others; without it
        // the posted password is ignored and the other fields still save. An LDAP
        // account has no local password to set.
        $password = (string)$this->httpRequest->getPostParam('password', '');
        if ($password !== '' && !$useLdap && $this->createApiPermissionService()->canEditUserPassword($callerId, $editId)) {
            $input['password'] = $password;
        }

        // The template is written only by callers who may pick one, and never on a
        // limited self-edit or while the picker is hidden.
        $permTempl = $this->httpRequest->getPostParam('perm_templ');
        $mayPickTemplate = $this->hasPermission(Permission::PERM_USER_EDIT_TEMPL_PERM)
            && $this->config->get('permissions', 'show_user_access_templates', true)
            && !($isOwnProfile && !$canEditOthers);
        if ($mayPickTemplate && $permTempl !== null && $permTempl !== '') {
            $input['perm_templ'] = $permTempl;
        }

        return $input;
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
        return AuthMethod::fromDb($currentAuthMethod)->isIdpManaged($ldapSynced);
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
        $isExternalAuth = AuthMethod::fromDb($user['auth_type'] ?? null)->isExternal();

        // Fetch user's group memberships
        $groupMemberRepo = $this->createUserGroupMemberRepository();
        $userGroupRepo = $this->createUserGroupRepository();

        $memberships = $groupMemberRepo->findByUserId($editId);
        $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
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
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'can_manage_users' => $this->createPermissionService()->canManageUsers((int)$this->userContextService->getLoggedInUserId()),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
        ]);
    }

    private function getUserPermissions(int $editId): array
    {
        $isCurrentUser = $this->userContextService->getLoggedInUserId() == $editId;

        return [
            'edit_templ_perm' => $this->hasPermission(Permission::PERM_USER_EDIT_TEMPL_PERM),
            'passwd_edit_others' => $this->hasPermission(Permission::PERM_USER_PASSWD_EDIT_OTHERS),
            'edit_own' => $this->hasPermission(Permission::PERM_USER_EDIT_OWN),
            'is_admin' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)
                && $isCurrentUser
        ];
    }

    private function handleAddToGroups(int $userId): void
    {
        // Only admins can manage group memberships
        if (!$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            $this->setMessage('edit_user', 'error', _('You do not have permission to manage group memberships.'));
            $this->showUserEditForm($userId, $this->policyService->getPolicyConfig());
            return;
        }

        $groupIds = $this->httpRequest->getPostParam('add_to_groups', []);

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

            foreach ($successfulGroups as $groupInfo) {
                $this->auditService->logGroupMembersAdd($groupInfo['id'], $groupInfo['name'], [(string)$targetUsername]);
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
