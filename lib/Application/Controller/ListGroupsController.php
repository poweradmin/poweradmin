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

use Poweradmin\Application\Service\GroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;

/**
 * Renders the groups list page at /groups.
 */
class ListGroupsController extends BaseController
{
    private GroupService $groupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->services()->userGroupRepository();
        $this->groupService = new GroupService($groupRepository);
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_groups');
        $this->setPageTitle(_('Groups'));

        $this->showGroupsList();
    }

    private function showGroupsList(): void
    {
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        $isAdmin = $this->services()->permissionService()->isAdmin($userId);

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
                'member_count' => $details['memberCount'],
                'zone_count' => $details['zoneCount'],
                'created_at' => $group->getCreatedAt(),
            ];
        }

        $this->render('list_groups.html', [
            'groups' => $enrichedGroups,
            'is_admin' => $isAdmin,
            'can_add_group' => $isAdmin, // Only admins can create groups
            'perm_is_godlike' => $isAdmin,
            'perm_group_logs_view' => $this->hasPermission(Permission::PERM_GROUP_LOGS_VIEW),
            'dblog_use' => $this->config->get('logging', 'database_enabled', false),
        ]);
    }
}
