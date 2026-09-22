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

namespace Poweradmin\Application\Controller\Record;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Presenter\RecordFormFieldPresenter;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Application\Service\RecordAddAccess;
use Poweradmin\Application\Service\RecordAddMessages;
use Poweradmin\Application\Service\RecordAddResult;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Utility\DnsHelper;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-record form for a zone: checks edit rights, validates the record and saves one or several rows.
 */
class AddRecordController extends BaseController
{
    private ?RecordAddService $recordAdd = null;
    private ?RecordTypeService $recordTypeService = null;
    private ?FormStateService $formStateService = null;
    private ?ReverseTtlResolver $reverseTtlResolver = null;

    private function formStateService(): FormStateService
    {
        return $this->formStateService ??= new FormStateService();
    }

    private function recordAdd(): RecordAddService
    {
        return $this->recordAdd ??= $this->services()->recordAddService();
    }

    private function recordTypeService(): RecordTypeService
    {
        return $this->recordTypeService ??= new RecordTypeService($this->getConfig());
    }

    private function reverseTtlResolver(): ReverseTtlResolver
    {
        return $this->reverseTtlResolver ??= $this->services()->reverseTtlResolver();
    }

    public function run(): void
    {
        $zone_id = (int)$this->getSafeRequestValue('zone_id');
        $access = $this->recordAdd()->open($zone_id, (int)$this->getCurrentUserId());
        $this->checkCondition($access->code === RecordAddAccess::ZONE_NOT_FOUND, _('There is no zone with this ID.'));
        // Multi-record mode stays direct-only: users whose changes need review use the zone editor
        $this->checkCondition($access->code === RecordAddAccess::REQUIRES_APPROVAL, ChangeRequestMessages::requiresApproval());
        $this->checkCondition(!$access->isGranted(), _("You do not have the permission to add a record to this zone."));

        if ($this->isPost()) {
            if ($this->httpRequest->getPostParam('multi_record_mode') !== null && is_array($this->httpRequest->getPostParam('records'))) {
                $this->addMultipleRecords($zone_id, $access->zoneName);
            } else {
                $this->addRecord($zone_id, $access->zoneName);
            }
        }
        $this->showForm($zone_id, $access->zoneName);
    }

    private function addRecord(int $zone_id, string $zone_name): void
    {
        // Required wraps the rule so a blank or missing field fails instead of being skipped
        $this->setValidationConstraints([
            'content' => new Assert\Required([new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'content'))]),
            'type' => new Assert\Required([new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'type'))]),
        ]);

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
            return;
        }

        $name = (string)$this->httpRequest->getPostParam('name', '');
        $content = (string)$this->httpRequest->getPostParam('content');
        $type = (string)$this->httpRequest->getPostParam('type');
        $prio = $this->httpRequest->getPostParam('prio');
        $prio = $prio !== null && $prio !== '' ? (int)$prio : 0;
        $comment = (string)$this->httpRequest->getPostParam('comment', '');
        $ttl = $this->httpRequest->getPostParam('ttl');

        $added = $this->recordAdd()->add(
            $zone_id,
            $zone_name,
            $name,
            $type,
            $content,
            $ttl !== null && $ttl !== '' ? (int)$ttl : null,
            $prio,
            $comment,
            (int)$this->getCurrentUserId(),
            (string)$this->getUserContextService()->getLoggedInUsername(),
            RecordAddResult::companionFrom($this->httpRequest->getPostParams())
        );
        if (!$added->isOk()) {
            // Keep the submitted values and point at the field the reason names
            $formId = $this->formStateService()->generateFormId('add_record');
            $this->formStateService()->saveFormData($formId, [
                'name' => $name,
                'content' => $content,
                'type' => $type,
                'prio' => $prio,
                'ttl' => $ttl,
                'comment' => $comment,
                'error' => true,
                'errorMessage' => $added->record->message,
                'fieldError' => RecordFormFieldPresenter::fieldId($added->record->field, (string)$added->record->message),
            ]);

            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        // Clear form data if it exists in the session
        $formToken = $this->httpRequest->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService()->clearFormData($formToken);
        }

        [$messageType, $message] = RecordAddMessages::forAdded($added);
        $this->setMessage('edit', $messageType, $message);

        // Redirect back to zone edit page
        $this->redirect('/zones/' . $zone_id . '/edit');
    }

    private function showForm(int $zone_id, string $zone_name): void
    {
        $isReverseZone = DnsHelper::isReverseZoneName($zone_name);

        // Pre-fill with the plain dns.ttl; JS updateTtlForType() swaps in dns.ttl_reverse
        // when the user selects PTR on a reverse zone, keeping the form consistent with
        // what the backend will actually persist.
        $ttl = $this->reverseTtlResolver()->getForwardTtl();
        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        // Retrieve form state data from session (e.g. after validation error redirect)
        $formData = null;
        $formId = $this->httpRequest->getQueryParam('form_id');
        if ($formId) {
            $formData = $this->formStateService()->getFormData($formId);
        }

        // Build saved_records array for multi-row restore
        $savedRecords = [];
        if ($formData && isset($formData['saved_records']) && is_array($formData['saved_records'])) {
            $savedRecords = $formData['saved_records'];
        }

        $offeredTypes = $isReverseZone
            ? $this->recordTypeService()->getReverseZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities(), false)
            : $this->recordTypeService()->getDomainZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities(), false);

        // Offer only what this caller may actually submit, so a restricted type is not
        // presented and then refused on save.
        $permEdit = $this->services()->permissionService()->getEditPermissionLevel((int)$this->getCurrentUserId());
        $offeredTypes = array_values(array_filter(
            $offeredTypes,
            fn(string $type): bool => !Permission::isRecordTypeRestrictedForClient($type, $permEdit)
        ));

        $this->render('add_record.html', [
            'types' => $offeredTypes,
            'deprecated_types' => RecordType::DEPRECATED_TYPES,
            'name' => $formData['name'] ?? $this->httpRequest->getPostParam('name', ''),
            'type' => $formData['type'] ?? $this->httpRequest->getPostParam('type', ''),
            'content' => $formData['content'] ?? $this->httpRequest->getPostParam('content', ''),
            'ttl' => $formData['ttl'] ?? $this->httpRequest->getPostParam('ttl', $ttl),
            'default_ttl' => $this->reverseTtlResolver()->getForwardTtl(),
            'ptr_default_ttl' => $this->reverseTtlResolver()->getConfiguredReverseTtl(),
            'type_default_ttls' => $this->reverseTtlResolver()->getTypeDefaults(),
            'ttl_defaults_by_type' => $this->reverseTtlResolver()->resolveTtlsForTypes($offeredTypes, $isReverseZone),
            'prio' => $formData['prio'] ?? $this->httpRequest->getPostParam('prio', 0),
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'is_reverse_zone' => $isReverseZone,
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', true),
            'display_hostname_only' => $this->services()->userPreferenceService()->getDisplayHostnameOnly(
                $this->getUserContextService()->getLoggedInUserId()
            ),
            'form_data' => $formData,
            'saved_records' => $savedRecords,
        ]);
    }

    private function addMultipleRecords(int $zone_id, string $zone_name): void
    {
        $records = $this->httpRequest->getPostParam('records', []);
        $successCount = 0;
        $failureReasons = [];
        $matchingRecordCount = 0;
        $ptrWarnings = [];
        $formId = $this->formStateService()->generateFormId('add_record');

        if (empty($records)) {
            $formData = [
                'error' => true,
                'errorMessage' => _('No records were provided.'),
            ];
            $this->formStateService()->saveFormData($formId, $formData);
            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        $username = (string)$this->getUserContextService()->getLoggedInUsername();

        foreach ($records as $record) {
            // Skip non-array or incomplete records
            if (!is_array($record) || empty($record['content']) || empty($record['type'])) {
                continue;
            }

            $added = $this->recordAdd()->add(
                $zone_id,
                $zone_name,
                (string)($record['name'] ?? ''),
                (string)$record['type'],
                (string)$record['content'],
                isset($record['ttl']) && $record['ttl'] !== '' ? (int)$record['ttl'] : null,
                isset($record['prio']) && $record['prio'] !== '' ? (int)$record['prio'] : 0,
                (string)($record['comment'] ?? ''),
                (int)$this->getCurrentUserId(),
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
        $formToken = $this->httpRequest->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService()->clearFormData($formToken);
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
                $formId = $this->formStateService()->generateFormId('add_record');
                $formData = [
                    'error' => true,
                    'multi_record_error' => true,
                    'failure_count' => count($failureReasons),
                    'errorMessage' => $errorMessage
                ];
                $this->formStateService()->saveFormData($formId, $formData);

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
            $formId = $this->formStateService()->generateFormId('add_record');
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
            $this->formStateService()->saveFormData($formId, $formData);

            // Redirect with form_id to show errors
            $this->redirect('/zones/' . $zone_id . '/records/add?form_id=' . $formId);
            return;
        }

        // Redirect back to zone edit page
        $this->redirect('/zones/' . $zone_id . '/edit');
    }
}
