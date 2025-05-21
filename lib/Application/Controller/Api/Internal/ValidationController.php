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

/**
 * Internal API controller for field validation
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use Symfony\Component\HttpFoundation\JsonResponse;

class ValidationController extends InternalApiController
{
    private DnsRecordValidationService $validationService;
    private ZoneRepositoryInterface $zoneRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $validatorRegistry = new DnsValidatorRegistry($this->getConfig(), $this->db);
        $dnsCommonValidator = new DnsCommonValidator($this->db, $this->getConfig());
        $ttlValidator = new TTLValidator();
        $messageService = new MessageService();
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $dnsViolationValidator = new DNSViolationValidator($this->db, $this->getConfig());

        $this->validationService = new DnsRecordValidationService(
            $validatorRegistry,
            $dnsCommonValidator,
            $ttlValidator,
            $messageService,
            $this->zoneRepository,
            $dnsViolationValidator
        );
    }

    public function run(): void
    {
        $action = $this->request->query->get('action', '');

        switch ($action) {
            case 'validate_record':
                $response = $this->validateRecord();
                $response->send();
                exit;
                break;
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
        $zoneId = $jsonData['zone_id'] ?? 0;
        $name = $jsonData['name'] ?? ($jsonData['records'][0]['name'] ?? '');
        $type = $jsonData['type'] ?? ($jsonData['records'][0]['type'] ?? '');
        $content = $jsonData['content'] ?? ($jsonData['records'][0]['content'] ?? '');
        $ttl = $jsonData['ttl'] ?? ($jsonData['records'][0]['ttl'] ?? $this->getConfig()->get('dns', 'ttl', 3600));
        $prio = $jsonData['prio'] ?? ($jsonData['records'][0]['prio'] ?? 0);

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
                'field' => $this->determineFieldWithError($result->getFirstError())
            ]);
        }
    }

    /**
     * Determine which field has an error based on the error message
     *
     * @param string $errorMessage The error message
     * @return string The name of the field with an error
     */
    private function determineFieldWithError(string $errorMessage): string
    {
        $lowerError = strtolower($errorMessage);

        // Check for specific field mentions in the error message
        if (strpos($lowerError, 'name') !== false && strpos($lowerError, 'invalid') !== false) {
            return 'name';
        } elseif (
            strpos($lowerError, 'content') !== false ||
                 strpos($lowerError, 'value') !== false ||
                 strpos($lowerError, 'address') !== false ||
                 strpos($lowerError, 'hostname') !== false
        ) {
            return 'content';
        } elseif (strpos($lowerError, 'ttl') !== false) {
            return 'ttl';
        } elseif (strpos($lowerError, 'prio') !== false || strpos($lowerError, 'priority') !== false) {
            return 'prio';
        } elseif (strpos($lowerError, 'already exists') !== false) {
            return 'name-content-duplicate';
        } elseif (strpos($lowerError, 'multiple cname') !== false || strpos($lowerError, 'dns violation') !== false) {
            return 'dns-violation';
        } elseif (strpos($lowerError, 'conflicts with') !== false) {
            return 'record-conflict';
        } elseif (strpos($lowerError, 'cname record cannot coexist') !== false) {
            return 'cname-conflict';
        }

        // Default to content field as that's the most common error source
        return 'content';
    }
}
