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
 * DHCID record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DHCIDRecordValidator implements DnsRecordValidatorInterface
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
     * Validates DHCID record content
     *
     * @param string $content The content of the DHCID record (base64-encoded data)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for DHCID records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
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

        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in DHCID record content.'));
        }

        // Validate content - DHCID should be a base64-encoded string
        $base64Result = $this->validateBase64($content);
        if (!$base64Result->isValid()) {
            return $base64Result;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for DHCID records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DHCID records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // DHCID records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Basic pattern for base64-encoded data
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('DHCID record must contain only valid base64 characters.'));
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('DHCID record must contain valid base64-encoded data.'));
        }

        return ValidationResult::success(true);
    }
}
