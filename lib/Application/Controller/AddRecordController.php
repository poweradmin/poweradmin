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

/**
 * Script that handles request to add new records to existing zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\RecordAddMessages;
use Poweradmin\Application\Service\RecordAddResult;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Domain\Enum\AccessScope;

class AddRecordController extends BaseController
{
    private DomainRepositoryInterface $domainRepository;
    private RecordAddService $recordAdd;
    private RecordTypeService $recordTypeService;
    private FormStateService $formStateService;
    private UserContextService $userContextService;
    private ReverseTtlResolver $reverseTtlResolver;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->formStateService = new FormStateService();
        $this->domainRepository = $this->createDomainRepository();
        $this->recordAdd = $this->createRecordAddService();
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->reverseTtlResolver = $this->createReverseTtlResolver();
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->checkId();

        $perm_edit = $this->createPermissionService()->getEditPermissionLevel((int)$this->getCurrentUserId());
        $zone_id = (int)$this->getSafeRequestValue('zone_id');
        $zone_type = $this->domainRepository->getDomainType($zone_id);
        $user_is_zone_owner = $this->isZoneOwner($zone_id);

        $this->checkCondition(ZoneType::isReadOnly($zone_type)
            || $perm_edit == "none"
            || AccessScope::fromString($perm_edit)->isOwnedOnly()
            && !$user_is_zone_owner, _("You do not have the permission to add a record to this zone."));

        if ($this->isPost()) {
            $this->validateCsrfToken();

            if ($this->request->getPostParam('multi_record_mode') !== null && is_array($this->request->getPostParam('records'))) {
                $this->addMultipleRecords();
            } else {
                $this->addRecord();
            }
        }
        $this->showForm();
    }

    private function addRecord(): void
    {
        // These are required fields
        $constraints = [
            'content' => [
                new Assert\NotBlank()
            ],
            'type' => [
                new Assert\NotBlank()
            ]
        ];

        // Optional fields won't be validated if they're empty due to the filter in BaseController

        $this->setValidationConstraints($constraints);

        $postParams = $this->request->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
        }

        $name = (string)$this->request->getPostParam('name', '');
        $content = (string)$this->request->getPostParam('content');
        $type = (string)$this->request->getPostParam('type');
        $prio = $this->request->getPostParam('prio');
        $prio = $prio !== null && $prio !== '' ? (int)$prio : 0;
        $comment = (string)$this->request->getPostParam('comment', '');
        $zone_id = (int)$this->getSafeRequestValue('zone_id');

        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }
        $ttl = $this->request->getPostParam('ttl');

        $added = $this->recordAdd->add(
            $zone_id,
            $zone_name,
            $name,
            $type,
            $content,
            $ttl !== null && $ttl !== '' ? (int)$ttl : null,
            $prio,
            $comment,
            (string)$this->userContextService->getLoggedInUsername(),
            RecordAddResult::companionFrom($this->request->getPostParams())
        );
        if (!$added->isOk()) {
            // Keep the submitted values and point at the field the reason names
            $formId = $this->formStateService->generateFormId('add_record');
            $this->formStateService->saveFormData($formId, [
                'name' => $name,
                'content' => $content,
                'type' => $type,
                'prio' => $prio,
                'ttl' => $ttl,
                'comment' => $comment,
                'error' => true,
                'errorMessage' => $added->record->message,
                'fieldError' => $added->record->field,
            ]);

            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        // Clear form data if it exists in the session
        $formToken = $this->request->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService->clearFormData($formToken);
        }

        [$messageType, $message] = RecordAddMessages::forAdded($added);
        $this->setMessage('edit', $messageType, $message);

        // Redirect back to zone edit page
        $this->redirect('/zones/' . $zone_id . '/edit');
    }

    private function showForm(): void
    {
        $zone_id = (int)$this->getSafeRequestValue('zone_id');
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        $isReverseZone = DnsHelper::isReverseZoneName($zone_name);

        // Pre-fill with the plain dns.ttl; JS updateTtlForType() swaps in dns.ttl_reverse
        // when the user selects PTR on a reverse zone, keeping the form consistent with
        // what the backend will actually persist.
        $ttl = $this->reverseTtlResolver->getForwardTtl();
        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        // Retrieve form state data from session (e.g. after validation error redirect)
        $formData = null;
        $formId = $this->request->getQueryParam('form_id');
        if ($formId) {
            $formData = $this->formStateService->getFormData($formId);
        }

        // Build saved_records array for multi-row restore
        $savedRecords = [];
        if ($formData && isset($formData['saved_records']) && is_array($formData['saved_records'])) {
            $savedRecords = $formData['saved_records'];
        }

        $offeredTypes = $isReverseZone
            ? $this->recordTypeService->getReverseZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities(), false)
            : $this->recordTypeService->getDomainZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities(), false);

        // Offer only what this caller may actually submit, so a restricted type is not
        // presented and then refused on save.
        $permEdit = $this->createPermissionService()->getEditPermissionLevel((int)$this->getCurrentUserId());
        $offeredTypes = array_values(array_filter(
            $offeredTypes,
            fn(string $type): bool => !Permission::isRecordTypeRestrictedForClient($type, $permEdit)
        ));

        $this->render('add_record.html', [
            'types' => $offeredTypes,
            'deprecated_types' => RecordType::DEPRECATED_TYPES,
            'name' => $formData['name'] ?? $this->request->getPostParam('name', ''),
            'type' => $formData['type'] ?? $this->request->getPostParam('type', ''),
            'content' => $formData['content'] ?? $this->request->getPostParam('content', ''),
            'ttl' => $formData['ttl'] ?? $this->request->getPostParam('ttl', $ttl),
            'default_ttl' => $this->reverseTtlResolver->getForwardTtl(),
            'ptr_default_ttl' => $this->reverseTtlResolver->getConfiguredReverseTtl(),
            'type_default_ttls' => $this->reverseTtlResolver->getTypeDefaults(),
            'prio' => $formData['prio'] ?? $this->request->getPostParam('prio', 0),
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'is_reverse_zone' => $isReverseZone,
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', true),
            'display_hostname_only' => $this->createUserPreferenceService()->getDisplayHostnameOnly(
                $this->userContextService->getLoggedInUserId()
            ),
            'form_data' => $formData,
            'saved_records' => $savedRecords,
        ]);
    }

    public function checkId(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->request->getQueryParams())) {
            $this->showFirstValidationError($this->request->getQueryParams());
        }
    }

    private function addMultipleRecords(): void
    {
        $zone_id = (int)$this->getSafeRequestValue('zone_id');
        $records = $this->request->getPostParam('records', []);
        $successCount = 0;
        $failureReasons = [];
        $matchingRecordCount = 0;
        $ptrWarnings = [];
        $formId = $this->formStateService->generateFormId('add_record');

        if (empty($records)) {
            $formData = [
                'error' => true,
                'errorMessage' => _('No records were provided.'),
            ];
            $this->formStateService->saveFormData($formId, $formData);
            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }
        $username = (string)$this->userContextService->getLoggedInUsername();

        foreach ($records as $record) {
            // Skip non-array or incomplete records
            if (!is_array($record) || empty($record['content']) || empty($record['type'])) {
                continue;
            }

            $added = $this->recordAdd->add(
                $zone_id,
                $zone_name,
                (string)($record['name'] ?? ''),
                (string)$record['type'],
                (string)$record['content'],
                isset($record['ttl']) && $record['ttl'] !== '' ? (int)$record['ttl'] : null,
                isset($record['prio']) && $record['prio'] !== '' ? (int)$record['prio'] : 0,
                (string)($record['comment'] ?? ''),
                $username,
                RecordAddResult::companionFrom($record)
            );
            if (!$added->isOk()) {
                $failureReasons[] = $added->record->message;
                continue;
            }

            $successCount++;
            if ($added->companionCreated) {
                $matchingRecordCount++;
            }
            if ($added->companionWarning) {
                $ptrWarnings[] = $added->companionMessage;
            }
        }

        // Clear form data if it exists in the session
        $formToken = $this->request->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService->clearFormData($formToken);
        }

        if ($successCount > 0) {
            $message = sprintf(_('%d record(s) have been added successfully.'), $successCount);
            if ($matchingRecordCount > 0) {
                $message .= ' ' . sprintf(_('%d matching record(s) were also created.'), $matchingRecordCount);
            }
            if ($failureReasons !== []) {
                $message .= ' ' . sprintf(_('%d record(s) failed to be added.'), count($failureReasons));
                $errorMessage = end($failureReasons);

                // Store form data with error flag for failed records
                $formId = $this->formStateService->generateFormId('add_record');
                $formData = [
                    'error' => true,
                    'multi_record_error' => true,
                    'failure_count' => count($failureReasons),
                    'errorMessage' => $errorMessage
                ];
                $this->formStateService->saveFormData($formId, $formData);

                // The form_data alert only renders inside the inline add-record card,
                // which is off by default, so report the refused rows as a message too.
                $this->setMessage('edit', 'warning', $message . ' ' . $errorMessage);

                // Redirect to edit page since some records were already created
                $this->redirect('/zones/' . $zone_id . '/edit?form_id=' . $formId);
                return;
            } elseif (!empty($ptrWarnings)) {
                // Success with PTR warnings
                $message .= ' ' . implode(' ', $ptrWarnings);
                $this->setMessage('edit', 'warning', $message);
            } else {
                $this->setMessage('edit', 'success', $message);
            }
        } else {
            $errorMessage = end($failureReasons) ?: _('Failed to add any records. They may contain invalid data.');

            // Store form data with error flag for all failed records
            // Include all records so the form can be fully restored
            $firstRecord = reset($records);
            $formId = $this->formStateService->generateFormId('add_record');
            $formData = [
                'error' => true,
                'multi_record_error' => true,
                'failure_count' => count($failureReasons),
                'errorMessage' => $errorMessage,
                'name' => is_array($firstRecord) ? ($firstRecord['name'] ?? '') : '',
                'type' => is_array($firstRecord) ? ($firstRecord['type'] ?? '') : '',
                'content' => is_array($firstRecord) ? ($firstRecord['content'] ?? '') : '',
                'prio' => is_array($firstRecord) ? ($firstRecord['prio'] ?? 0) : 0,
                'ttl' => is_array($firstRecord) ? ($firstRecord['ttl'] ?? '') : '',
                'comment' => is_array($firstRecord) ? ($firstRecord['comment'] ?? '') : '',
                'saved_records' => array_values($records),
            ];
            $this->formStateService->saveFormData($formId, $formData);

            // Redirect with form_id to show errors
            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        // Redirect back to zone edit page
        $this->redirect('/zones/' . $zone_id . '/edit');
    }
}
