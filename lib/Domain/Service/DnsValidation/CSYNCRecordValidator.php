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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Validator for CSYNC DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CSYNCRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate CSYNC record
     *
     * @param string $content CSYNC record content in format: "SOA_SERIAL FLAGS TYPE1 [TYPE2...]"
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for CSYNC records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate CSYNC content format
        $contentResult = $this->validateCSYNCContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for CSYNC records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for CSYNC records
     * CSYNC records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing the validated priority value or error
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for CSYNC records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. CSYNC records must have priority value of 0.'));
    }

    /**
     * Check if CSYNC content is valid
     *
     * @param string $content CSYNC record content
     * @return ValidationResult ValidationResult containing validation result
     */
    public function validateCSYNCContent(string $content): ValidationResult
    {
        $fields = preg_split("/\s+/", trim($content));

        // Validate SOA Serial (first field)
        if (!isset($fields[0]) || !is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 4294967295) {
            return ValidationResult::failure(_('Invalid SOA Serial in CSYNC record.'));
        }

        // Validate Flags (second field)
        if (!isset($fields[1]) || !is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 3) {
            return ValidationResult::failure(_('Invalid Flags in CSYNC record.'));
        }

        // Validate Type Bit Map (remaining fields)
        if (count($fields) <= 2) {
            // At least one type must be specified
            return ValidationResult::failure(_('CSYNC record must specify at least one record type.'));
        }

        // Valid record types that can be synchronized
        // RFC 7477 mentions A, AAAA, and NS as the most common
        // But other record types can be synchronized as well
        $validTypes = [
            RecordType::A, RecordType::AAAA, RecordType::CNAME, RecordType::DNAME, RecordType::MX, RecordType::NS,
            RecordType::PTR, RecordType::SRV, RecordType::TXT
        ];

        for ($i = 2; $i < count($fields); $i++) {
            if (!in_array(strtoupper($fields[$i]), $validTypes)) {
                return ValidationResult::failure(_('Invalid Type in CSYNC record Type Bit Map.'));
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate CSYNC content for public use
     *
     * @param string $content CSYNC record content
     *
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    public function validateCSYNCRecordContent(string $content): ValidationResult
    {
        return $this->validateCSYNCContent($content);
    }
}
