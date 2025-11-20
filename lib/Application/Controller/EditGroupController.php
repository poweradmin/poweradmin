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
 * Script that handles requests to edit user groups
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
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Symfony\Component\Validator\Constraints as Assert;

class EditGroupController extends BaseController
{
    private GroupService $groupService;
    private GroupMembershipService $membershipService;
    private ZoneGroupService $zoneGroupService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $memberRepository = new DbUserGroupMemberRepository($this->db);
        $zoneGroupRepository = new DbZoneGroupRepository($this->db, $this->config);

        $this->groupService = new GroupService($groupRepository);
        $this->membershipService = new GroupMembershipService($memberRepository, $groupRepository);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
        $this->request = new Request();
    }

    public function run(): void
    {
        // Only admin (überuser) can edit groups
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to edit groups.'));
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
        $this->requestData['page'] = 'edit_group';

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->editGroup($groupId);
        } else {
            $this->renderEditGroupForm($groupId);
        }
    }

    private function editGroup(int $groupId): void
    {
        if (!$this->validateInput()) {
            $this->renderEditGroupForm($groupId);
            return;
        }

        $name = $this->request->getPostParam('name');
        $description = $this->request->getPostParam('description', '');
        $permTemplId = (int)$this->request->getPostParam('perm_templ');

        try {
            $this->groupService->updateGroup($groupId, $name, $description, $permTemplId);

            // Log group update
            $logger = new DbGroupLogger($this->db);
            $logger->doLog("Group updated: $name (ID: $groupId)", $groupId, LOG_INFO);

            $this->setMessage('list_groups', 'success', _('Group has been updated successfully.'));
            $this->redirect('/groups');
        } catch (InvalidArgumentException $e) {
            $this->setMessage('edit_group', 'error', $e->getMessage());
            $this->renderEditGroupForm($groupId);
        }
    }

    private function renderEditGroupForm(int $groupId): void
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
            $members = $this->membershipService->listGroupMembers($groupId);
            $zones = $this->zoneGroupService->listGroupZones($groupId);

            $permTemplates = UserManager::listPermissionTemplates($this->db);

            // Get member usernames
            $memberUsernames = [];
            foreach ($members as $member) {
                $memberUsernames[] = [
                    'id' => $member->getUserId(),
                    'username' => $member->getUsername(),
                    'fullname' => $member->getFullname() ?? '',
                ];
            }

            // Get zone details
            $tableNameService = new TableNameService($this->config);
            $domainsTable = $tableNameService->getTable(PdnsTable::DOMAINS);

            $zoneDetails = [];
            foreach ($zones as $zoneGroup) {
                $domainId = $zoneGroup->getDomainId();
                // Get zone name from domains table
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

            $this->render('edit_group.html', [
                'group' => $group,
                'details' => $details,
                'members' => $memberUsernames,
                'zones' => $zoneDetails,
                'perm_templates' => $permTemplates,
                'name' => $this->request->getPostParam('name', $group->getName()),
                'description' => $this->request->getPostParam('description', $group->getDescription() ?? ''),
                'perm_templ' => $this->request->getPostParam('perm_templ', (string)$group->getPermTemplId()),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('list_groups', 'error', $e->getMessage());
            $this->redirect('/groups');
        }
    }

    private function validateInput(): bool
    {
        $constraints = [
            'name' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 1, 'max' => 255])
            ],
            'perm_templ' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('edit_group', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }
}
