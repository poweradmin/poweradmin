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

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * EUI48 record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class EUI48RecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates EUI48 record content
     *
     * @param string $content The content of the EUI48 record (MAC address in xx-xx-xx-xx-xx-xx format)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for EUI48 records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::errors($hostnameResult->getErrors());
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content - should be a valid EUI-48 (MAC-48) address in xx-xx-xx-xx-xx-xx format
        $contentResult = $this->isValidEUI48($content);
        if (!$contentResult->isValid()) {
            $errors[] = $contentResult->getFirstError();
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $ttlResult->getErrors()));
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for EUI48 records)
        $validatedPrio = $this->validatePriority($prio);
        if (!$validatedPrio->isValid()) {
            $errors[] = _('Invalid value for prio field.');
        }

        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio->getData(),
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Check if a string is a valid EUI-48 (MAC-48) address
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with validation status
     */
    private function isValidEUI48(string $data): ValidationResult
    {
        // MAC address format: xx-xx-xx-xx-xx-xx where x is a hexadecimal digit
        if (preg_match('/^([0-9a-fA-F]{2}-){5}[0-9a-fA-F]{2}$/', $data)) {
            return ValidationResult::success(true);
        }
        return ValidationResult::failure(_('EUI48 record must be a valid MAC address in xx-xx-xx-xx-xx-xx format (where x is a hexadecimal digit).'));
    }

    /**
     * Validate priority for EUI48 records
     * EUI48 records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult<int> ValidationResult with validated priority
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for EUI48 records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Priority must be 0 for EUI48 records.'));
    }
}
