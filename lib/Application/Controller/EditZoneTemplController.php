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
 * Script that handles zone templates editing
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Valitron;

class EditZoneTemplController extends BaseController
{

    public function run(): void
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $owner = ZoneTemplate::get_zone_templ_is_owner($this->db, $zone_templ_id, $_SESSION['userid']);
        $perm_godlike = UserManager::verify_permission($this->db, 'user_is_ueberuser');
        $perm_master_add = UserManager::verify_permission($this->db, 'zone_master_add');

        $this->checkCondition(!($perm_godlike || $perm_master_add && $owner), _("You do not have the permission to delete zone templates."));

        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        if (ZoneTemplate::zone_templ_id_exists($this->db, $zone_templ_id) == "0") {
            $this->showError(_('There is no zone template with this ID.'));
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateZoneTemplate($zone_templ_id);
        }
        $this->showForm($zone_templ_id);
    }

    private function updateZoneTemplate(string $zone_templ_id): void
    {
        $owner = ZoneTemplate::get_zone_templ_is_owner($this->db, $zone_templ_id, $_SESSION['userid']);
        $perm_godlike = UserManager::verify_permission($this->db, 'user_is_ueberuser');

        if (isset($_POST['edit']) && ($owner || $perm_godlike)) {
            $this->updateZoneTemplateDetails($zone_templ_id);
        }

        // TODO: review this code
//        if (isset($_POST['save_as'])) {
//            if (ZoneTemplate::zone_templ_name_exists($_POST['templ_name'])) {
//                error(ERR_ZONE_TEMPL_EXIST);
//            } elseif ($_POST['templ_name'] == '') {
//                error(ERR_ZONE_TEMPL_IS_EMPTY);
//            } else {
//                ZoneTemplate::add_zone_templ_save_as($_POST['templ_name'], $_POST['templ_descr'], $_SESSION['userid'], $_POST['record']);
//                $this->setMessage('list_zone_templ', 'success', SUC_ZONE_TEMPL_ADD);
//                $this->redirect('index.php', ['page'=> 'list_zone_templ.php']);
//            }
//        }

        if (isset($_POST['update_zones'])) {
            $this->updateZoneRecords($zone_templ_id);
        }
    }

    private function showForm(string $zone_templ_id): void
    {
        $iface_rowamount = $this->config('iface_rowamount');
        $row_start = $this->getRowStart($iface_rowamount);
        $record_sort_by = $this->getSortBy('record_sort_by', ['name', 'type', 'content', 'ttl', 'prio']);
        $record_count = ZoneTemplate::count_zone_templ_records($this->db, $zone_templ_id);
        $templ_details = ZoneTemplate::get_zone_templ_details($this->db, $zone_templ_id);

        $this->render('edit_zone_templ.html', [
            'templ_details' => $templ_details,
            'pagination' => $this->createAndPresentPagination($record_count, $iface_rowamount, $zone_templ_id),
            'records' => ZoneTemplate::get_zone_templ_records($this->db, $zone_templ_id, $row_start, $iface_rowamount, $record_sort_by),
            'zone_templ_id' => $zone_templ_id,
            'perm_is_godlike' => UserManager::verify_permission($this->db, 'user_is_ueberuser'),
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage, int $id): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $presenter = new PaginationPresenter($pagination, 'index.php?page=edit_zone_templ&start={PageNumber}', $id);

        return $presenter->present();
    }

    public function getRowStart($rowAmount)
    {
        $row_start = 0;
        $start = filter_input(INPUT_GET, "start", FILTER_VALIDATE_INT);

        if ($start !== false && $start > 0) {
            $row_start = max(0, ($start - 1) * $rowAmount);
        }

        return $row_start;
    }

    public function getSortBy(string $name, array $allowedValues): string
    {
        $sortOrder = 'name';

        foreach ([$_GET, $_POST, $_SESSION] as $source) {
            if (isset($source[$name]) && in_array($source[$name], $allowedValues)) {
                $sortOrder = $source[$name];
                $_SESSION[$name] = $source[$name];
                break;
            }
        }

        return $sortOrder;
    }

    public function updateZoneTemplateDetails(string $zone_templ_id): void
    {
        if (!isset($_POST['templ_name']) || $_POST['templ_name'] == "") {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        ZoneTemplate::edit_zone_templ($this->db, $_POST, $zone_templ_id, $_SESSION['userid']);
        $this->setMessage('list_zone_templ', 'success', _('Zone template has been updated successfully.'));
        $this->redirect('index.php', ['page'=> 'list_zone_templ']);
    }

    public function updateZoneRecords(string $zone_templ_id): void
    {
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $zones = $zoneTemplate->get_list_zone_use_templ($zone_templ_id, $_SESSION['userid']);
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        foreach ($zones as $zone_id) {
            $dnsRecord->update_zone_records($this->config('db_type'), $this->config('dns_ttl'), $zone_id, $zone_templ_id);
        }
        $this->setMessage('edit_zone_templ', 'success', _('Zones have been updated successfully.'));
    }
}
