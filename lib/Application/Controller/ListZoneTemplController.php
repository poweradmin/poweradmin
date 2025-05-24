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
 * Script that displays list of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;

class ListZoneTemplController extends BaseController
{
    private UserContextService $userContext;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContext = new UserContextService();
    }

    public function run(): void
    {
        // Only users with zone_master_add permission can view zone templates
        $hasPermission = UserManager::verifyPermission($this->db, 'zone_master_add') ||
                         UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        $this->checkCondition(!$hasPermission, _("You do not have the permission to view zone templates."));

        $this->showListZoneTempl();
    }

    private function showListZoneTempl(): void
    {
        $perm_zone_templ_add = UserManager::verifyPermission($this->db, 'zone_templ_add');
        $userId = $this->userContext->getLoggedInUserId();
        $userName = $this->userContext->getLoggedInUsername();

        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $templatesList = $zone_templates->getListZoneTempl($userId);

        // Get sync status for all templates
        $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig());
        $syncStatus = $syncService->getTemplateSyncStatus($userId);

        $this->render('list_zone_templ.html', [
            'perm_zone_templ_add' => $perm_zone_templ_add,
            'perm_zone_templ_edit' => UserManager::verifyPermission($this->db, 'zone_templ_edit'),
            'user_name' => UserManager::getFullnameFromUserId($this->db, $userId) ?: $userName,
            'zone_templates' => $templatesList,
            'sync_status' => $syncStatus,
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
        ]);
    }
}
