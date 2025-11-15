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
 * Script that handles adding/removing users to/from groups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Symfony\Component\Validator\Constraints as Assert;

class ManageGroupMembersController extends BaseController
{
    private GroupMembershipService $membershipService;
    private GroupService $groupService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $memberRepository = new DbUserGroupMemberRepository($this->db);

        $this->groupService = new GroupService($groupRepository);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
        $this->request = new Request();
    }

    public function run(): void
    {
        // Only admin (überuser) can manage group membership
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to manage group members.'));
            $this->redirect('/groups');
            return;
        }

        $groupId = isset($this->requestData['id']) ? (int)$this->requestData['id'] : 0;
        if ($groupId <= 0) {
            $this->setMessage('list_groups', 'error', _('Invalid group ID.'));
            $this->redirect('/groups');
            return;
        }

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'manage_group_members';

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->processAction($groupId);
        } else {
            $this->showManageMembers($groupId);
        }
    }

    private function processAction(int $groupId): void
    {
        $action = $this->request->getPostParam('action');

        if ($action === 'add') {
            $this->addMembers($groupId);
        } elseif ($action === 'remove') {
            $this->removeMembers($groupId);
        } else {
            $this->setMessage('manage_group_members', 'error', _('Invalid action.'));
            $this->showManageMembers($groupId);
        }
    }

    private function addMembers(int $groupId): void
    {
        $userIds = $this->request->getPostParam('user_ids', []);

        if (!is_array($userIds) || empty($userIds)) {
            $this->setMessage('manage_group_members', 'error', _('Please select at least one user.'));
            $this->showManageMembers($groupId);
            return;
        }

        // Convert to integers
        $userIds = array_map('intval', $userIds);

        try {
            $results = $this->membershipService->bulkAddUsers($groupId, $userIds);

            if (!empty($results['success'])) {
                $message = sprintf(
                    ngettext(
                        '%d user added to group.',
                        '%d users added to group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_members', 'success', $message);
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

    private function removeMembers(int $groupId): void
    {
        $userIds = $this->request->getPostParam('user_ids', []);

        if (!is_array($userIds) || empty($userIds)) {
            $this->setMessage('manage_group_members', 'error', _('Please select at least one user.'));
            $this->showManageMembers($groupId);
            return;
        }

        // Convert to integers
        $userIds = array_map('intval', $userIds);

        try {
            $results = $this->membershipService->bulkRemoveUsers($groupId, $userIds);

            if (!empty($results['success'])) {
                $message = sprintf(
                    ngettext(
                        '%d user removed from group.',
                        '%d users removed from group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_members', 'success', $message);
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
            $isAdmin = UserManager::isUserSuperuser($this->db, $userId);

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
            $allUsers = UserManager::getUserDetailList($this->db, false);

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
}
