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

/**
 * Renders the list of zones linked to one zone template.
 */
class ListTemplateZonesController extends BaseController
{
    public function run(): void
    {
        $perm_templ_edit = $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT);
        $perm_templ_add = $this->hasPermission(Permission::PERM_ZONE_TEMPL_ADD);
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);

        $this->checkCondition(!($perm_godlike || $perm_templ_edit || $perm_templ_add), _('You do not have permission to view zone templates.'));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('list_template_zones');
        $this->setPageTitle(_('Template Zones'));

        $id = $this->getSafeRequestValue('id');
        if (empty($id)) {
            $this->showError(_('Invalid template ID.'));
            return;
        }

        $zone_templ_id = (int)$id;

        if (!$this->services()->zoneTemplateRepository()->zoneTemplateExists($zone_templ_id)) {
            $this->showError(_('Template does not exist.'));
            return;
        }

        $this->showZonesList($zone_templ_id);
    }

    private function showZonesList(int $zone_templ_id): void
    {
        $itemsPerPage = $this->resolveRowsPerPage();
        $currentPage = $this->httpRequest->getPage();
        $offset = ($currentPage - 1) * $itemsPerPage;

        $zoneTemplate = $this->services()->zoneTemplateService();
        $template_details = $this->services()->zoneTemplateRepository()->getZoneTemplateDetails($zone_templ_id) ?: [];

        // Get zones using this template with pagination
        $zones = $zoneTemplate->getZonesUsingTemplate($zone_templ_id, (int)$this->getCurrentUserId());

        // Get total count of zones for pagination
        $totalZones = count($zones);

        // Apply pagination manually for now (ideally would be implemented in the model)
        $paginatedZones = array_slice($zones, $offset, $itemsPerPage);

        $this->render('list_template_zones.html', [
            'template' => $template_details,
            'zones' => $paginatedZones,
            'user_name' => $this->services()->userRepository()->getFullNameById((int)$this->getCurrentUserId()) ?: $this->getUserContextService()->getLoggedInUsername(),
            'pagination' => $this->presentPagination($totalZones, $itemsPerPage, '/zones/templates/' . $zone_templ_id . '/zones?start={PageNumber}'),
            'total_zones' => $totalZones,
            'iface_rowamount' => $itemsPerPage
        ]);
    }
}
