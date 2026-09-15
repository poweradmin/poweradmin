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
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-record form of a zone template and marks the template as modified on success.
 */
class AddZoneTemplRecordController extends BaseController
{
    private RecordTypeService $recordTypeService;
    private UserContextService $userContext;
    private ZoneTemplate $zoneTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->userContext = new UserContextService();
        $this->zoneTemplate = $this->createZoneTemplateModel();
    }

    public function run(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->requestData)) {
            $this->showFirstValidationError($this->requestData);
        }

        $zone_templ_id = (int)$this->getSafeRequestValue('id');
        $userId = $this->userContext->getLoggedInUserId();
        $owner = $this->zoneTemplate->isUserOwnerOfTemplate($zone_templ_id, $userId);
        $perm_godlike = $this->hasPermission('user_is_ueberuser');
        $perm_templ_edit = $this->hasPermission('zone_templ_edit');

        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to add records to zone templates."));

        if ($this->isPost()) {
            $this->validateCsrfToken();

            $constraints = [
                'name' => [
                    new Assert\NotBlank()
                ],
                'type' => [
                    new Assert\NotBlank()
                ],
                'content' => [
                    new Assert\NotBlank()
                ],
                'prio' => [
                    new Assert\NotBlank()
                ],
                'ttl' => [
                    new Assert\NotBlank()
                ]
            ];

            $this->setValidationConstraints($constraints);

            $postParams = $this->httpRequest->getPostParams();
            if ($this->doValidateRequest($postParams)) {
                $this->addZoneTemplRecord();
            } else {
                $this->showFirstValidationError($postParams);
            }
        } else {
            $this->showAddZoneTemplRecord();
        }
    }

    private function addZoneTemplRecord(): void
    {
        $zone_templ_id = (int)$this->getSafeRequestValue('id');
        $name = $this->httpRequest->getPostParam('name', "[ZONE]");
        $type = $this->httpRequest->getPostParam('type', "");
        $content = $this->httpRequest->getPostParam('content', "");
        $prio = $this->httpRequest->getPostParam('prio', 0);
        $dns_ttl = $this->config->get('dns', 'ttl', 3600);
        $ttl = $this->httpRequest->getPostParam('ttl', $dns_ttl);

        if ($this->zoneTemplate->addZoneTemplRecord($zone_templ_id, $name, $type, $content, $ttl, $prio)) {
            // Mark template as modified to track sync status
            $syncService = new ZoneTemplateSyncService($this->db, $this->getConfig(), $this->createDnsBackendProvider());
            $syncService->markTemplateAsModified($zone_templ_id);

            $auditService = $this->createAuditService();
            $auditService->logZoneTemplateRecordAdd($zone_templ_id, $name, $type);

            $this->setMessage('edit_zone_templ', 'success', 'The record was successfully added.');
            $this->redirect('/zones/templates/' . $zone_templ_id . '/edit');
        } else {
            $this->showAddZoneTemplRecord();
        }
    }

    private function showAddZoneTemplRecord(): void
    {
        $zone_templ_id = (int)$this->getSafeRequestValue('id');
        $templ_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);
        $name = $this->httpRequest->getPostParam('name', "[ZONE]");
        $type = $this->httpRequest->getPostParam('type', "");
        $content = $this->httpRequest->getPostParam('content', "");
        $prio = $this->httpRequest->getPostParam('prio', 0);
        $dns_ttl = $this->config->get('dns', 'ttl', 3600);
        $ttl = $this->httpRequest->getPostParam('ttl', $dns_ttl);

        // Get count of zones using this template
        $userId = $this->userContext->getLoggedInUserId();
        $linked_zones = $this->zoneTemplate->getListZoneUseTempl($zone_templ_id, $userId);
        $zones_linked_count = count($linked_zones);

        $this->render('add_zone_templ_record.html', [
            'templ_name' => $templ_details['name'],
            'zone_templ_id' => $zone_templ_id,
            'name' => htmlspecialchars($name),
            'type' => htmlspecialchars($type),
            'record_types' => $this->recordTypeService->getAllTypes($this->getRecordTypeCapabilities()),
            'content' => htmlspecialchars($content),
            'prio' => htmlspecialchars($prio),
            'ttl' => htmlspecialchars($ttl),
            'zones_linked_count' => $zones_linked_count,
        ]);
    }
}
