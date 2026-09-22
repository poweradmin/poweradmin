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

/**
 * Quick removal of a zone from group (from edit group page)
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;

class QuickRemoveGroupZoneController extends BaseController
{
    private ZoneGroupService $zoneGroupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->createUserGroupRepository();
        $zoneGroupRepository = $this->createZoneGroupRepository();
        $this->zoneGroupService = new ZoneGroupService(
            $zoneGroupRepository,
            $groupRepository,
            $this->createZoneRepository(),
            new ZoneOwnershipModeService($this->config)
        );
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage group zones
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!$this->createPermissionService()->isAdmin($userId)) {
            $this->setMessage('edit_group', 'error', _('You do not have permission to manage group zones.'));
            $this->redirect('/groups');
            return;
        }

        if (!$this->isPost()) {
            $this->setMessage('edit_group', 'error', _('Invalid request method.'));
            $this->redirect('/groups');
            return;
        }

        $this->validateCsrfToken();

        $groupId = isset($this->requestData['group_id']) ? (int)$this->requestData['group_id'] : 0;
        $zoneId = isset($this->requestData['zone_id']) ? (int)$this->requestData['zone_id'] : 0;

        if ($groupId <= 0 || $zoneId <= 0) {
            $this->setMessage('edit_group', 'error', _('Invalid group or zone ID.'));
            $this->redirect('/groups');
            return;
        }

        $refusal = $this->zoneGroupService->getGroupRemovalRefusal($zoneId, $groupId);
        if ($refusal !== null) {
            $this->setMessage('edit_group', 'error', $this->refusalMessage($refusal));
            $this->redirect('/groups/' . $groupId . '/edit');
            return;
        }

        try {
            $success = $this->zoneGroupService->removeGroupFromZone($zoneId, $groupId);

            if ($success) {
                $auditService = new AuditService($this->db);
                $auditService->logZoneGroupRemove($zoneId, (string)$zoneId, $groupId);
                $this->setMessage('edit_group', 'success', _('Zone removed from group successfully.'));
            } else {
                $this->setMessage('edit_group', 'warning', _('Zone was not owned by this group.'));
            }
        } catch (InvalidArgumentException $e) {
            $this->setMessage('edit_group', 'error', $e->getMessage());
        }

        $this->redirect('/groups/' . $groupId . '/edit');
    }

    private function refusalMessage(string $refusal): string
    {
        $ownershipMode = new ZoneOwnershipModeService($this->config);

        if ($refusal === ZoneGroupService::REFUSAL_LAST_GROUP_GROUPS_ONLY) {
            return _('Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.');
        }
        if ($refusal === ZoneGroupService::REFUSAL_USERS_ONLY_NO_USER_OWNERS) {
            return _('Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.');
        }

        if ($ownershipMode->isUserOwnerAllowed() && $ownershipMode->isGroupOwnerAllowed()) {
            $hint = _('Add another owner or a group first.');
        } elseif ($ownershipMode->isUserOwnerAllowed()) {
            $hint = _('Add another user owner first (zone ownership mode is users_only).');
        } else {
            $hint = _('Add a group first (zone ownership mode is groups_only).');
        }

        return _('Cannot remove the last owner: this would leave the zone with no ownership.') . ' ' . $hint;
    }
}
