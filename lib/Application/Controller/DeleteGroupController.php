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
 * Script that handles requests to delete user groups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;

class DeleteGroupController extends BaseController
{
    private GroupService $groupService;
    private ZoneGroupService $zoneGroupService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $zoneGroupRepository = new DbZoneGroupRepository($this->db, $this->config);

        $this->groupService = new GroupService($groupRepository);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
        $this->request = new Request();
    }

    public function run(): void
    {
        // Only admin (überuser) can delete groups
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to delete groups.'));
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
        $this->requestData['page'] = 'delete_group';

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->deleteGroup($groupId);
        } else {
            $this->showDeleteConfirmation($groupId);
        }
    }

    private function deleteGroup(int $groupId): void
    {
        $confirm = $this->request->getPostParam('confirm');

        if ($confirm !== 'yes') {
            $this->setMessage('list_groups', 'info', _('Group deletion cancelled.'));
            $this->redirect('/groups');
            return;
        }

        try {
            $this->groupService->deleteGroup($groupId);

            $this->setMessage('list_groups', 'success', _('Group has been deleted successfully.'));
            $this->redirect('/groups');
        } catch (InvalidArgumentException $e) {
            $this->setMessage('delete_group', 'error', $e->getMessage());
            $this->showDeleteConfirmation($groupId);
        }
    }

    private function showDeleteConfirmation(int $groupId): void
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

            $details = $this->groupService->getGroupDetails($groupId);

            // Get impact information (up to 20 zones)
            $impact = $this->zoneGroupService->getGroupDeletionImpact($groupId, 20);

            // Get zone details for display
            $tableNameService = new TableNameService($this->config);
            $domainsTable = $tableNameService->getTable(PdnsTable::DOMAINS);

            $zoneDetails = [];
            foreach ($impact['zones'] as $zoneGroup) {
                $domainId = $zoneGroup->getDomainId();
                $query = "SELECT name FROM $domainsTable WHERE id = :id";
                $stmt = $this->db->prepare($query);
                $stmt->execute([':id' => $domainId]);
                $zoneName = $stmt->fetchColumn();

                if ($zoneName) {
                    $zoneDetails[] = [
                        'id' => $domainId,
                        'name' => $zoneName,
                    ];
                }
            }

            $this->render('delete_group.html', [
                'group' => $group,
                'member_count' => $details['memberCount'],
                'zone_count' => $impact['zoneCount'],
                'zones_sample' => $zoneDetails,
                'show_more_zones' => $impact['zoneCount'] > 20,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('list_groups', 'error', $e->getMessage());
            $this->redirect('/groups');
        }
    }
}
