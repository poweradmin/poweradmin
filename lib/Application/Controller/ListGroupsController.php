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
 * Script that displays list of user groups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\GroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class ListGroupsController extends BaseController
{
    private GroupService $groupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $this->groupService = new GroupService($groupRepository);
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'list_groups';

        $this->showGroupsList();
    }

    private function showGroupsList(): void
    {
        $userId = $_SESSION['userid'];
        $isAdmin = UserManager::isAdmin($userId, $this->db);

        // Get groups based on user role (admin sees all, normal users see only their groups)
        $groups = $this->groupService->listGroups($userId, $isAdmin);

        // Enrich groups with member and zone counts
        $enrichedGroups = [];
        foreach ($groups as $group) {
            $details = $this->groupService->getGroupDetails($group->getId());
            $enrichedGroups[] = [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription(),
                'perm_templ_id' => $group->getPermTemplId(),
                'member_count' => $details['member_count'],
                'zone_count' => $details['zone_count'],
                'created_at' => $group->getCreatedAt(),
            ];
        }

        $this->render('list_groups.html', [
            'groups' => $enrichedGroups,
            'is_admin' => $isAdmin,
            'can_add_group' => $isAdmin, // Only admins can create groups
        ]);
    }
}
