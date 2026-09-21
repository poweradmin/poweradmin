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
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;

/**
 * Handles the POST that removes a user from one group from the edit-user page.
 */
class RemoveUserGroupController extends BaseController
{
    private GroupMembershipService $membershipService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $memberRepository = $this->services()->userGroupMemberRepository();
        $groupRepository = $this->services()->userGroupRepository();
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage group membership; denials are audit-logged
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have permission to manage group memberships.'));

        if (!$this->isPost()) {
            $this->setMessage('edit_user', 'error', _('Invalid request method.'));
            $this->redirect('/users');
            return;
        }

        $targetUserId = isset($this->requestData['user_id']) ? (int)$this->requestData['user_id'] : 0;
        $groupId = isset($this->requestData['group_id']) ? (int)$this->requestData['group_id'] : 0;

        if ($targetUserId <= 0 || $groupId <= 0) {
            $this->setMessage('edit_user', 'error', _('Invalid group or user ID.'));
            $this->redirect('/users');
            return;
        }

        try {
            // Get details before removal for logging
            $groupRepository = $this->services()->userGroupRepository();

            $group = $groupRepository->findById($groupId);
            $groupName = $group ? $group->getName() : "ID: $groupId";

            // Get target user details for logging
            $userRepository = $this->services()->userRepository();
            $targetUser = $userRepository->getUserById($targetUserId);
            $targetUsername = $targetUser !== null ? $targetUser['username'] : "ID: $targetUserId";

            $success = $this->membershipService->removeUserFromGroup($groupId, $targetUserId);

            if ($success) {
                $this->setMessage('edit_user', 'success', _('User removed from group successfully.'));

                $this->services()->auditService()->logGroupMembersRemove($groupId, $groupName, [(string)$targetUsername]);
            } else {
                $this->setMessage('edit_user', 'warning', _('User was not a member of this group.'));
            }
        } catch (InvalidArgumentException $e) {
            $this->setMessage('edit_user', 'error', $e->getMessage());
        }

        $this->redirect('/users/' . $targetUserId . '/edit');
    }
}
