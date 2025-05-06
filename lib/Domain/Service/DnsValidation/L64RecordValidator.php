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
 * L64 record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class L64RecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates L64 record content
     *
     * L64 format: <preference> <locator64>
     * Example: 10 2001:0db8:1140:1000
     *
     * @param string $content The content of the L64 record
     * @param string $name The name of the record
     * @param mixed $prio The priority (preference) value
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

        // Validate content
        $contentResult = $this->validateL64Content($content);
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

        // Use the provided priority if available, otherwise extract from content
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : $this->extractPreferenceFromContent($content);

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an L64 record
     * Format: <preference> <locator64>
     * The locator64 is the lower 64 bits of an IPv6 address.
     *
     * @param string $content The content to validate
     * @return ValidationResult Validation result with success or error message
     */
    private function validateL64Content(string $content): ValidationResult
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) !== 2) {
            return ValidationResult::failure(_('L64 record must contain preference and locator64 separated by space.'));
        }

        [$preference, $locator64] = $parts;

        // Validate preference (0-65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            return ValidationResult::failure(_('L64 preference must be a number between 0 and 65535.'));
        }

        // Validate locator64 (must be a valid 64-bit IPv6 address part)
        if (!$this->isValid64BitHex($locator64)) {
            return ValidationResult::failure(_('L64 locator must be a valid 64-bit hexadecimal IPv6 address segment (e.g., 2001:0db8:1140:1000).'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Extract preference value from L64 record content
     *
     * @param string $content The L64 record content
     * @return int The preference value
     */
    private function extractPreferenceFromContent(string $content): int
    {
        $parts = preg_split('/\s+/', trim($content));
        return isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 0;
    }

    /**
     * Check if the given string is a valid 64-bit hexadecimal address segment
     *
     * @param string $hex64 The hexadecimal string to validate
     * @return bool True if valid, false otherwise
     */
    private function isValid64BitHex(string $hex64): bool
    {
        // Regular expression for a valid 64-bit IPv6 address segment
        // Should be 4 groups of up to 4 hex digits, separated by colons
        return (bool) preg_match('/^([0-9a-fA-F]{1,4}:){3}[0-9a-fA-F]{1,4}$/', $hex64);
    }
}
