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

namespace Poweradmin\Module\DnsWizard\Controller;

use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Module\DnsWizard\Service\WizardRegistry;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneAccessPolicy;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;

/**
 * Renders the form for one wizard at /zones/{id}/wizard/{type} and creates the records it builds.
 *
 * Handles the wizard form display and submission for creating DNS records.
 */
class DnsWizardFormController extends BaseController
{
    private DomainRepositoryInterface $domainRepository;
    private WizardRegistry $wizardRegistry;
    private RecordManagerService $recordManager;
    private FormStateService $formStateService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->wizardRegistry = new WizardRegistry($this->getConfig());
        $this->formStateService = new FormStateService();

        $this->domainRepository = $this->createDomainRepository();
        $this->recordManager = $this->createRecordManagerService();
    }

    public function run(): void
    {
        // Check if wizards are enabled
        if (!$this->getModuleConfig('dns_wizards', 'enabled', false)) {
            $this->showError(_('DNS wizards are not enabled.'));
        }

        $zone_id = $this->getSafeRequestValue('id');
        $wizard_type = strtoupper($this->getSafeRequestValue('type'));

        if (!is_numeric($zone_id)) {
            $this->showError(_('Invalid zone ID.'));
        }

        $zone_id = (int)$zone_id;

        // Check if zone exists
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
        }

        // Check permissions
        $perm_edit = $this->createPermissionService()->getEditPermissionLevel((int)$this->getCurrentUserId());
        $user_is_zone_owner = $this->isZoneOwner($zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if (ZoneType::isReadOnly($zone_type) || !ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner)) {
            $this->showError(_('You do not have permission to add records to this zone.'));
        }
        // Wizards write directly, so a reviewed zone sends the user to the zone editor
        if ($this->changeApprovalModeForZone($zone_id) === ChangeApprovalPolicy::MODE_REQUEST) {
            $this->showError(ChangeRequestMessages::requiresApproval());
        }

        // Check if zone is reverse zone
        $is_reverse_zone = DnsHelper::isReverseZoneName($zone_name);

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
        $schema = $this->withSchemaDefaults($schema);

        // Initialize form data with defaults
        $formData = [];
        foreach ($schema['sections'] as $section) {
            if (isset($section['fields'])) {
                foreach ($section['fields'] as $field) {
                    if (isset($field['default']) && $field['default'] !== '') {
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
        $this->setCurrentPage('module_dns_wizard_form');
        $this->render('dns_wizard_form.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'is_reverse_zone' => $is_reverse_zone,
            'wizard' => [
                'type' => $wizard_type,
                'name' => $wizard->getDisplayName(),
                'recordType' => $wizard->getRecordType(),
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
        $reverseTtlResolver = $this->createReverseTtlResolver();
        $isReverseZone = DnsHelper::isReverseZoneName($zone_name);
        $ttl = isset($recordData['ttl']) && $recordData['ttl'] !== ''
            ? (int)$recordData['ttl']
            : $reverseTtlResolver->resolveTtlForType($type, $isReverseZone);
        $prio = isset($recordData['prio']) && $recordData['prio'] !== '' ? (int)$recordData['prio'] : 0;

        // Create the record
        $userContextService = new UserContextService();
        $userlogin = $userContextService->getLoggedInUsername() ?? 'unknown';

        $result = $this->recordManager->createRecord(
            $zone_id,
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            '',
            $userlogin
        );

        if (!$result->success) {
            $this->setMessage('dns_wizard_form', 'error', (string)$result->message);

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

    /**
     * Fill in the optional section, field and option keys the form template reads.
     *
     * Wizards only declare the keys they use, and the template runs under Twig
     * strict_variables when display_errors is on, where a missing key is fatal.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withSchemaDefaults(array $schema): array
    {
        foreach ($schema['sections'] ?? [] as $i => $section) {
            $section += ['type' => '', 'title' => '', 'description' => '', 'content' => '', 'fields' => []];
            foreach ($section['fields'] as $j => $field) {
                $field += ['label' => '', 'required' => false, 'help' => '', 'pattern' => '', 'placeholder' => '', 'default' => '', 'options' => []];
                foreach ($field['options'] as $k => $option) {
                    $field['options'][$k] = $option + ['description' => ''];
                }
                $section['fields'][$j] = $field;
            }
            $schema['sections'][$i] = $section;
        }

        return $schema;
    }
}
