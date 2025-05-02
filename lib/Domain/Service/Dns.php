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
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * DNS functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class Dns
{
    private ConfigurationManager $config;
    private PDOLayer $db;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;
    private DnsCommonValidator $dnsCommonValidator;
    private DnsValidatorRegistry $validatorRegistry;

    public function __construct(
        PDOLayer $db,
        ConfigurationManager $config,
        ?DnsValidatorRegistry $validatorRegistry = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator();
        $this->dnsCommonValidator = new DnsCommonValidator($db, $config);
        $this->validatorRegistry = $validatorRegistry ?? new DnsValidatorRegistry($config, $db);
    }

    /**
     * Validate DNS record input
     *
     * @param int $rid Record ID
     * @param int $zid Zone ID
     * @param string $type Record Type
     * @param mixed $content content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL
     * @param string $dns_hostmaster DNS hostmaster email
     * @param int $dns_ttl Default TTL value
     *
     * @return array|bool Returns array with validated data on success, false otherwise
     */
    public function validate_input(int $rid, int $zid, string $type, mixed $content, string $name, mixed $prio, mixed $ttl, $dns_hostmaster, $dns_ttl): array|bool
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zone = $dnsRecord->get_domain_name_by_id($zid);
        if (!$zone) {
            $this->messageService->addSystemError(_('Unable to find domain with the given ID.'));
            return false;
        }

        // Perform common validation for all record types
        $cnameValidator = $this->validatorRegistry->getValidator(RecordType::CNAME);
        if ($type != RecordType::CNAME) {
            if (!$cnameValidator->isValidCnameExistence($name, $rid)) {
                return false;
            }
        }

        // Get the appropriate validator for this record type
        $validator = $this->validatorRegistry->getValidator($type);

        if ($validator === null) {
            $this->messageService->addSystemError(_('Unknown record type.'));
            return false;
        }

        // Special case for SOA records
        if ($type === RecordType::SOA) {
            $validator->setSOAParams($dns_hostmaster, $zone);
        }

        // Perform validation using the appropriate validator
        $validationResult = $validator->validate($content, $name, $prio, $ttl, $dns_ttl);
        if ($validationResult === false) {
            return false;
        }

        // Extract validated data
        $content = $validationResult['content'];
        $name = $validationResult['name'];
        $prio = $validationResult['prio'];
        $ttl = $validationResult['ttl'];

        // Perform additional validation for specific record types
        if ($type === RecordType::NS || $type === RecordType::MX) {
            if (!$this->dnsCommonValidator->isValidNonAliasTarget($content)) {
                return false;
            }
        }

        // Skip validation if it was already handled by a specific validator
        if (
            $type !== RecordType::A && $type !== RecordType::AAAA && $type !== RecordType::CNAME &&
            $type !== RecordType::CSYNC && $type !== RecordType::MX && $type !== RecordType::NS
        ) {
            $validatedPrio = $this->dnsCommonValidator->isValidPriority($prio, $type);
            if ($validatedPrio === false) {
                return false;
            }

            $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $dns_ttl);
            if ($validatedTtl === false) {
                return false;
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
