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
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Handles the group zones page: assigns zones to a group and removes them.
 */
class ManageGroupZonesController extends BaseController
{
    private ZoneGroupService $zoneGroupService;
    private GroupService $groupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->services()->userGroupRepository();
        $zoneGroupRepository = $this->services()->zoneGroupRepository();

        $this->groupService = new GroupService($groupRepository);
        $this->zoneGroupService = new ZoneGroupService($zoneGroupRepository, $groupRepository);
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage zone ownership; denials are audit-logged
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have permission to manage zone ownership.'));

        $groupId = isset($this->requestData['id']) ? (int)$this->requestData['id'] : 0;
        if ($groupId <= 0) {
            $this->setMessage('list_groups', 'error', _('Invalid group ID.'));
            $this->redirect('/groups');
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('manage_group_zones');
        $this->setPageTitle(_('Manage Group Zones'));

        if ($this->isPost()) {
            $this->processAction($groupId);
        } else {
            $this->showManageZones($groupId);
        }
    }

    private function processAction(int $groupId): void
    {
        $action = $this->httpRequest->getPostParam('action');

        if ($action === 'add') {
            $this->addZones($groupId);
        } elseif ($action === 'remove') {
            $this->removeZones($groupId);
        } else {
            $this->setMessage('manage_group_zones', 'error', _('Invalid action.'));
            $this->showManageZones($groupId);
        }
    }

    /**
     * Selected zones arrive as one comma-separated field to stay under PHP's
     * max_input_vars limit; a plain checkbox array is accepted as fallback.
     */
    private function getSelectedDomainIds(): array
    {
        $domainIds = $this->httpRequest->getPostParam('domain_ids', []);
        if (is_string($domainIds)) {
            $domainIds = explode(',', $domainIds);
        }
        if (!is_array($domainIds)) {
            return [];
        }
        $ids = [];
        foreach ($domainIds as $domainId) {
            $id = (int)$domainId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function addZones(int $groupId): void
    {
        $domainIds = $this->getSelectedDomainIds();

        if (empty($domainIds)) {
            $this->setMessage('manage_group_zones', 'error', _('Please select at least one zone.'));
            $this->showManageZones($groupId);
            return;
        }

        try {
            // Get group details and zone names before adding
            $userContext = $this->getUserContextService();
            $currentUserId = $userContext->getLoggedInUserId();
            $isAdmin = $this->services()->permissionService()->isAdmin($currentUserId);
            $group = $this->groupService->getGroupById($groupId, $currentUserId, $isAdmin);
            $groupName = $group ? $group->getName() : "ID: $groupId";

            $repositoryFactory = $this->services()->repositoryFactory();
            $domainRepository = $repositoryFactory->createDomainRepository();

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

                $this->services()->auditService()->logGroupZonesAdd($groupId, $groupName, $this->zoneLogNames($domainRepository, $results['success']));
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
        $domainIds = $this->getSelectedDomainIds();

        if (empty($domainIds)) {
            $this->setMessage('manage_group_zones', 'error', _('Please select at least one zone.'));
            $this->showManageZones($groupId);
            return;
        }

        try {
            // Get group details and zone names before removing
            $userContext = $this->getUserContextService();
            $currentUserId = $userContext->getLoggedInUserId();
            $isAdmin = $this->services()->permissionService()->isAdmin($currentUserId);
            $group = $this->groupService->getGroupById($groupId, $currentUserId, $isAdmin);
            $groupName = $group ? $group->getName() : "ID: $groupId";

            $repositoryFactory = $this->services()->repositoryFactory();
            $domainRepository = $repositoryFactory->createDomainRepository();

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

                $this->services()->auditService()->logGroupZonesRemove($groupId, $groupName, $this->zoneLogNames($domainRepository, $results['success']));
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

    /**
     * Zone names for the audit line, one per touched zone. An id that no longer
     * resolves (zones_groups has no domain foreign key) is kept as "ID:n" so the
     * count still reflects every row that changed.
     *
     * @param list<int|string> $zoneIds
     * @return list<string>
     */
    private function zoneLogNames(DomainRepositoryInterface $domainRepository, array $zoneIds): array
    {
        $names = [];
        foreach ($domainRepository->getZoneInfoFromIds($zoneIds, $this->getViewPermissionLevel()) as $info) {
            if (isset($info['id'], $info['name']) && $info['name'] !== '') {
                $names[(int)$info['id']] = (string)$info['name'];
            }
        }

        return array_map(function ($id) use ($names): string {
            $name = $names[(int)$id] ?? null;
            if ($name === null) {
                return 'ID:' . $id;
            }

            return IpHelper::displayZoneName($name);
        }, array_values($zoneIds));
    }

    private function showManageZones(int $groupId): void
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

            $repositoryFactory = $this->services()->repositoryFactory();
            $domainRepository = $repositoryFactory->createDomainRepository();

            // Get zones owned by this group
            $zoneGroups = $this->zoneGroupService->listGroupZones($groupId);
            $ownedDomainIds = array_map(fn($zg) => $zg->getDomainId(), $zoneGroups);

            // Get owned zone details in one bulk call to avoid per-zone API round-trips
            $ownedZones = [];
            if (!empty($ownedDomainIds)) {
                foreach ($domainRepository->getZoneInfoFromIds($ownedDomainIds, $this->getViewPermissionLevel()) as $zoneInfo) {
                    $name = $zoneInfo['name'] ?? '';
                    if ($name === '') {
                        continue;
                    }
                    $name = IpHelper::displayZoneName($name);
                    $ownedZones[] = [
                        'id' => (int)($zoneInfo['id'] ?? 0),
                        'name' => $name,
                        'type' => $zoneInfo['type'] ?? '',
                    ];
                }
                usort($ownedZones, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            }

            // Zones the group does not own yet, for the picker
            $availableZones = array_filter($domainRepository->listZoneNames(), function ($zone) use ($ownedDomainIds) {
                return !in_array($zone['id'], $ownedDomainIds);
            });

            // Shorten IPv6 reverse zones for display
            foreach ($availableZones as &$zone) {
                $zone['name'] = IpHelper::displayZoneName($zone['name']);
            }
            unset($zone);

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
