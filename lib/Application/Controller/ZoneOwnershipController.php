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
 *
 */

/**
 * Script that handles zone ownership (user and group access)
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\ZoneAccessNotificationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class ZoneOwnershipController extends BaseController
{
    private UserContextService $userContextService;
    private ZoneRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
        $this->zoneRepository = $this->createZoneRepository();

        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $this->permissionService = new PermissionService($userRepository);
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit');
        $this->setPageTitle(_('Edit Zone'));

        $zone_id = $this->getSafeRequestValue('id');
        if (!$zone_id || !is_numeric($zone_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }
        $zone_id = (int)$zone_id;

        // Check permissions
        $userId = $this->userContextService->getLoggedInUserId();
        $perm_view = $this->permissionService->getViewPermissionLevel($userId);
        $perm_meta_edit = $this->permissionService->getZoneMetaEditPermissionLevel($userId);
        $perm_view_others = $this->permissionService->canViewOthersContent($userId);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if ($perm_view !== "all" && !$user_is_zone_owner) {
            $this->showError(_('You do not have permission to access this zone.'));
            return;
        }

        $meta_edit = $perm_meta_edit == "all" || ($perm_meta_edit == "own" && $user_is_zone_owner == "1");

        // Get zone information
        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }

        // Handle form submissions
        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->handleFormSubmission($zone_id, $zone_name, $userId, $meta_edit);
        }

        // Get owners
        $users = UserManager::showUsers($this->db);
        $owners = $this->zoneRepository->getZoneOwners($zone_id);

        // Filter out users who are already owners
        $ownerIds = [];
        if (is_array($owners)) {
            $ownerIds = array_column($owners, 'id');
        }
        $availableUsers = array_values(array_filter($users, function ($user) use ($ownerIds) {
            return !in_array($user['id'], $ownerIds);
        }));

        // Fetch group ownership
        $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig(), DnsBackendProviderFactory::isApiBackend($this->getConfig()));
        $groupOwnerships = $zoneGroupRepo->findByDomainId($zone_id);

        // Fetch groups - all for name lookup, filtered for dropdown
        $userGroupRepo = new DbUserGroupRepository($this->db);
        $allGroups = $userGroupRepo->findAll();
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $userGroups = $isAdmin ? $allGroups : $userGroupRepo->findByUserId($userId);

        // Filter out groups that are already owners (from user's visible groups)
        $groupOwnerIds = array_map(fn($zg) => $zg->getGroupId(), $groupOwnerships);
        $availableGroups = array_values(array_filter($userGroups, function ($group) use ($groupOwnerIds) {
            return !in_array($group->getId(), $groupOwnerIds);
        }));

        // Map group IDs to group data for display
        $groupOwners = array_map(function ($zg) use ($allGroups) {
            $groupId = $zg->getGroupId();
            $groupName = 'Group #' . $groupId;

            foreach ($allGroups as $group) {
                if ($group->getId() === $groupId) {
                    $groupName = $group->getName();
                    break;
                }
            }

            return [
                'id' => $groupId,
                'name' => $groupName
            ];
        }, $groupOwnerships);

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        // Render the ownership page
        $this->render('zone-ownership.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'is_reverse_zone' => $zone_name !== null && DnsHelper::isReverseZone($zone_name),
            'users' => $availableUsers,
            'owners' => $owners,
            'group_owners' => $groupOwners,
            'all_groups' => $availableGroups,
            'meta_edit' => $meta_edit,
            'perm_view_others' => $perm_view_others,
            'session_userid' => $userId,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
        ]);
    }

    private function handleFormSubmission(int $zone_id, string $zone_name, int $userId, bool $meta_edit): void
    {
        $auditService = new AuditService($this->db);
        $ownershipMode = new ZoneOwnershipModeService($this->config);

        // Add owner
        if (isset($_POST["newowner"]) && is_numeric($_POST["newowner"]) && $meta_edit) {
            if (!$ownershipMode->isUserOwnerAllowed()) {
                $this->setMessage('zone_ownership', 'error', _('User-owner assignment is disabled by the current zone ownership mode.'));
                return;
            }
            $ownerAdded = $this->zoneRepository->addOwnerToZone($zone_id, (int)$_POST["newowner"]);

            if ($ownerAdded) {
                $auditService->logZoneOwnerAdd($zone_id, $zone_name, (int)$_POST["newowner"]);
                $this->setMessage('zone_ownership', 'success', _('Owner has been added successfully.'));

                // Send zone access granted notification
                if ($this->config->get('notifications', 'zone_access_enabled', false)) {
                    $notificationService = $this->createZoneAccessNotificationService();
                    $notificationService->notifyAccessGranted($zone_id, (int)$_POST["newowner"], $userId);
                }
            }
        }

        // Delete owner
        if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"]) && $meta_edit) {
            // Orphan prevention: refuse if this deletion would leave the zone
            // with no remaining owners and no group ownership. The mode hint in
            // the message tells the operator what kind of replacement is allowed.
            $currentOwners = $this->zoneRepository->getZoneOwners($zone_id);
            $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig(), DnsBackendProviderFactory::isApiBackend($this->getConfig()));
            $currentGroups = $zoneGroupRepo->findByDomainId($zone_id);
            $deleteUserId = (int)$_POST["delete_owner"];
            $isCurrentOwner = false;
            foreach ($currentOwners as $o) {
                if ((int)($o['id'] ?? 0) === $deleteUserId) {
                    $isCurrentOwner = true;
                    break;
                }
            }
            $wouldRemoveLastUserOwner = $isCurrentOwner && count($currentOwners) <= 1;
            if ($wouldRemoveLastUserOwner && count($currentGroups) === 0) {
                $hint = $this->buildLastOwnerHint($ownershipMode);
                $this->setMessage('zone_ownership', 'error', _('Cannot remove the last owner: this would leave the zone with no ownership.') . ' ' . $hint);
                return;
            }
            // users_only requires at least one user owner; refuse to leave the
            // zone with only legacy group ownership that the mode forbids.
            if ($wouldRemoveLastUserOwner && !$ownershipMode->isGroupOwnerAllowed()) {
                $this->setMessage('zone_ownership', 'error', _('Cannot remove the last user owner: zone ownership mode is users_only and requires at least one user owner. Add another user owner first.'));
                return;
            }
            // groups_only requires at least one group: block any user-owner
            // removal on a zone that currently has no group ownership.
            if ($isCurrentOwner && !$ownershipMode->isUserOwnerAllowed() && count($currentGroups) === 0) {
                $this->setMessage('zone_ownership', 'error', _('Cannot remove user owner: zone ownership mode is groups_only and the zone has no group owners. Add a group first.'));
                return;
            }
            $ownerRemoved = $this->zoneRepository->removeOwnerFromZone($zone_id, $deleteUserId);

            if ($ownerRemoved) {
                $auditService->logZoneOwnerRemove($zone_id, $zone_name, (int)$_POST["delete_owner"]);
                $this->setMessage('zone_ownership', 'success', _('Owner has been removed successfully.'));

                // Send zone access revoked notification
                if ($this->config->get('notifications', 'zone_access_enabled', false)) {
                    $notificationService = $this->createZoneAccessNotificationService();
                    $notificationService->notifyAccessRevoked($zone_id, (int)$_POST["delete_owner"], $userId);
                }
            }
        }

        // Add group
        if (isset($_POST["newgroup"]) && is_numeric($_POST["newgroup"]) && $meta_edit) {
            if (!$ownershipMode->isGroupOwnerAllowed()) {
                $this->setMessage('zone_ownership', 'error', _('Group-ownership assignment is disabled by the current zone ownership mode.'));
                return;
            }
            $groupId = (int)$_POST["newgroup"];

            // Validate group ID against user's allowed groups
            $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
            if (!$isAdmin) {
                $userGroupRepo = new DbUserGroupRepository($this->db);
                $allowedGroups = $userGroupRepo->findByUserId($userId);
                $allowedGroupIds = array_map(fn($g) => $g->getId(), $allowedGroups);
                if (!in_array($groupId, $allowedGroupIds)) {
                    $this->setMessage('zone_ownership', 'error', _('You do not have permission to assign this group.'));
                    return;
                }
            }

            $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig(), DnsBackendProviderFactory::isApiBackend($this->getConfig()));
            $zoneGroupRepo->add($zone_id, $groupId);
            $auditService->logZoneGroupAdd($zone_id, $zone_name, $groupId);
            $this->setMessage('zone_ownership', 'success', _('Group has been added successfully.'));
        }

        // Delete group
        if (isset($_POST["delete_group"]) && is_numeric($_POST["delete_group"]) && $meta_edit) {
            $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig(), DnsBackendProviderFactory::isApiBackend($this->getConfig()));
            // Orphan prevention: refuse if this deletion would leave the zone
            // with no remaining groups and no user owners. Applies in every
            // mode - the message hints what kind of replacement is allowed.
            $deleteGroupId = (int)$_POST["delete_group"];
            $currentGroups = $zoneGroupRepo->findByDomainId($zone_id);
            $currentOwners = $this->zoneRepository->getZoneOwners($zone_id);
            $isCurrentGroup = false;
            foreach ($currentGroups as $zg) {
                if ($zg->getGroupId() === $deleteGroupId) {
                    $isCurrentGroup = true;
                    break;
                }
            }
            $wouldRemoveLastGroup = $isCurrentGroup && count($currentGroups) <= 1;
            if ($wouldRemoveLastGroup && count($currentOwners) === 0) {
                $hint = $this->buildLastOwnerHint($ownershipMode);
                $this->setMessage('zone_ownership', 'error', _('Cannot remove the last owner: this would leave the zone with no ownership.') . ' ' . $hint);
                return;
            }
            // groups_only requires at least one group; refuse to leave the zone
            // with only legacy user ownership that the mode forbids.
            if ($wouldRemoveLastGroup && !$ownershipMode->isUserOwnerAllowed()) {
                $this->setMessage('zone_ownership', 'error', _('Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.'));
                return;
            }
            // users_only requires at least one user owner: block any group
            // removal on a zone that currently has no user owners.
            if ($isCurrentGroup && !$ownershipMode->isGroupOwnerAllowed() && count($currentOwners) === 0) {
                $this->setMessage('zone_ownership', 'error', _('Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.'));
                return;
            }
            $zoneGroupRepo->remove($zone_id, $deleteGroupId);
            $auditService->logZoneGroupRemove($zone_id, $zone_name, $deleteGroupId);
            $this->setMessage('zone_ownership', 'success', _('Group has been removed successfully.'));
        }
    }

    private function createZoneAccessNotificationService(): ZoneAccessNotificationService
    {
        // Pass null for logger since LegacyLogger doesn't implement PSR LoggerInterface
        $mailService = new MailService($this->config, null);
        $emailTemplateService = new EmailTemplateService($this->config);

        return new ZoneAccessNotificationService(
            $this->db,
            $this->config,
            $mailService,
            $emailTemplateService,
            $this->zoneRepository,
            null
        );
    }

    private function buildLastOwnerHint(ZoneOwnershipModeService $mode): string
    {
        if ($mode->isUserOwnerAllowed() && $mode->isGroupOwnerAllowed()) {
            return _('Add another owner or a group first.');
        }
        if ($mode->isUserOwnerAllowed()) {
            return _('Add another user owner first (zone ownership mode is users_only).');
        }
        return _('Add a group first (zone ownership mode is groups_only).');
    }
}
