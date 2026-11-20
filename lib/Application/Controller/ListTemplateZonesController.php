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

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Application\Service\ResultPaginator;

class ListTemplateZonesController extends BaseController
{
    public function run(): void
    {
        $perm_templ_edit = $this->hasPermission('zone_templ_edit');
        $perm_templ_add = $this->hasPermission('zone_templ_add');
        $perm_godlike = $this->hasPermission('user_is_ueberuser');

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

        if (!ZoneTemplate::zoneTemplIdExists($this->db, $zone_templ_id)) {
            $this->showError(_('Template does not exist.'));
            return;
        }

        $this->showZonesList($zone_templ_id);
    }

    /**
     * Blanks the owner columns on rows the ownership view level hides, like the zone lists:
     * every row at "none", the zones the caller does not own (directly or through a group) at "own".
     *
     * @param array<int, array<string, mixed>> $zones
     * @return array<int, array<string, mixed>>
     */
    public static function withVisibleOwners(array $zones, string $level, callable $ownsZone): array
    {
        if ($level === 'all') {
            return $zones;
        }

        foreach ($zones as $key => $zone) {
            if ($level === 'own' && $ownsZone((int)($zone['id'] ?? 0))) {
                continue;
            }
            $zones[$key]['owner_name'] = null;
            $zones[$key]['owner_fullname'] = null;
        }

        return $zones;
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
        $offset = ResultPaginator::offset($currentPage, $itemsPerPage);

        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig(), $this->createDnsBackendProvider());
        $template_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);

        // Get zones using this template with pagination
        $zones = $zoneTemplate->getZonesUsingTemplate($zone_templ_id, $_SESSION[SessionKeys::USERID]);

        // Template list visibility, plus a template one of the caller's zones is linked to
        $visible = $this->hasPermission('user_is_ueberuser')
            || (int)($template_details['owner'] ?? -1) === 0
            || $zoneTemplate->isUserOwnerOfTemplate($zone_templ_id, (int)$_SESSION['userid'])
            || $zones !== [];
        if (!$visible) {
            $this->showError(_('Template does not exist.'));
            return;
        }

        // Get total count of zones for pagination
        $totalZones = count($zones);

        // Apply pagination manually for now (ideally would be implemented in the model)
        $paginatedZones = self::withVisibleOwners(
            array_slice($zones, $offset, $itemsPerPage),
            Permission::getZoneOwnershipViewPermission($this->db),
            fn(int $zoneId): bool => $this->isZoneOwner($zoneId)
        );

        // Create pagination object and presenter
        $pagination = $paginationService->createPagination($totalZones, $itemsPerPage, $currentPage);
        $paginationHtml = '';

        if ($totalZones > $itemsPerPage) {
            $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
            $presenter = new PaginationPresenter(
                $pagination,
                $baseUrlPrefix . '/zones/templates/' . $zone_templ_id . '/zones?start={PageNumber}'
            );
            $paginationHtml = $presenter->present();
        }

        $this->render('list_template_zones.html', [
            'template' => $template_details,
            'zones' => $paginatedZones,
            'user_name' => $this->createUserRepository()->getFullNameById($_SESSION[SessionKeys::USERID]) ?: $_SESSION[SessionKeys::USERLOGIN],
            'pagination' => $paginationHtml,
            'total_zones' => $totalZones,
            'iface_rowamount' => $itemsPerPage
        ]);
    }
}
