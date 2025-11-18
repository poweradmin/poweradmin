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
 * Script that handles zone settings (ownership, type, template)
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\ZoneAccessNotificationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

class ZoneSettingsController extends BaseController
{
    private UserContextService $userContextService;
    private ZoneRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;
    private DnsRecord $dnsRecord;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());

        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $this->permissionService = new PermissionService($userRepository);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'edit';

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
            $this->handleFormSubmission($zone_id, $userId, $meta_edit);
        }

        // Get zone data
        $domain_type = $this->zoneRepository->getDomainType($zone_id);
        $slave_master = $this->zoneRepository->getDomainSlaveMaster($zone_id);
        $types = ZoneType::getTypes();

        // Get zone templates
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $zone_templates = $zone_templates->getListZoneTempl($userId);
        $zone_template_id = DnsRecord::getZoneTemplate($this->db, $zone_id);
        $zone_template_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_template_id);

        // Get owners
        $users = UserManager::showUsers($this->db);
        $owners = $this->zoneRepository->getZoneOwners($zone_id);

        // Filter out users who are already owners
        $ownerIds = [];
        if ($owners !== "-1") {
            $ownerIds = array_column($owners, 'id');
        }
        $availableUsers = array_values(array_filter($users, function ($user) use ($ownerIds) {
            return !in_array($user['id'], $ownerIds);
        }));

        // Fetch group ownership
        $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig());
        $groupOwnerships = $zoneGroupRepo->findByDomainId($zone_id);

        // Fetch all groups for name lookup and dropdown
        $userGroupRepo = new DbUserGroupRepository($this->db);
        $allGroups = $userGroupRepo->findAll();

        // Filter out groups that are already owners
        $groupOwnerIds = array_map(fn($zg) => $zg->getGroupId(), $groupOwnerships);
        $availableGroups = array_values(array_filter($allGroups, function ($group) use ($groupOwnerIds) {
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

        // Render the settings page
        $this->render('zone-settings.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'domain_type' => $domain_type,
            'slave_master' => $slave_master,
            'zone_types' => $types,
            'zone_templates' => $zone_templates,
            'zone_template_id' => $zone_template_id,
            'zone_template_details' => $zone_template_details,
            'users' => $availableUsers,
            'owners' => $owners,
            'group_owners' => $groupOwners,
            'all_groups' => $availableGroups,
            'meta_edit' => $meta_edit,
            'perm_view_others' => $perm_view_others,
            'session_userid' => $userId,
        ]);
    }

    private function handleFormSubmission(int $zone_id, int $userId, bool $meta_edit): void
    {
        // Add owner
        if (isset($_POST["newowner"]) && is_numeric($_POST["newowner"]) && $meta_edit) {
            $ownerAdded = $this->zoneRepository->addOwnerToZone($zone_id, $_POST["newowner"]);

            if ($ownerAdded) {
                $this->setMessage('zone_settings', 'success', _('Owner has been added successfully.'));

                // Send zone access granted notification
                if ($this->config->get('notifications', 'zone_access_enabled', false)) {
                    $notificationService = $this->createZoneAccessNotificationService();
                    $notificationService->notifyAccessGranted($zone_id, (int)$_POST["newowner"], $userId);
                }
            }
        }

        // Delete owner
        if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"]) && $meta_edit) {
            $ownerRemoved = $this->zoneRepository->removeOwnerFromZone($zone_id, $_POST["delete_owner"]);

            if ($ownerRemoved) {
                $this->setMessage('zone_settings', 'success', _('Owner has been removed successfully.'));

                // Send zone access revoked notification
                if ($this->config->get('notifications', 'zone_access_enabled', false)) {
                    $notificationService = $this->createZoneAccessNotificationService();
                    $notificationService->notifyAccessRevoked($zone_id, (int)$_POST["delete_owner"], $userId);
                }
            }
        }

        // Add group
        if (isset($_POST["newgroup"]) && is_numeric($_POST["newgroup"]) && $meta_edit) {
            $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig());
            $zoneGroupRepo->add($zone_id, (int)$_POST["newgroup"]);
            $this->setMessage('zone_settings', 'success', _('Group has been added successfully.'));
        }

        // Delete group
        if (isset($_POST["delete_group"]) && is_numeric($_POST["delete_group"]) && $meta_edit) {
            $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig());
            $zoneGroupRepo->remove($zone_id, (int)$_POST["delete_group"]);
            $this->setMessage('zone_settings', 'success', _('Group has been removed successfully.'));
        }

        // Change zone type
        $new_type = htmlspecialchars($_POST['newtype'] ?? '');
        if (isset($_POST['type_change']) && in_array($new_type, ZoneType::getTypes()) && $meta_edit) {
            $this->dnsRecord->changeZoneType($new_type, $zone_id);
            $this->setMessage('zone_settings', 'success', _('Zone type has been changed successfully.'));
        }

        // Change slave master
        if (isset($_POST['slave_master_change']) && $meta_edit) {
            $this->dnsRecord->changeZoneSlaveMaster($zone_id, $_POST['new_master']);
            $this->setMessage('zone_settings', 'success', _('Slave master has been changed successfully.'));
        }

        // Change template
        if (isset($_POST["template_change"]) && $meta_edit) {
            if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
                $new_zone_template = 0;
            } else {
                $new_zone_template = $_POST['zone_template'];
            }
            $current_zone_template = $_POST['current_zone_template'] ?? 0;

            if ($current_zone_template != $new_zone_template) {
                $this->dnsRecord->updateZoneRecords(
                    $this->config->get('database', 'type', 'mysql'),
                    $this->config->get('dns', 'ttl', 86400),
                    $zone_id,
                    $new_zone_template
                );
                $this->setMessage('zone_settings', 'success', _('Zone template has been changed successfully.'));
            }
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
}
