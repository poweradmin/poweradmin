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

namespace Poweradmin\Application\Controller\Template;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\UserContextService;

/**
 * Renders the zone templates list at /zones/templates.
 */
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
        // Only users with zone_templ_add or zone_templ_edit permission can view zone templates
        $hasPermission = $this->hasPermission(Permission::PERM_ZONE_TEMPL_ADD) ||
                         $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT) ||
                         $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);

        $this->checkCondition(!$hasPermission, _("You do not have permission to view zone templates."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_zone_templ');
        $this->setPageTitle(_('Zone templates'));

        $this->showListZoneTempl();
    }

    private function showListZoneTempl(): void
    {
        $perm_zone_templ_add = $this->hasPermission(Permission::PERM_ZONE_TEMPL_ADD);
        $userId = $this->userContext->getLoggedInUserId();
        $userName = $this->userContext->getLoggedInUsername();

        $zone_templates = $this->services()->zoneTemplateService();
        $templatesList = $zone_templates->getListZoneTempl($userId);

        // Get sync status for all templates
        $syncService = $this->services()->zoneTemplateSync();
        $syncStatus = $syncService->getTemplateSyncStatus($userId);

        $effectiveDefaultId = $zone_templates->getDefaultTemplateId();
        $hasDbDefault = false;
        foreach ($templatesList as $row) {
            if ($row['is_default'] === true) {
                $hasDbDefault = true;
                break;
            }
        }

        $this->render('list_zone_templ.html', [
            'perm_zone_templ_add' => $perm_zone_templ_add,
            'perm_zone_templ_edit' => $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT),
            'user_name' => $this->services()->userRepository()->getFullNameById($userId) ?: $userName,
            'zone_templates' => $templatesList,
            'sync_status' => $syncStatus,
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'effective_default_id' => $effectiveDefaultId,
            'has_db_default' => $hasDbDefault,
        ]);
    }
}
