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

namespace Poweradmin\Application\Controller\User;

use InvalidArgumentException;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Domain\Model\Permission;

/**
 * Handles the POST that removes one user from a group from the edit-group page.
 */
class QuickRemoveGroupMemberController extends BaseController
{
    private ?GroupMembershipService $membershipService = null;

    private function membershipService(): GroupMembershipService
    {
        return $this->membershipService ??= new GroupMembershipService(
            $this->services()->userGroupMemberRepository(),
            $this->services()->userGroupRepository()
        );
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage group membership; denials are audit-logged
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have permission to manage group members.'));

        if (!$this->isPost()) {
            $this->setMessage('edit_group', 'error', _('Invalid request method.'));
            $this->redirect('/groups');
            return;
        }

        $groupId = isset($this->requestData['group_id']) ? (int)$this->requestData['group_id'] : 0;
        $memberId = isset($this->requestData['user_id']) ? (int)$this->requestData['user_id'] : 0;

        if ($groupId <= 0 || $memberId <= 0) {
            $this->setMessage('edit_group', 'error', _('Invalid group or user ID.'));
            $this->redirect('/groups');
            return;
        }

        try {
            $success = $this->membershipService()->removeUserFromGroup($groupId, $memberId);

            if ($success) {
                $auditService = $this->services()->auditService();
                $auditService->logGroupMemberRemove($groupId, $memberId);
                $this->setMessage('edit_group', 'success', _('Member removed from group successfully.'));
            } else {
                $this->setMessage('edit_group', 'warning', _('User was not a member of this group.'));
            }
        } catch (InvalidArgumentException $e) {
            $this->setMessage('edit_group', 'error', $e->getMessage());
        }

        $this->redirect('/groups/' . $groupId . '/edit');
    }
}
