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

    public function __construct(
        DnsValidatorRegistry $validatorRegistry,
        DnsCommonValidator $dnsCommonValidator,
        TTLValidator $ttlValidator,
        MessageService $messageService,
        ZoneRepositoryInterface $zoneRepository
    ) {
        $this->validatorRegistry = $validatorRegistry;
        $this->dnsCommonValidator = $dnsCommonValidator;
        $this->ttlValidator = $ttlValidator;
        $this->messageService = $messageService;
        $this->zoneRepository = $zoneRepository;
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
     * @return array|null Returns array with validated data on success, null on failure
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
    ): ?array {
        $zone = $this->zoneRepository->getDomainNameById($zid);
        if (!$zone) {
            $this->messageService->addSystemError(_('Unable to find domain with the given ID.'));
            return null;
        }

        // Perform common validation for all record types
        $cnameValidator = $this->validatorRegistry->getValidator(RecordType::CNAME);
        if ($type != RecordType::CNAME) {
            if (!$cnameValidator->isValidCnameExistence($name, $rid)) {
                return null;
            }
        }

        // Get the appropriate validator for this record type
        $validator = $this->validatorRegistry->getValidator($type);

        if ($validator === null) {
            $this->messageService->addSystemError(_('Unknown record type.'));
            return null;
        }

        // Special case for SOA records
        if ($type === RecordType::SOA) {
            $validator->setSOAParams($dns_hostmaster, $zone);
        }

        // Perform validation using the appropriate validator
        $validationResult = $validator->validate($content, $name, $prio, $ttl, $dns_ttl);
        if ($validationResult === null) {
            return null;
        }

        // Extract validated data
        $content = $validationResult['content'];
        $name = $validationResult['name'];
        $prio = $validationResult['prio'];
        $ttl = $validationResult['ttl'];

        // Perform additional validation for specific record types
        if ($type === RecordType::NS || $type === RecordType::MX) {
            if (!$this->dnsCommonValidator->isValidNonAliasTarget($content)) {
                return null;
            }
        }

        // Skip validation if it was already handled by a specific validator
        if (
            $type !== RecordType::A && $type !== RecordType::AAAA && $type !== RecordType::CNAME &&
            $type !== RecordType::CSYNC && $type !== RecordType::MX && $type !== RecordType::NS
        ) {
            $validatedPrio = $this->dnsCommonValidator->isValidPriority($prio, $type);
            if ($validatedPrio === false) {
                return null;
            }

            $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $dns_ttl);
            if ($validatedTtl === false) {
                return null;
            }
        } else {
            // We've already validated these in the specific record validator
            $validatedPrio = $prio;
            $validatedTtl = $ttl;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }
}
