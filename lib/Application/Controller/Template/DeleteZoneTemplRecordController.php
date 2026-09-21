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
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateSyncService;

/**
 * Handles the delete confirmation for a zone template record and marks the template modified after deletion.
 */
class DeleteZoneTemplRecordController extends BaseController
{
    private ZoneTemplateService $zoneTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->zoneTemplate = $this->services()->zoneTemplateService();
    }

    public function run(): void
    {
        $record_id = $this->requireNumericParam('id');
        $zone_templ_id = $this->requireNumericParam('template_id');

        $confirmed = $this->httpRequest->getPostParam('confirm') !== null;

        $owner = $this->zoneTemplate->isUserOwnerOfTemplate($zone_templ_id, (int)$this->getCurrentUserId());
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $perm_templ_edit = $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT);

        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to delete this record."));

        if ($confirmed) {
            $deleted = $this->zoneTemplate->deleteZoneTemplRecord($record_id, $zone_templ_id);
            if ($deleted->success) {
                // Mark template as modified to track sync status
                $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig());
                $syncService->markTemplateAsModified($zone_templ_id);

                $auditService = $this->services()->auditService();
                $auditService->logZoneTemplateRecordDelete($zone_templ_id, $record_id);

                $this->setMessage('edit_zone_templ', 'success', _('The record has been deleted successfully.'));
                $this->redirect('/zones/templates/' . $zone_templ_id . '/edit');
            } else {
                $this->setMessage('edit_zone_templ', 'error', (string)$deleted->message);
                $this->redirect('/zones/templates/' . $zone_templ_id . '/edit');
            }
        }

        $templ_details = $this->services()->zoneTemplateRepository()->getZoneTemplateDetails($zone_templ_id) ?: [];
        $record_info = $this->services()->zoneTemplateRepository()->getZoneTemplateRecordById($record_id, $zone_templ_id);

        // The lookup is scoped to the template, so a record id from another template comes back empty
        if (!$record_info) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $this->render('delete_zone_templ_record.html', [
            'record_id' => $record_id,
            'zone_templ_id' => $zone_templ_id,
            'templ_details' => $templ_details,
            'record_info' => $record_info,
        ]);
    }
}
