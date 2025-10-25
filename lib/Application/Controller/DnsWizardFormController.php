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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsWizard\WizardRegistry;
use Poweradmin\Domain\Service\FormStateService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * DNS Wizard Form Controller
 *
 * Handles the wizard form display and submission for creating DNS records.
 */
class DnsWizardFormController extends BaseController
{
    private DnsRecord $dnsRecord;
    private WizardRegistry $wizardRegistry;
    private DbZoneRepository $zoneRepository;
    private RecordManagerService $recordManager;
    private FormStateService $formStateService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $this->wizardRegistry = new WizardRegistry($this->getConfig());
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->formStateService = new FormStateService();

        $logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $recordCommentService = new RecordCommentService($recordCommentRepository);
        $commentSyncService = new RecordCommentSyncService($recordCommentService);

        $this->recordManager = new RecordManagerService(
            $this->db,
            $this->dnsRecord,
            $recordCommentService,
            $commentSyncService,
            $logger,
            $this->getConfig()
        );
    }

    public function run(): void
    {
        // Check if wizards are enabled
        if (!$this->getConfig()->get('dns_wizards', 'enabled', false)) {
            $this->showError(_('DNS wizards are not enabled.'));
        }

        $zone_id = $this->getSafeRequestValue('id');
        $wizard_type = strtoupper($this->getSafeRequestValue('type'));

        if (!is_numeric($zone_id)) {
            $this->showError(_('Invalid zone ID.'));
        }

        $zone_id = (int)$zone_id;

        // Check if zone exists
        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
        }

        // Check permissions
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $zone_type = $this->dnsRecord->getDomainType($zone_id);

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && !$user_is_zone_owner)) {
            $this->showError(_('You do not have permission to add records to this zone.'));
        }

        // Check if zone is reverse zone
        $is_reverse_zone = preg_match('/\.in-addr\.arpa$/i', $zone_name) || preg_match('/\.ip6\.arpa$/i', $zone_name);

        // Get wizard
        try {
            $wizard = $this->wizardRegistry->getWizard($wizard_type);
        } catch (\RuntimeException $e) {
            $this->showError(_('Invalid wizard type.'));
            return;
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_wizard'])) {
            $this->handleFormSubmission($zone_id, $zone_name, $wizard, $wizard_type);
            return;
        }

        // Get form schema
        $schema = $wizard->getFormSchema();

        // Initialize form data with defaults
        $formData = [];
        foreach ($schema['sections'] as $section) {
            if (isset($section['fields'])) {
                foreach ($section['fields'] as $field) {
                    if (isset($field['default']) && $field['default'] !== null && $field['default'] !== '') {
                        $formData[$field['name']] = $field['default'];
                    }
                }
            }
        }

        // Check if we have saved form data from a validation error or warnings
        $formId = $_GET['form_id'] ?? null;
        $showWarnings = $_GET['show_warnings'] ?? null;
        $warnings = [];

        if ($formId) {
            $savedFormData = $this->formStateService->getFormData($formId);
            if ($savedFormData) {
                // Extract warnings if present
                if (isset($savedFormData['_warnings'])) {
                    $warnings = $savedFormData['_warnings'];
                    unset($savedFormData['_warnings']);
                }

                // Merge saved data over defaults (saved data takes precedence)
                $formData = array_merge($formData, $savedFormData);
                // Clear the saved data now that we've used it
                $this->formStateService->clearFormData($formId);
            }
        }

        // Render the wizard form page
        $this->render('dns_wizard_form.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'is_reverse_zone' => $is_reverse_zone,
            'wizard' => [
                'type' => $wizard_type,
                'name' => $wizard->getDisplayName(),
                'recordType' => $wizard->getRecordType(),
                'supportsTwoModes' => $wizard->supportsTwoModes(),
                'schema' => $schema,
            ],
            'formData' => $formData,
            'warnings' => $warnings,
            'showWarnings' => $showWarnings === '1',
        ]);
    }

    private function handleFormSubmission(int $zone_id, string $zone_name, $wizard, string $wizard_type): void
    {
        // Validate CSRF token
        $this->validateCsrfToken();

        // Get form data from POST
        $formData = [];
        $warningsAcknowledged = false;
        foreach ($_POST as $key => $value) {
            if ($key === 'warnings_acknowledged' && $value === '1') {
                $warningsAcknowledged = true;
            } elseif ($key !== '_token' && $key !== 'submit_wizard' && $key !== 'warnings_acknowledged') {
                $formData[$key] = $value;
            }
        }

        // Validate form data
        $validation = $wizard->validate($formData);

        if (!$validation['valid']) {
            // Show validation errors
            $errors = $validation['errors'] ?? [];
            $this->setMessage('dns_wizard_form', 'error', _('Validation failed:') . ' ' . implode(', ', $errors));

            // Save form data so it can be repopulated
            $formId = $this->formStateService->generateFormId('dns_wizard_form');
            $this->formStateService->saveFormData($formId, $formData);

            $this->redirect('/zones/' . $zone_id . '/wizard/' . strtolower($wizard_type), ['form_id' => $formId]);
            return;
        }

        // Check for warnings even if validation passed (unless user already acknowledged them)
        $warnings = $validation['warnings'] ?? [];
        if (!empty($warnings) && !$warningsAcknowledged) {
            $formId = $this->formStateService->generateFormId('dns_wizard_form');
            $this->formStateService->saveFormData($formId, array_merge($formData, ['_warnings' => $warnings]));

            $this->redirect('/zones/' . $zone_id . '/wizard/' . strtolower($wizard_type), ['form_id' => $formId, 'show_warnings' => '1']);
            return;
        }

        // Generate record data
        try {
            $recordData = $wizard->generateRecord($formData);
        } catch (\Exception $e) {
            $this->setMessage('dns_wizard_form', 'error', _('Failed to generate record:') . ' ' . $e->getMessage());

            // Save form data so it can be repopulated
            $formId = $this->formStateService->generateFormId('dns_wizard_form');
            $this->formStateService->saveFormData($formId, $formData);

            $this->redirect('/zones/' . $zone_id . '/wizard/' . strtolower($wizard_type), ['form_id' => $formId]);
            return;
        }

        // Normalize wizard-provided names
        $name = DnsHelper::restoreZoneSuffix($recordData['name'] ?? '', $zone_name);
        $type = $recordData['type'] ?? '';
        $content = $recordData['content'] ?? '';
        $ttl = isset($recordData['ttl']) && $recordData['ttl'] !== '' ? (int)$recordData['ttl'] : $this->getConfig()->get('dns', 'ttl', 3600);
        $prio = isset($recordData['prio']) && $recordData['prio'] !== '' ? (int)$recordData['prio'] : 0;

        // Create the record
        $userlogin = $_SESSION['userlogin'] ?? 'unknown';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $success = $this->recordManager->createRecord(
            $zone_id,
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            '',
            $userlogin,
            $clientIp
        );

        if (!$success) {
            $this->setMessage('dns_wizard_form', 'error', _('This record was not valid and could not be added. It may already exist or contain invalid data.'));

            // Save form data so it can be repopulated
            $formId = $this->formStateService->generateFormId('dns_wizard_form');
            $this->formStateService->saveFormData($formId, $formData);

            $this->redirect('/zones/' . $zone_id . '/wizard/' . strtolower($wizard_type), ['form_id' => $formId]);
            return;
        }

        // Success - redirect to zone edit page
        $this->setMessage('edit', 'success', _('The record was successfully added.'));
        $this->redirect('/zones/' . $zone_id . '/edit');
    }
}
