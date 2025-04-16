<?php

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;

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
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $template_details = ZoneTemplate::get_zone_templ_details($this->db, $zone_templ_id);

        // Get zones using this template
        $zones = $zoneTemplate->get_zones_using_template($zone_templ_id, $_SESSION['userid']);

        $this->render('list_template_zones.html', [
            'template' => $template_details,
            'zones' => $zones,
            'user_name' => UserManager::get_fullname_from_userid($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
        ]);
    }
}
