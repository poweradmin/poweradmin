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

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the delete-zone-template confirmation page and deletes the template on confirmed POST.
 */
class DeleteZoneTemplController extends BaseController
{
    private ZoneTemplateService $zoneTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->zoneTemplate = $this->services()->zoneTemplateService();
    }
    public function run(): void
    {
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

        $zone_templ_id = $this->getSafeRequestValue('id');
        $owner = $this->zoneTemplate->isUserOwnerOfTemplate((int)$zone_templ_id, (int)$this->getCurrentUserId());
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $perm_templ_edit = $this->hasPermission(Permission::PERM_ZONE_TEMPL_EDIT);

        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to delete zone templates."));

        if ($this->httpRequest->getPostParam('confirm') !== null) {
            $this->deleteZoneTempl();
        } else {
            $this->showDeleteZoneTempl();
        }
    }

    private function deleteZoneTempl(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if ($this->doValidateRequest($this->requestData)) {
            $zone_templ_id = $this->getSafeRequestValue('id');
            $deleted = $this->zoneTemplate->deleteZoneTempl((int)$zone_templ_id);
            if (!$deleted->success) {
                $this->addSystemMessage('error', (string)$deleted->message);
                $this->showDeleteZoneTempl();
                return;
            }

            $auditService = $this->services()->auditService();
            $auditService->logZoneTemplateDelete((int)$zone_templ_id);
            $this->setMessage('list_zone_templ', 'success', _('Zone template has been deleted successfully.'));
            $this->redirect('/zones/templates');
        } else {
            $this->showFirstValidationError($this->requestData);
        }
    }

    private function showDeleteZoneTempl(): void
    {
        $zone_templ_id = $this->getSafeRequestValue('id');
        $templ_details = $this->services()->zoneTemplateRepository()->getZoneTemplateDetails((int)$zone_templ_id) ?: [];

        $this->render('delete_zone_templ.html', [
            'templ_name' => $templ_details['name'],
            'zone_templ_id' => $zone_templ_id,
        ]);
    }
}
