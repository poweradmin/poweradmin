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
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Symfony\Component\Validator\Constraints as Assert;

class EditZoneTemplController extends BaseController
{
    private UserContextService $userContext;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContext = new UserContextService();
    }

    public function run(): void
    {
        if (!isset($_GET['id']) || empty($_GET['id'])) {
            $this->showError(_('No template ID provided.'));
            return;
        }

        $zone_templ_id = (int)$_GET['id'];
        $userId = $this->userContext->getLoggedInUserId();
        $owner = ZoneTemplate::getZoneTemplIsOwner($this->db, $zone_templ_id, $userId);
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $perm_templ_edit = UserManager::verifyPermission($this->db, 'zone_templ_edit');

        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to edit zone templates."));

        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }

        if (ZoneTemplate::zoneTemplIdExists($this->db, $zone_templ_id) == "0") {
            $this->showError(_('There is no zone template with this ID.'));
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateZoneTemplate($zone_templ_id);
        }
        $this->showForm($zone_templ_id);
    }

    private function updateZoneTemplate(int $zone_templ_id): void
    {
        $userId = $this->userContext->getLoggedInUserId();
        $owner = ZoneTemplate::getZoneTemplIsOwner($this->db, $zone_templ_id, $userId);
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        if (isset($_POST['edit']) && ($owner || $perm_godlike)) {
            $this->updateZoneTemplateDetails($zone_templ_id);
        }

        if (isset($_POST['save_as'])) {
            $this->saveTemplateAs($zone_templ_id);
        }

        if (isset($_POST['update_zones'])) {
            $this->updateZoneRecords($zone_templ_id);
        }
    }

    private function showForm(int $zone_templ_id): void
    {
        $iface_rowamount = $this->config->get('interface', 'rows_per_page', 10);
        $row_start = $this->getRowStart($iface_rowamount);
        $record_sort_by = $this->getSortBy('record_sort_by', ['name', 'type', 'content', 'ttl', 'prio']);
        $record_count = ZoneTemplate::countZoneTemplRecords($this->db, $zone_templ_id);
        $templ_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);

        // Get count of zones using this template
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $userId = $this->userContext->getLoggedInUserId();
        $linked_zones = $zoneTemplate->getListZoneUseTempl($zone_templ_id, $userId);
        $zones_linked_count = count($linked_zones);

        // Get sync status
        $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig());
        $unsynced_zones_count = $syncService->getUnsyncedZoneCount($zone_templ_id);

        $this->render('edit_zone_templ.html', [
            'templ_details' => $templ_details,
            'pagination' => $this->createAndPresentPagination($record_count, $iface_rowamount, $zone_templ_id),
            'records' => ZoneTemplate::getZoneTemplRecords($this->db, $zone_templ_id, $row_start, $iface_rowamount, $record_sort_by),
            'zone_templ_id' => $zone_templ_id,
            'zones_linked_count' => $zones_linked_count,
            'unsynced_zones_count' => $unsynced_zones_count,
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
            'perm_zone_templ_add' => UserManager::verifyPermission($this->db, 'zone_templ_add'),
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

    public function updateZoneTemplateDetails(int $zone_templ_id): void
    {
        $constraints = [
            'templ_name' => [
                new Assert\NotBlank()
            ],
            'templ_descr' => [
                new Assert\Length(['max' => 1024])
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return;
        }

        $zoneTemplate = new ZoneTemplate($this->db, $this->config);
        $userId = $this->userContext->getLoggedInUserId();
        $zoneTemplate->editZoneTempl($_POST, $zone_templ_id, $userId);
        $this->setMessage('list_zone_templ', 'success', _('Zone template has been updated successfully.'));
        $this->redirect('index.php', ['page' => 'list_zone_templ']);
    }

    public function updateZoneRecords(int $zone_templ_id): void
    {
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $userId = $this->userContext->getLoggedInUserId();
        $zones = $zoneTemplate->getListZoneUseTempl($zone_templ_id, $userId);
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig());

        foreach ($zones as $zone_id) {
            $dnsRecord->updateZoneRecords($this->config->get('database', 'type', 'mysql'), $this->config->get('dns', 'ttl', 86400), $zone_id, $zone_templ_id);
        }

        // Mark all zones as synced
        $syncService->markZonesAsSynced($zones, $zone_templ_id);

        $this->setMessage('edit_zone_templ', 'success', _('Zones have been updated successfully.'));
    }

    private function saveTemplateAs(int $zone_templ_id): void
    {
        // Check if user has permission to add templates
        if (
            !(UserManager::verifyPermission($this->db, 'zone_templ_add') ||
              UserManager::verifyPermission($this->db, 'user_is_ueberuser'))
        ) {
            $this->showError(_('You do not have permission to create new zone templates.'));
            return;
        }

        $constraints = [
            'templ_name' => [
                new Assert\NotBlank()
            ],
            'templ_descr' => [
                new Assert\Length(['max' => 1024])
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return;
        }

        $zoneTemplate = new ZoneTemplate($this->db, $this->config);
        $templateExists = $zoneTemplate->zoneTemplNameExists($_POST['templ_name']);
        $currentTemplate = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);

        if ($templateExists) {
            $this->showError(_('Zone template with this name already exists, please choose another one.'));
            return;
        }

        // Don't allow saving with the same name
        if ($_POST['templ_name'] === $currentTemplate['name']) {
            $this->showError(_('Please enter a different name when using Save As.'));
            return;
        }

        // Get records from the current template
        $records = ZoneTemplate::getZoneTemplRecords($this->db, $zone_templ_id);

        // For a simple "save as" with no domain substitution
        $options = [];
        if (isset($_POST['templ_global'])) {
            $options['global'] = true;
        }

        // Call the addZoneTemplSaveAs with the correct signature
        $success = $zoneTemplate->addZoneTemplSaveAs(
            $_POST['templ_name'],
            $_POST['templ_descr'],
            $_SESSION['userid'],
            $records,
            $options,
            '' // Empty domain since we're not doing domain substitution
        );

        if ($success) {
            $this->setMessage('list_zone_templ', 'success', _('Zone template has been copied successfully.'));
            $this->redirect('index.php', ['page' => 'list_zone_templ']);
        }
    }
}
