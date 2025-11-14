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
 * Script that handles adding/removing zones to/from groups
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
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;

class ManageGroupZonesController extends BaseController
{
    private ZoneGroupService $zoneGroupService;
    private GroupService $groupService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $zoneGroupRepository = new DbZoneGroupRepository($this->db);

        $this->groupService = new GroupService($groupRepository);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
        $this->request = new Request();
    }

    public function run(): void
    {
        // Any admin can manage zone ownership (same as user ownership model)
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to manage zone ownership.'));
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
        $this->requestData['page'] = 'manage_group_zones';

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->processAction($groupId);
        } else {
            $this->showManageZones($groupId);
        }
    }

    private function processAction(int $groupId): void
    {
        $action = $this->request->getPostParam('action');

        if ($action === 'add') {
            $this->addZones($groupId);
        } elseif ($action === 'remove') {
            $this->removeZones($groupId);
        } else {
            $this->setMessage('manage_group_zones', 'error', _('Invalid action.'));
            $this->showManageZones($groupId);
        }
    }

    private function addZones(int $groupId): void
    {
        $domainIds = $this->request->getPostParam('domain_ids', []);

        if (!is_array($domainIds) || empty($domainIds)) {
            $this->setMessage('manage_group_zones', 'error', _('Please select at least one zone.'));
            $this->showManageZones($groupId);
            return;
        }

        // Convert to integers
        $domainIds = array_map('intval', $domainIds);

        try {
            $results = $this->zoneGroupService->bulkAddZones($groupId, $domainIds);

            if (!empty($results['success'])) {
                $message = sprintf(
                    ngettext(
                        '%d zone added to group.',
                        '%d zones added to group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_zones', 'success', $message);
            }

            if (!empty($results['failed'])) {
                $failedCount = count($results['failed']);
                $message = sprintf(
                    ngettext(
                        '%d zone could not be added.',
                        '%d zones could not be added.',
                        $failedCount
                    ),
                    $failedCount
                );
                $this->setMessage('manage_group_zones', 'warning', $message);
            }

            $this->showManageZones($groupId);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('manage_group_zones', 'error', $e->getMessage());
            $this->showManageZones($groupId);
        }
    }

    private function removeZones(int $groupId): void
    {
        $domainIds = $this->request->getPostParam('domain_ids', []);

        if (!is_array($domainIds) || empty($domainIds)) {
            $this->setMessage('manage_group_zones', 'error', _('Please select at least one zone.'));
            $this->showManageZones($groupId);
            return;
        }

        // Convert to integers
        $domainIds = array_map('intval', $domainIds);

        try {
            $results = $this->zoneGroupService->bulkRemoveZones($groupId, $domainIds);

            if (!empty($results['success'])) {
                $message = sprintf(
                    ngettext(
                        '%d zone removed from group.',
                        '%d zones removed from group.',
                        count($results['success'])
                    ),
                    count($results['success'])
                );
                $this->setMessage('manage_group_zones', 'success', $message);
            }

            if (!empty($results['failed'])) {
                $failedCount = count($results['failed']);
                $message = sprintf(
                    ngettext(
                        '%d zone could not be removed.',
                        '%d zones could not be removed.',
                        $failedCount
                    ),
                    $failedCount
                );
                $this->setMessage('manage_group_zones', 'warning', $message);
            }

            $this->showManageZones($groupId);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('manage_group_zones', 'error', $e->getMessage());
            $this->showManageZones($groupId);
        }
    }

    private function showManageZones(int $groupId): void
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

            // Get zones owned by this group
            $zoneGroups = $this->zoneGroupService->listGroupZones($groupId);
            $ownedDomainIds = array_map(fn($zg) => $zg->getDomainId(), $zoneGroups);

            // Get owned zone details
            $ownedZones = [];
            if (!empty($ownedDomainIds)) {
                $placeholders = implode(',', array_fill(0, count($ownedDomainIds), '?'));
                $query = "SELECT id, name, type FROM domains WHERE id IN ($placeholders) ORDER BY name ASC";
                $stmt = $this->db->prepare($query);
                $stmt->execute($ownedDomainIds);
                $ownedZones = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            // Get all zones for selection
            $query = "SELECT id, name, type FROM domains ORDER BY name ASC";
            $stmt = $this->db->query($query);
            $allZones = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Filter out owned zones from available zones
            $availableZones = array_filter($allZones, function ($zone) use ($ownedDomainIds) {
                return !in_array($zone['id'], $ownedDomainIds);
            });

            $this->render('manage_group_zones.html', [
                'group' => $group,
                'owned_zones' => $ownedZones,
                'available_zones' => array_values($availableZones),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->setMessage('list_groups', 'error', $e->getMessage());
            $this->redirect('/groups');
        }
    }
}
