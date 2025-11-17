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
 * Controller for removing a user from a group
 *
 * @package     Poweradmin
 *  * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class RemoveUserGroupController extends BaseController
{
    private GroupMembershipService $membershipService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $memberRepository = new DbUserGroupMemberRepository($this->db);
        $groupRepository = new DbUserGroupRepository($this->db);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
    }

    public function run(): void
    {
        // Only admin (überuser) can manage group membership
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('edit_user', 'error', _('You do not have permission to manage group memberships.'));
            $this->redirect('/users');
            return;
        }

        if (!$this->isPost()) {
            $this->setMessage('edit_user', 'error', _('Invalid request method.'));
            $this->redirect('/users');
            return;
        }

        $this->validateCsrfToken();

        $targetUserId = isset($this->requestData['user_id']) ? (int)$this->requestData['user_id'] : 0;
        $groupId = isset($this->requestData['group_id']) ? (int)$this->requestData['group_id'] : 0;

        if ($targetUserId <= 0 || $groupId <= 0) {
            $this->setMessage('edit_user', 'error', _('Invalid user or group ID.'));
            $this->redirect('/users');
            return;
        }

        try {
            $success = $this->membershipService->removeUserFromGroup($groupId, $targetUserId);

            if ($success) {
                $this->setMessage('edit_user', 'success', _('User removed from group successfully.'));
            } else {
                $this->setMessage('edit_user', 'warning', _('User was not a member of this group.'));
            }
        } catch (InvalidArgumentException $e) {
            $this->setMessage('edit_user', 'error', $e->getMessage());
        }

        $this->redirect('/users/' . $targetUserId . '/edit');
    }
}
