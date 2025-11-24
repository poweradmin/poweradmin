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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListTemplateZonesController extends BaseController
{
    public function run(): void
    {
        $perm_templ_edit = UserManager::verifyPermission($this->db, 'zone_templ_edit');
        $perm_templ_add = UserManager::verifyPermission($this->db, 'zone_templ_add');
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        $this->checkCondition(!($perm_godlike || $perm_templ_edit || $perm_templ_add), _('You do not have permission to view zone templates.'));

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'list_template_zones';

        $id = $this->getSafeRequestValue('id');
        if (empty($id)) {
            $this->showError(_('Invalid template ID.'));
            return;
        }

        $zone_templ_id = (int)$id;

        if (!ZoneTemplate::zoneTemplIdExists($this->db, $zone_templ_id)) {
            $this->showError(_('Template does not exist.'));
            return;
        }

        $this->showZonesList($zone_templ_id);
    }

    private function showZonesList(int $zone_templ_id): void
    {
        // Get default rows per page from config
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);

        // Create pagination service and get user preference
        $paginationService = $this->createPaginationService();
        $userId = $this->getCurrentUserId();
        $itemsPerPage = $paginationService->getUserRowsPerPage($default_rowamount, $userId);

        // Get the current page from request
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();
        $offset = ($currentPage - 1) * $itemsPerPage;

        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $template_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);

        // Get zones using this template with pagination
        $zones = $zoneTemplate->getZonesUsingTemplate($zone_templ_id, $_SESSION['userid']);

        // Get total count of zones for pagination
        $totalZones = count($zones);

        // Apply pagination manually for now (ideally would be implemented in the model)
        $paginatedZones = array_slice($zones, $offset, $itemsPerPage);

        // Create pagination object and presenter
        $pagination = $paginationService->createPagination($totalZones, $itemsPerPage, $currentPage);
        $paginationHtml = '';

        if ($totalZones > $itemsPerPage) {
            $presenter = new PaginationPresenter(
                $pagination,
                '/zones/templates/' . $zone_templ_id . '/zones?start={PageNumber}'
            );
            $paginationHtml = $presenter->present();
        }

        $this->render('list_template_zones.html', [
            'template' => $template_details,
            'zones' => $paginatedZones,
            'user_name' => UserManager::getFullnameFromUserId($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
            'pagination' => $paginationHtml,
            'total_zones' => $totalZones,
            'iface_rowamount' => $itemsPerPage
        ]);
    }
}
