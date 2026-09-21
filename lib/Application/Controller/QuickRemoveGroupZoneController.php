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

use InvalidArgumentException;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\Permission;

/**
 * Handles the POST that removes one zone from a group from the edit-group page.
 */
class QuickRemoveGroupZoneController extends BaseController
{
    private ZoneGroupService $zoneGroupService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->services()->userGroupRepository();
        $zoneRepository = $this->services()->zoneGroupRepository();
        $this->zoneGroupService = new ZoneGroupService($zoneRepository, $groupRepository);
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can manage group zones; denials are audit-logged
        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have permission to manage group zones.'));

        if (!$this->isPost()) {
            $this->setMessage('edit_group', 'error', _('Invalid request method.'));
            $this->redirect('/groups');
            return;
        }

        $groupId = isset($this->requestData['group_id']) ? (int)$this->requestData['group_id'] : 0;
        $zoneId = isset($this->requestData['zone_id']) ? (int)$this->requestData['zone_id'] : 0;

        if ($groupId <= 0 || $zoneId <= 0) {
            $this->setMessage('edit_group', 'error', _('Invalid group or zone ID.'));
            $this->redirect('/groups');
            return;
        }

        try {
            $success = $this->zoneGroupService->removeGroupFromZone($zoneId, $groupId);

            if ($success) {
                $auditService = $this->services()->auditService();
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
}
