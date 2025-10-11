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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;

/**
 * DNS Record Validation Service
 *
 * Responsible only for validating DNS records according to their type
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class DnsRecordValidationService implements DnsRecordValidationServiceInterface
{
    private TTLValidator $ttlValidator;
    private DnsCommonValidator $dnsCommonValidator;
    private DnsValidatorRegistry $validatorRegistry;
    private MessageService $messageService;
    private ZoneRepositoryInterface $zoneRepository;
    private DNSViolationValidator $dnsViolationValidator;

    public function __construct(
        DnsValidatorRegistry $validatorRegistry,
        DnsCommonValidator $dnsCommonValidator,
        TTLValidator $ttlValidator,
        MessageService $messageService,
        ZoneRepositoryInterface $zoneRepository,
        DNSViolationValidator $dnsViolationValidator
    ) {
        $this->validatorRegistry = $validatorRegistry;
        $this->dnsCommonValidator = $dnsCommonValidator;
        $this->ttlValidator = $ttlValidator;
        $this->messageService = $messageService;
        $this->zoneRepository = $zoneRepository;
        $this->dnsViolationValidator = $dnsViolationValidator;
    }

    /**
     * Validate DNS record input
     *
     * @param int $rid Record ID
     * @param int $zid Zone ID
     * @param string $type Record Type
     * @param string $content content part of record
     * @param string $name Name part of record
     * @param int|null $prio Priority
     * @param int|null $ttl TTL
     * @param string $dns_hostmaster DNS hostmaster email
     * @param int $dns_ttl Default TTL value
     *
     * @return ValidationResult Returns ValidationResult with validated data or error messages
     */
    public function validateRecord(
        int $rid,
        int $zid,
        string $type,
        string $content,
        string $name,
        ?int $prio,
        ?int $ttl,
        string $dns_hostmaster,
        int $dns_ttl
    ): ValidationResult {
        $zone = $this->zoneRepository->getDomainNameById($zid);
        if (!$zone) {
            return ValidationResult::failure(_('Unable to find domain with the given ID.'));
        }

        // Get the appropriate validator for this record type
        $validator = $this->validatorRegistry->getValidator($type);
        if ($validator === null) {
            return ValidationResult::failure(_('Unknown record type.'));
        }

        // Perform common validation for all record types
        $cnameValidator = $this->validatorRegistry->getValidator(RecordType::CNAME);
        if ($type != RecordType::CNAME && $cnameValidator instanceof CNAMERecordValidator) {
            $cnameResult = $cnameValidator->validateCnameExistence($name, $rid);
            if (!$cnameResult->isValid()) {
                return $cnameResult;
            }
        }

        // Special case for SOA records
        if ($type === RecordType::SOA && $validator instanceof SOARecordValidator) {
            $validator->setSOAParams($dns_hostmaster, $zone);
        }

        // Check for DNS violations (like multiple CNAMEs with the same name)
        $violationResult = $this->dnsViolationValidator->validate($rid, $zid, $type, $name, $content);
        if (!$violationResult->isValid()) {
            return $violationResult;
        }

        // Perform validation using the appropriate validator
        if ($type === RecordType::CNAME) {
            // CNAME validator expects: $rid, $zone
            $validationResult = $validator->validate($content, $name, $prio, $ttl, $dns_ttl, $rid, $zone);
        } elseif ($type === RecordType::SOA) {
            // SOA validator expects: $dns_hostmaster, $zone
            $validationResult = $validator->validate($content, $name, $prio, $ttl, $dns_ttl, $dns_hostmaster, $zone);
        } else {
            // Other validators don't need additional parameters
            $validationResult = $validator->validate($content, $name, $prio, $ttl, $dns_ttl);
        }

        // If validation failed, return the errors
        if (!$validationResult->isValid()) {
            return $validationResult;
        }

        // Extract validated data
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = $validatedData['name'];
        $prio = $validatedData['prio'];
        $ttl = $validatedData['ttl'];

        // Perform additional validation for specific record types
        if ($type === RecordType::NS || $type === RecordType::MX) {
            // For NS/MX, check that target is not a CNAME
            $aliasResult = $this->dnsCommonValidator->validateNonAliasTarget($content);
            if (!$aliasResult->isValid()) {
                return $aliasResult;
            }
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $prio,
            'ttl' => $ttl
        ]);
    }
}
