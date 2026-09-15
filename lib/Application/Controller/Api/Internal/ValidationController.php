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

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\UserContextService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Internal endpoint /api/internal/validation: validates a record against a zone before it is submitted.
 */
class ValidationController extends InternalApiController
{
    private DnsRecordValidationService $validationService;
    private ApiPermissionService $apiPermissionService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $validatorRegistry = new DnsValidatorRegistry($this->getConfig(), $this->db);
        $dnsCommonValidator = new DnsCommonValidator($this->db, $this->getConfig());
        $dnsViolationValidator = new DNSViolationValidator($this->getRepositoryFactory()->createRecordRepository());

        $this->validationService = new DnsRecordValidationService(
            $validatorRegistry,
            $dnsCommonValidator,
            $this->createDomainRepository(),
            $dnsViolationValidator
        );
        $this->apiPermissionService = $this->createApiPermissionService();
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $action = $this->request->query->get('action', '');

        switch ($action) {
            case 'validate_record':
                $response = $this->validateRecord();
                $response->send();
                exit;
            default:
                $response = $this->returnErrorResponse('Unknown action', 400);
                $response->send();
                exit;
        }
    }

    /**
     * Validates a DNS record
     *
     * Expected JSON POST data:
     * {
     *   "zone_id": 123,
     *   "name": "www",
     *   "type": "A",
     *   "content": "192.168.1.1",
     *   "ttl": 3600,
     *   "prio": 0
     * }
     *
     * @return JsonResponse The JSON response
     */
    private function validateRecord(): JsonResponse
    {
        // Get input data - either from JSON or form post
        $jsonData = $this->getJsonInput();
        if ($jsonData === null) {
            return $this->returnErrorResponse('Invalid request data', 400);
        }

        // Extract record data with fallbacks for different input formats
        $zoneId = (int)($jsonData['zone_id'] ?? 0);
        $name = $jsonData['name'] ?? ($jsonData['records'][0]['name'] ?? '');
        $type = $jsonData['type'] ?? ($jsonData['records'][0]['type'] ?? '');
        $content = $jsonData['content'] ?? ($jsonData['records'][0]['content'] ?? '');
        $ttl = $jsonData['ttl'] ?? ($jsonData['records'][0]['ttl'] ?? $this->getConfig()->get('dns', 'ttl', 3600));
        $prio = $jsonData['prio'] ?? ($jsonData['records'][0]['prio'] ?? 0);

        // Validation runs SQL conflict probes against the target zone (e.g. "already
        // exists", "CNAME conflict"). Refuse to validate against zones the caller
        // cannot view, otherwise an attacker enumerates record presence in others'
        // zones via validation error messages.
        if ($zoneId > 0) {
            $userId = $this->userContextService->getLoggedInUserId() ?? 0;
            if (!$this->apiPermissionService->canViewZone($userId, $zoneId)) {
                return $this->returnErrorResponse('You do not have permission to validate against this zone', 403);
            }
        }

        // Validate the record
        $result = $this->validationService->validateRecord(
            0, // No record ID for new records
            $zoneId,
            $type,
            $content,
            $name,
            $prio,
            $ttl,
            $this->getConfig()->get('dns', 'hostmaster', 'hostmaster.example.com'),
            $this->getConfig()->get('dns', 'ttl', 3600)
        );

        if ($result->isValid()) {
            return $this->returnJsonResponse([
                'valid' => true,
                'data' => $result->getData()
            ]);
        } else {
            return $this->returnJsonResponse([
                'valid' => false,
                'errors' => $result->getErrors(),
                'field' => RecordWriteResult::fieldForMessage($result->getFirstError())
            ]);
        }
    }
}
