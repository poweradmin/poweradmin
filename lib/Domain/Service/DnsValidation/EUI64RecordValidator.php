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
 * EUI64 record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class EUI64RecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates EUI64 record content
     *
     * @param string $content The content of the EUI64 record (EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for EUI64 records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content - should be a valid EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format
        $contentResult = $this->validateEUI64($content);
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

        // Validate priority (should be 0 for EUI64 records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for EUI64 records must be 0 or empty.'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // EUI64 records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate an EUI-64 address
     *
     * @param string $data The data to check
     * @return ValidationResult Validation result with success or error message
     */
    private function validateEUI64(string $data): ValidationResult
    {
        // EUI-64 format: xx-xx-xx-xx-xx-xx-xx-xx where x is a hexadecimal digit
        if (preg_match('/^([0-9a-fA-F]{2}-){7}[0-9a-fA-F]{2}$/', $data)) {
            return ValidationResult::success(true);
        }
        return ValidationResult::failure(_('EUI64 record must be a valid EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format (where x is a hexadecimal digit).'));
    }
}
