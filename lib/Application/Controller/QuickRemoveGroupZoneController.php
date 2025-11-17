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
 * Quick removal of a zone from group (from edit group page)
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Service\GroupZoneService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupZoneRepository;

class QuickRemoveGroupZoneController extends BaseController
{
    private GroupZoneService $groupZoneService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $zoneRepository = new DbUserGroupZoneRepository($this->db);
        $this->groupZoneService = new GroupZoneService($zoneRepository, $groupRepository);
    }

    public function run(): void
    {
        // Only admin (überuser) can manage group zones
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
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

        try {
            $success = $this->groupZoneService->removeZoneFromGroup($groupId, $zoneId);

            if ($success) {
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
