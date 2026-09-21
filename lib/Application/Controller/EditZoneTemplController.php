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

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplateService;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Renders the zone template edit page and handles detail, record, save-as and linked-zone updates.
 */
class EditZoneTemplController extends BaseController
{
    private UserContextService $userContext;
    private ZoneTemplateService $zoneTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContext = new UserContextService();
        $this->zoneTemplate = $this->services()->zoneTemplateService();
    }

    public function run(): void
    {
        $id = $this->getSafeRequestValue('id');
        if (empty($id)) {
            $this->showError(_('No template ID provided.'));
            return;
        }

        $zone_templ_id = (int)$id;
        $userId = $this->userContext->getLoggedInUserId();
        $owner = $this->zoneTemplate->isUserOwnerOfTemplate($zone_templ_id, $userId);
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $perm_templ_edit = $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT);

        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to edit zone templates."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit_zone_templ');
        $this->setPageTitle(_('Edit Zone Template'));

        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->requestData)) {
            $this->showFirstValidationError($this->requestData);
        }

        if (!$this->services()->zoneTemplateRepository()->zoneTemplateExists($zone_templ_id)) {
            $this->showError(_('There is no zone template with this ID.'));
        }

        if ($this->isPost()) {
            $this->updateZoneTemplate($zone_templ_id);
        }
        $this->showForm($zone_templ_id);
    }

    private function updateZoneTemplate(int $zone_templ_id): void
    {
        $userId = $this->userContext->getLoggedInUserId();
        $owner = $this->zoneTemplate->isUserOwnerOfTemplate($zone_templ_id, $userId);
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);

        if ($this->httpRequest->getPostParam('edit') !== null && ($owner || $perm_godlike)) {
            $this->updateZoneTemplateDetails($zone_templ_id);
        }

        if ($this->httpRequest->getPostParam('save_as') !== null) {
            $this->saveTemplateAs($zone_templ_id);
        }

        if ($this->httpRequest->getPostParam('update_zones') !== null) {
            $this->updateZoneRecords($zone_templ_id);
        }
    }

    private function showForm(int $zone_templ_id): void
    {
        $iface_rowamount = $this->resolveRowsPerPage();
        $row_start = $this->getRowStart($iface_rowamount);
        [$record_sort_by] = $this->services()->zoneSortingService($this->userContext)->getZoneSortOrder(
            ['name', 'type', 'content', 'ttl', 'prio'],
            SessionKeys::ZONE_TEMPL_RECORD_SORT_BY,
            submittedSortBy: $this->httpRequest->getPostParam('record_sort_by') ?? $this->httpRequest->getQueryParam('record_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('record_sort_by_direction') ?? $this->httpRequest->getQueryParam('record_sort_by_direction')
        );
        $templates = $this->services()->zoneTemplateRepository();
        $record_count = $templates->countZoneTemplateRecords($zone_templ_id);
        $templ_details = $templates->getZoneTemplateDetails($zone_templ_id) ?: [];

        // Get count of zones using this template
        $userId = $this->userContext->getLoggedInUserId();
        $linked_zones = $this->zoneTemplate->getListZoneUseTempl($zone_templ_id, $userId);
        $zones_linked_count = count($linked_zones);

        // Get sync status
        $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig(), $this->services()->dnsBackendProvider());
        $unsynced_zones_count = $syncService->getUnsyncedZoneCount($zone_templ_id);

        $this->render('edit_zone_templ.html', [
            'templ_details' => $templ_details,
            'pagination' => $this->presentPagination($record_count, $iface_rowamount, '/zones/templates/' . $zone_templ_id . '/edit?start={PageNumber}', ['id' => $zone_templ_id]),
            'records' => $templates->getZoneTemplateRecords($zone_templ_id, $row_start, $iface_rowamount, $record_sort_by),
            'zone_templ_id' => $zone_templ_id,
            'zones_linked_count' => $zones_linked_count,
            'unsynced_zones_count' => $unsynced_zones_count,
            'perm_is_godlike' => $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER),
            'perm_zone_templ_add' => $this->hasPermission(Permission::PERM_ZONE_TEMPL_ADD),
        ]);
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

    public function updateZoneTemplateDetails(int $zone_templ_id): void
    {
        $constraints = [
            'templ_name' => [
                new Assert\NotBlank()
            ],
            'templ_descr' => [
                new Assert\Length(max: 1024)
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
            return;
        }

        $userId = $this->userContext->getLoggedInUserId();
        $edited = $this->zoneTemplate->editZoneTempl($postParams, $zone_templ_id, $userId);
        if (!$edited->success) {
            $this->addSystemMessage('error', (string)$edited->message);
            return;
        }
        $auditService = $this->services()->auditService();
        $auditService->logZoneTemplateEdit($zone_templ_id, $postParams['templ_name'] ?? '');
        $this->setMessage('list_zone_templ', 'success', _('Zone template has been updated successfully.'));
        $this->redirect('/zones/templates');
    }

    public function updateZoneRecords(int $zone_templ_id): void
    {
        $userId = $this->userContext->getLoggedInUserId();
        $zones = $this->zoneTemplate->getZoneAndDomainIdsByTemplate($zone_templ_id, $userId);
        $domainManager = $this->services()->domainManager();
        $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig(), $this->services()->dnsBackendProvider());

        $defaultTtl = $this->config->get('dns', 'ttl', 86400);
        $syncedZoneIds = [];
        $failures = [];
        foreach ($zones as $zone) {
            // PowerDNS record updates use domain_id; sync tracking uses Poweradmin zones.id.
            // Only mark a zone as synced once its records actually took, otherwise a
            // failed zone is recorded as up to date and never retried.
            $updated = $domainManager->updateZoneRecords($defaultTtl, $zone['domain_id'], $zone_templ_id);
            if ($updated->success) {
                $syncedZoneIds[] = $zone['zone_id'];
            } else {
                $failures[] = (string)$updated->message;
            }
        }

        $syncService->markZonesAsSynced($syncedZoneIds, $zone_templ_id);

        if ($failures !== []) {
            $this->setMessage('edit_zone_templ', 'warning', sprintf(
                ngettext(
                    '%d zone could not be updated.',
                    '%d zones could not be updated.',
                    count($failures)
                ),
                count($failures)
            ));
            foreach (array_unique($failures) as $reason) {
                $this->addSystemMessage('error', $reason);
            }
        } else {
            $this->setMessage('edit_zone_templ', 'success', _('Zones have been updated successfully.'));
        }
        $this->redirect('/zones/templates/' . $zone_templ_id . '/edit');
    }

    private function saveTemplateAs(int $zone_templ_id): void
    {
        // Check if user has permission to add templates
        if (
            !($this->hasPermission(Permission::PERM_ZONE_TEMPL_ADD) ||
              $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER))
        ) {
            $this->showError(_('You do not have permission to create new zone templates.'));
            return;
        }

        $constraints = [
            'templ_name' => [
                new Assert\NotBlank()
            ],
            'templ_descr' => [
                new Assert\Length(max: 1024)
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
            return;
        }

        $templateExists = $this->zoneTemplate->zoneTemplNameExists($postParams['templ_name']);
        $currentTemplate = $this->services()->zoneTemplateRepository()->getZoneTemplateDetails($zone_templ_id) ?: [];

        if ($templateExists) {
            $this->showError(_('Zone template with this name already exists, please choose another one.'));
            return;
        }

        // Don't allow saving with the same name
        if ($postParams['templ_name'] === $currentTemplate['name']) {
            $this->showError(_('Please enter a different name when using Save As.'));
            return;
        }

        // Get records from the current template
        $records = $this->services()->zoneTemplateRepository()->getZoneTemplateRecords($zone_templ_id);

        // For a simple "save as" with no domain substitution
        $options = [];
        if (isset($postParams['templ_global'])) {
            $options['global'] = true;
        }

        // Call the addZoneTemplSaveAs with the correct signature
        $saved = $this->zoneTemplate->addZoneTemplSaveAs(
            $postParams['templ_name'],
            $postParams['templ_descr'],
            (int)$this->getCurrentUserId(),
            $records,
            $options,
            '' // Empty domain since we're not doing domain substitution
        );

        if (!$saved->success) {
            $this->addSystemMessage('error', (string)$saved->message);
            return;
        }
        if ($saved->message !== null) {
            $this->setMessage('list_zone_templ', 'warning', $saved->message);
        }
        $this->setMessage('list_zone_templ', 'success', _('Zone template has been copied successfully.'));
        $this->redirect('/zones/templates');
    }
}
