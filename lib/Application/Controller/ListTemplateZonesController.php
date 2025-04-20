<?php

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class ListTemplateZonesController extends BaseController
{
    public function run(): void
    {
        $this->checkPermission('zone_master_add', _('You do not have permission to view zone templates.'));

        $zone_templ_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$zone_templ_id) {
            $this->showError(_('Invalid template ID.'));
            return;
        }

        if (!ZoneTemplate::zone_templ_id_exists($this->db, $zone_templ_id)) {
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
        $paginationService = new PaginationService();
        $itemsPerPage = $paginationService->getUserRowsPerPage($default_rowamount);

        // Get the current page from request
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();
        $offset = ($currentPage - 1) * $itemsPerPage;

        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $template_details = ZoneTemplate::get_zone_templ_details($this->db, $zone_templ_id);

        // Get zones using this template with pagination
        $zones = $zoneTemplate->get_zones_using_template($zone_templ_id, $_SESSION['userid']);

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
                'index.php?page=list_template_zones&id=' . $zone_templ_id . '&start={PageNumber}'
            );
            $paginationHtml = $presenter->present();
        }

        $this->render('list_template_zones.html', [
            'template' => $template_details,
            'zones' => $paginatedZones,
            'user_name' => UserManager::get_fullname_from_userid($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
            'pagination' => $paginationHtml,
            'total_zones' => $totalZones,
            'iface_rowamount' => $itemsPerPage
        ]);
    }
}
