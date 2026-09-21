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

use InvalidArgumentException;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Domain\Model\Permission;

/**
 * Handles the group members page: adds and removes users in a group.
 */
class ManageGroupMembersController extends BaseController
{
    private GroupMembershipService $membershipService;
    private GroupService $groupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->services()->userGroupRepository();
        $memberRepository = $this->services()->userGroupMemberRepository();

        $this->groupService = new GroupService($groupRepository);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage group membership; denials are audit-logged
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have permission to manage group members.'));

        $groupId = isset($this->requestData['id']) ? (int)$this->requestData['id'] : 0;
        if ($groupId <= 0) {
            $this->setMessage('list_groups', 'error', _('Invalid group ID.'));
            $this->redirect('/groups');
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('manage_group_members');
        $this->setPageTitle(_('Manage Group Members'));

        if ($this->isPost()) {
            $this->processAction($groupId);
        } else {
            $this->showManageMembers($groupId);
        }
    }

    private function processAction(int $groupId): void
    {
        $action = $this->httpRequest->getPostParam('action');

        if ($action === 'add') {
            $this->addMembers($groupId);
        } elseif ($action === 'remove') {
            $this->removeMembers($groupId);
        } else {
            $this->setMessage('manage_group_members', 'error', _('Invalid action.'));
            $this->showManageMembers($groupId);
        }
    }

    /**
     * Selected users arrive as one comma-separated field to stay under PHP's
     * max_input_vars limit; a plain checkbox array is accepted as fallback.
     */
    private function getSelectedUserIds(): array
    {
        $userIds = $this->httpRequest->getPostParam('user_ids', []);
        if (is_string($userIds)) {
            $userIds = explode(',', $userIds);
        }
        if (!is_array($userIds)) {
            return [];
        }
        $ids = [];
        foreach ($userIds as $userId) {
            $id = (int)$userId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function addMembers(int $groupId): void
    {
        $userIds = $this->getSelectedUserIds();

        if (empty($userIds)) {
            $this->setMessage('manage_group_members', 'error', _('Please select at least one user.'));
            $this->showManageMembers($groupId);
            return;
        }

        try {
            // Get group details and usernames before adding
            $userContext = $this->getUserContextService();
            $currentUserId = $userContext->getLoggedInUserId();
            $isAdmin = $this->services()->permissionService()->isAdmin($currentUserId);
            $group = $this->groupService->getGroupById($groupId, $currentUserId, $isAdmin);
            $groupName = $group ? $group->getName() : "ID: $groupId";

            // Get usernames for logging
            $userMap = array_column($this->getVisibleUsers(), 'username', 'uid');

            $results = $this->membershipService->bulkAddUsers($groupId, $userIds);

            if (!empty($results['success'])) {
                $this->forgetMembers($results['success']);
                $message = sprintf(
                    ngettext(
                        '%d user added to group.',
                        '%d users added to group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_members', 'success', $message);

                // Build detailed log message with usernames
                $addedUsernames = array_map(fn($id) => $userMap[$id] ?? "ID: $id", $results['success']);
                $this->services()->auditService()->logGroupMembersAdd($groupId, $groupName, array_values($addedUsernames));
            }

            if (!empty($results['failed'])) {
                $failedCount = count($results['failed']);
                $message = sprintf(
                    ngettext(
                        '%d user could not be added.',
                        '%d users could not be added.',
                        $failedCount
                    ),
                    $failedCount
                );
                $this->setMessage('manage_group_members', 'warning', $message);
            }

            $this->showManageMembers($groupId);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('manage_group_members', 'error', $e->getMessage());
            $this->showManageMembers($groupId);
        }
    }

    /**
     * Group templates and group-owned zones feed the permission cache.
     *
     * @param int[] $userIds
     */
    private function forgetMembers(array $userIds): void
    {
        $permissionService = $this->services()->permissionService();
        foreach ($userIds as $userId) {
            $permissionService->forgetUser((int)$userId);
        }
    }

    private function removeMembers(int $groupId): void
    {
        $userIds = $this->getSelectedUserIds();

        if (empty($userIds)) {
            $this->setMessage('manage_group_members', 'error', _('Please select at least one user.'));
            $this->showManageMembers($groupId);
            return;
        }

        try {
            // Get group details and usernames before removing
            $userContext = $this->getUserContextService();
            $currentUserId = $userContext->getLoggedInUserId();
            $isAdmin = $this->services()->permissionService()->isAdmin($currentUserId);
            $group = $this->groupService->getGroupById($groupId, $currentUserId, $isAdmin);
            $groupName = $group ? $group->getName() : "ID: $groupId";

            // Get usernames for logging
            $userMap = array_column($this->getVisibleUsers(), 'username', 'uid');

            $results = $this->membershipService->bulkRemoveUsers($groupId, $userIds);

            if (!empty($results['success'])) {
                $this->forgetMembers($results['success']);
                $message = sprintf(
                    ngettext(
                        '%d user removed from group.',
                        '%d users removed from group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_members', 'success', $message);

                // Build detailed log message with usernames
                $removedUsernames = array_map(fn($id) => $userMap[$id] ?? "ID: $id", $results['success']);
                $this->services()->auditService()->logGroupMembersRemove($groupId, $groupName, array_values($removedUsernames));
            }

            if (!empty($results['failed'])) {
                $failedCount = count($results['failed']);
                $message = sprintf(
                    ngettext(
                        '%d user could not be removed.',
                        '%d users could not be removed.',
                        $failedCount
                    ),
                    $failedCount
                );
                $this->setMessage('manage_group_members', 'warning', $message);
            }

            $this->showManageMembers($groupId);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('manage_group_members', 'error', $e->getMessage());
            $this->showManageMembers($groupId);
        }
    }

    private function showManageMembers(int $groupId): void
    {
        try {
            $userContext = $this->getUserContextService();
            $userId = $userContext->getLoggedInUserId();
            $isAdmin = $this->services()->permissionService()->isAdmin($userId);

            $group = $this->groupService->getGroupById($groupId, $userId, $isAdmin);
            if (!$group) {
                $this->setMessage('list_groups', 'error', _('Group not found.'));
                $this->redirect('/groups');
                return;
            }

            // Get current members
            $members = $this->membershipService->listGroupMembers($groupId);
            $memberIds = array_map(fn($m) => $m->getUserId(), $members);

            // Get member details
            $currentMembers = [];
            foreach ($members as $member) {
                $currentMembers[] = [
                    'id' => $member->getUserId(),
                    'username' => $member->getUsername(),
                    'fullname' => $member->getFullname() ?? '',
                    'email' => $member->getEmail() ?? '',
                ];
            }

            // Get all users for selection
            $allUsers = $this->getVisibleUsers();

            // Filter out current members from available users
            $availableUsers = array_filter($allUsers, function ($user) use ($memberIds) {
                return !in_array($user['uid'], $memberIds);
            });

            $this->render('manage_group_members.html', [
                'group' => $group,
                'current_members' => $currentMembers,
                'available_users' => array_values($availableUsers),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('list_groups', 'error', $e->getMessage());
            $this->redirect('/groups');
        }
    }

    /**
     * Users visible to the current account: everyone with the view-others
     * permission, otherwise only the account itself
     */
    private function getVisibleUsers(): array
    {
        $restrictToUserId = $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS) ? null : ($this->getCurrentUserId() ?? 0);
        return $this->services()->userRepository()->getUserDetailList(false, $restrictToUserId);
    }
}
