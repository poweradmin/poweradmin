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

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsWizard\WizardRegistry;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * DNS Wizard Internal API Controller
 *
 * Handles internal API endpoints for DNS record wizards.
 * These endpoints are used by JavaScript for dynamic wizard interactions.
 *
 * @package Poweradmin\Application\Controller\Api\Internal
 */
class DnsWizardController extends InternalApiController
{
    private WizardRegistry $wizardRegistry;

    /**
     * Run the controller
     *
     * @return void
     */
    public function run(): void
    {
        // Check if wizards are enabled
        if (!$this->config->get('dns_wizards', 'enabled', false)) {
            $response = $this->returnApiError('DNS wizards are not enabled', 403);
            $response->send();
            exit;
        }

        $this->wizardRegistry = new WizardRegistry($this->config);

        $action = $this->request->query->get('action', '');

        switch ($action) {
            case 'list':
                $response = $this->listWizards();
                break;
            case 'schema':
                $response = $this->getWizardSchema();
                break;
            case 'validate':
                $response = $this->validateWizardData();
                break;
            case 'preview':
                $response = $this->previewWizardRecord();
                break;
            case 'parse':
                $response = $this->parseExistingRecord();
                break;
            case 'generate':
                $response = $this->generateRecord();
                break;
            case 'create':
                $response = $this->createRecord();
                break;
            default:
                $response = $this->returnApiError('Invalid action', 400);
        }

        $response->send();
        exit;
    }

    /**
     * List all available wizards
     *
     * GET /api/internal/dns-wizard?action=list
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function listWizards(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $metadata = $this->wizardRegistry->getWizardMetadata();
            return $this->returnApiResponse([
                'wizards' => $metadata,
                'enabled' => $this->wizardRegistry->isEnabled(),
            ]);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Get wizard form schema
     *
     * GET /api/internal/dns-wizard?action=schema&type=DMARC
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function getWizardSchema(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $type = $this->request->query->get('type', '');

        if (empty($type)) {
            return $this->returnApiError('Missing wizard type', 400);
        }

        try {
            $wizard = $this->wizardRegistry->getWizard($type);
            return $this->returnApiResponse([
                'type' => $wizard->getWizardType(),
                'name' => $wizard->getDisplayName(),
                'recordType' => $wizard->getRecordType(),
                'schema' => $wizard->getFormSchema(),
            ]);
        } catch (\RuntimeException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Validate wizard form data
     *
     * POST /api/internal/dns-wizard?action=validate
     * Body: { "type": "DMARC", "formData": {...} }
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function validateWizardData(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $input = json_decode($this->request->getContent(), true) ?? [];
        $type = $input['type'] ?? '';
        $formData = $input['formData'] ?? [];

        if (empty($type)) {
            return $this->returnApiError('Missing wizard type', 400);
        }

        try {
            $wizard = $this->wizardRegistry->getWizard($type);
            $validation = $wizard->validate($formData);

            return $this->returnApiResponse($validation);
        } catch (\RuntimeException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Preview wizard-generated DNS record
     *
     * POST /api/internal/dns-wizard?action=preview
     * Body: { "type": "DMARC", "formData": {...} }
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function previewWizardRecord(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $input = json_decode($this->request->getContent(), true) ?? [];
        $type = $input['type'] ?? '';
        $formData = $input['formData'] ?? [];

        if (empty($type)) {
            return $this->returnApiError('Missing wizard type', 400);
        }

        try {
            $wizard = $this->wizardRegistry->getWizard($type);

            // Validate first
            $validation = $wizard->validate($formData);
            if (!$validation['valid']) {
                return $this->returnApiResponse([
                    'preview' => '',
                    'validation' => $validation
                ]);
            }

            $preview = $wizard->getPreview($formData);

            return $this->returnApiResponse([
                'preview' => $preview,
                'validation' => $validation
            ]);
        } catch (\RuntimeException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Parse existing DNS record for editing
     *
     * POST /api/internal/dns-wizard?action=parse
     * Body: { "type": "DMARC", "content": "v=DMARC1; p=none;", "recordData": {...} }
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function parseExistingRecord(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $input = json_decode($this->request->getContent(), true) ?? [];
        $type = $input['type'] ?? '';
        $content = $input['content'] ?? '';
        $recordData = $input['recordData'] ?? [];

        if (empty($type) || empty($content)) {
            return $this->returnApiError('Missing wizard type or record content', 400);
        }

        try {
            $wizard = $this->wizardRegistry->getWizard($type);
            $formData = $wizard->parseExistingRecord($content, $recordData);

            return $this->returnApiResponse([
                'formData' => $formData
            ]);
        } catch (\RuntimeException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Generate DNS record data from wizard form data
     *
     * POST /api/internal/dns-wizard?action=generate
     * Body: { "type": "DMARC", "formData": {...} }
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function generateRecord(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $input = json_decode($this->request->getContent(), true) ?? [];
        $type = $input['type'] ?? '';
        $formData = $input['formData'] ?? [];

        if (empty($type)) {
            return $this->returnApiError('Missing wizard type', 400);
        }

        try {
            $wizard = $this->wizardRegistry->getWizard($type);
            $recordData = $wizard->generateRecord($formData);

            return $this->returnApiResponse($recordData);
        } catch (\RuntimeException $e) {
            return $this->returnApiError($e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }

    /**
     * Create DNS record from wizard data
     *
     * POST /api/internal/dns-wizard?action=create
     * Body: { "zone_id": 1, "name": "@", "type": "TXT", "content": "...", "ttl": 3600, "priority": 0 }
     * Headers: X-CSRF-Token: <token>
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    private function createRecord(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        // Validate CSRF token from X-CSRF-Token header
        $csrfToken = $this->request->headers->get('X-CSRF-Token', '');
        if (empty($csrfToken)) {
            return $this->returnApiError('Missing CSRF token', 403);
        }

        // Validate the CSRF token using the service from BaseController
        if (!$this->config->get('security', 'global_token_validation', true)) {
            // CSRF validation is disabled in config - skip check
        } else {
            // Create a temporary CsrfTokenService to validate the token
            $csrfService = new \Poweradmin\Application\Service\CsrfTokenService();
            if (!$csrfService->validateToken($csrfToken)) {
                return $this->returnApiError('Invalid CSRF token', 403);
            }
        }

        $data = json_decode($this->request->getContent(), true) ?? [];

        // Validate required fields
        if (empty($data['zone_id'])) {
            return $this->returnApiError('Missing zone_id', 400);
        }

        if (!isset($data['name']) && !array_key_exists('name', $data)) {
            return $this->returnApiError('Missing record name', 400);
        }

        if (empty($data['type'])) {
            return $this->returnApiError('Missing record type', 400);
        }

        if (!isset($data['content']) && !array_key_exists('content', $data)) {
            return $this->returnApiError('Missing record content', 400);
        }

        $zone_id = (int)$data['zone_id'];
        $name = $data['name'];
        $type = $data['type'];
        $content = $data['content'];
        $ttl = isset($data['ttl']) && $data['ttl'] !== '' ? (int)$data['ttl'] : $this->config->get('dns', 'ttl', 3600);
        $prio = isset($data['priority']) && $data['priority'] !== '' ? (int)$data['priority'] : 0;
        $comment = $data['comment'] ?? '';

        try {
            // Check zone existence
            $zoneRepository = new DbZoneRepository($this->db, $this->config);
            $zone_name = $zoneRepository->getDomainNameById($zone_id);

            if ($zone_name === null) {
                return $this->returnApiError('Zone not found', 404);
            }

            // Check user has permission to edit this zone
            $dnsRecord = new DnsRecord($this->db, $this->config);
            $zone_type = $dnsRecord->getDomainType($zone_id);
            $perm_edit = Permission::getEditPermission($this->db);
            $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

            // Same permission check as AddRecordController
            if (
                $zone_type == "SLAVE"
                || $perm_edit == "none"
                || (($perm_edit == "own" || $perm_edit == "own_as_client") && !$user_is_zone_owner)
            ) {
                return $this->returnApiError('You do not have permission to add records to this zone', 403);
            }

            // Normalize wizard-provided names (e.g., '@' for zone apex) to actual zone names
            // This ensures records match the zone apex even when display_hostname_only is disabled
            $name = DnsHelper::restoreZoneSuffix($name, $zone_name);

            // Create the record using RecordManagerService
            $logger = new LegacyLogger($this->db);
            $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->config);
            $recordCommentService = new RecordCommentService($recordCommentRepository);
            $commentSyncService = new RecordCommentSyncService($recordCommentService);
            $recordManager = new RecordManagerService(
                $this->db,
                $dnsRecord,
                $recordCommentService,
                $commentSyncService,
                $logger,
                $this->config
            );

            $userlogin = $_SESSION['userlogin'] ?? 'unknown';
            $clientIp = $this->request->getClientIp() ?? '0.0.0.0';

            $success = $recordManager->createRecord(
                $zone_id,
                $name,
                $type,
                $content,
                $ttl,
                $prio,
                $comment,
                $userlogin,
                $clientIp
            );

            if (!$success) {
                return $this->returnApiError(_('This record was not valid and could not be added. It may already exist or contain invalid data.'), 400);
            }

            // Set success message for display after page reload
            $this->setMessage('edit', 'success', _('The record was successfully added.'));

            return $this->returnApiResponse([
                'success' => true,
                'message' => _('Record created successfully'),
            ]);
        } catch (\Exception $e) {
            return $this->returnApiError($e->getMessage(), 500);
        }
    }
}
