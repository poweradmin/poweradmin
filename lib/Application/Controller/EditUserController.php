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
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Symfony\Component\Validator\Constraints as Assert;

class EditUserController extends BaseController
{
    protected Request $request;
    private PasswordPolicyService $policyService;

    private readonly UserContextService $userContextService;
    public function __construct(
        array $request
    ) {
        parent::__construct($request);

        $this->request = new Request();
        $this->policyService = new PasswordPolicyService();
        $this->userContextService = new UserContextService();
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
        $this->requestData['page'] = 'edit_user';

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
            $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
            $canViewAllUsers = UserManager::verifyPermission($this->db, 'user_view_others');
            $canEditAllUsers = UserManager::verifyPermission($this->db, 'user_edit_others');

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
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('edit_user', 'error', _('Please fill in all required fields correctly.'));
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

        // Skip password validation for external auth users
        $editId = (int)$this->request->getPostParam('number');
        $user = $this->getUserDetails($editId);
        $externalAuthMethods = ['ldap', 'oidc', 'saml'];
        if (in_array($user['auth_type'] ?? 'sql', $externalAuthMethods)) {
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
        $canEditOwn = UserManager::verifyPermission($this->db, 'user_edit_own');
        $canEditOthers = UserManager::verifyPermission($this->db, 'user_edit_others');

        if ((!$isOwnProfile || !$canEditOwn) && ($isOwnProfile || !$canEditOthers)) {
            $this->showError(_('You do not have the permission to edit this user.'));
        }

        // Prevent non-superusers from editing superuser accounts (privilege escalation protection)
        $targetIsSuperuser = UserManager::isUserSuperuser($this->db, $editId);
        $currentIsSuperuser = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        if ($targetIsSuperuser && !$currentIsSuperuser) {
            $this->showError(_('You do not have permission to edit a superuser account.'));
        }
    }

    private function prepareUserData(): array
    {
        $editId = (int)$this->request->getPostParam('number');
        $isOwnProfile = $editId === $this->userContextService->getLoggedInUserId();
        $canEditOthers = UserManager::verifyPermission($this->db, 'user_edit_others');

        // Force active state to true if user is editing their own profile
        $active = $isOwnProfile ? true : $this->request->getPostParam('active') === '1';

        // Determine permission template
        $permTempl = $this->request->getPostParam('perm_templ');

        // If editing own profile and not an admin, maintain existing template
        if ($isOwnProfile && !$canEditOthers) {
            $userData = $this->getUserDetails($editId);
            $permTempl = $userData['tpl_id'];
        }

        return [
            'username' => htmlspecialchars($this->request->getPostParam('username')),
            'fullname' => htmlspecialchars($this->request->getPostParam('fullname')),
            'email' => htmlspecialchars($this->request->getPostParam('email')),
            'description' => htmlspecialchars($this->request->getPostParam('description')),
            'password' => $this->request->getPostParam('password', ''),
            'perm_templ' => $permTempl,
            'active' => $active,
            'use_ldap' => $this->request->getPostParam('use_ldap') === '1'
        ];
    }

    public function showUserEditForm(int $editId, array $policyConfig): void
    {
        $user = $this->getUserDetails($editId);
        $permissions = $this->getUserPermissions($editId);

        // Check if password changes should be disabled for external auth users
        $externalAuthMethods = ['ldap', 'oidc', 'saml'];
        $isExternalAuth = in_array($user['auth_type'] ?? 'sql', $externalAuthMethods);

        // Fetch user's group memberships
        $groupMemberRepo = new DbUserGroupMemberRepository($this->db);
        $userGroupRepo = new DbUserGroupRepository($this->db);

        $memberships = $groupMemberRepo->findByUserId($editId);
        $allGroups = $userGroupRepo->findAll();

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
            'permission_templates' => UserManager::listPermissionTemplates($this->db, 'user'),
            'user_permissions' => UserManager::getPermissionsByTemplateId($this->db, $user['tpl_id']),
            'ldap_use' => $this->config->get('ldap', 'enabled', false) && !$permissions['is_admin'],
            'use_ldap_checked' => $user['use_ldap'] ? "checked" : "",
            'is_external_auth' => $isExternalAuth,
            'password_policy' => $policyConfig,
            'user_groups' => $userGroups,
            'available_groups' => $availableGroupsArray,
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
        ]);
    }

    private function getUserPermissions(int $editId): array
    {
        $isCurrentUser = $this->userContextService->getLoggedInUserId() == $editId;

        return [
            'edit_templ_perm' => UserManager::verifyPermission($this->db, 'user_edit_templ_perm'),
            'passwd_edit_others' => UserManager::verifyPermission($this->db, 'user_passwd_edit_others'),
            'edit_own' => UserManager::verifyPermission($this->db, 'user_edit_own'),
            'is_admin' => Permission::getPermissions($this->db, ['user_is_ueberuser'])['user_is_ueberuser']
                && $isCurrentUser
        ];
    }

    private function handleAddToGroups(int $userId): void
    {
        // Only admins can manage group memberships
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
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

        $groupRepository = new DbUserGroupRepository($this->db);
        $memberRepository = new DbUserGroupMemberRepository($this->db);
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

            // Log the additions for each group in the same format as group management
            $currentUserId = $this->userContextService->getLoggedInUserId();
            $ldapUse = $this->config->get('ldap', 'enabled');
            $currentUsers = UserManager::getUserDetailList($this->db, $ldapUse, $currentUserId);
            $actorUsername = !empty($currentUsers) ? $currentUsers[0]['username'] : "ID: $currentUserId";

            $logger = new \Poweradmin\Infrastructure\Logger\DbGroupLogger($this->db);

            // Log each group addition separately with the target username
            foreach ($successfulGroups as $groupInfo) {
                $logMessage = sprintf(
                    "Added 1 user(s) to group '%s' (ID: %d) by %s: %s",
                    $groupInfo['name'],
                    $groupInfo['id'],
                    $actorUsername,
                    $targetUsername
                );
                $logger->doLog($logMessage, $groupInfo['id'], LOG_INFO);
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
        $users = UserManager::getUserDetailList($this->db, $this->config->get('ldap', 'enabled', false), $editId);

        if (empty($users)) {
            $this->showError(_('User does not exist.'));
        }

        return $users[0];
    }
}
