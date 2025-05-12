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
 * The EUI48 resource record is used to store IEEE EUI-48 addresses in the DNS.
 * These addresses are typically MAC addresses used in Ethernet and other layer-2 networks.
 *
 * Format: xx-xx-xx-xx-xx-xx
 * Where each 'xx' is a two-digit hexadecimal number representing one octet of the
 * 48-bit address. The address is represented as six two-digit hexadecimal numbers
 * separated by hyphens. The hexadecimal digits A-F may be uppercase or lowercase.
 *
 * Examples:
 * - 00-00-5e-00-53-2a
 * - 00-1A-2B-3C-4D-5E
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7043 RFC 7043: Resource Records for EUI-48 and EUI-64 Addresses in the DNS
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
     * @return ValidationResult ValidationResult containing validated data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content - should be a valid EUI-48 (MAC-48) address in xx-xx-xx-xx-xx-xx format
        $contentResult = $this->isValidEUI48($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Add any warnings from content validation
        if ($contentResult->hasWarnings() && is_array($contentResult->getData()) && isset($contentResult->getData()['warnings'])) {
            $warnings = array_merge($warnings, $contentResult->getData()['warnings']);
        }

        // Normalize content to standard format (preserving case as specified in RFC 7043)
        $normalizedContent = $contentResult->getData()['content'] ?? $content;

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for EUI48 records)
        $validatedPrio = $this->validatePriority($prio);
        if (!$validatedPrio->isValid()) {
            return ValidationResult::failure(_('Priority must be 0 for EUI48 records.'));
        }

        // Add privacy warning as recommended by RFC 7043 (Section 5)
        $warnings[] = _('RFC 7043 recommends against publishing EUI-48 addresses in the public DNS due to potential privacy implications.');

        return ValidationResult::success(['content' => $normalizedContent,
            'name' => $name,
            'prio' => $validatedPrio->getData(),
            'ttl' => $validatedTtl], $warnings);
    }

    /**
     * Check if a string is a valid EUI-48 (MAC-48) address
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with validation status
     */
    private function isValidEUI48(string $data): ValidationResult
    {
        $warnings = [];

        // MAC address format: xx-xx-xx-xx-xx-xx where x is a hexadecimal digit
        if (!preg_match('/^([0-9a-fA-F]{2}-){5}[0-9a-fA-F]{2}$/', $data)) {
            // Check for alternative formats and provide helpful error messages
            if (preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $data)) {
                // Common mistake: using colons instead of hyphens
                return ValidationResult::failure(_('EUI48 record uses colon separators (xx:xx:xx:xx:xx:xx) but requires hyphen separators (xx-xx-xx-xx-xx-xx).'));
            } elseif (preg_match('/^([0-9a-fA-F]{2}){6}$/', $data)) {
                // No separators at all
                return ValidationResult::failure(_('EUI48 record is missing separators. Format must be xx-xx-xx-xx-xx-xx with hyphens between octets.'));
            } elseif (preg_match('/^([0-9a-fA-F]{4}[.-]){2}[0-9a-fA-F]{4}$/', $data)) {
                // Cisco format with dots or hyphens
                return ValidationResult::failure(_('EUI48 record appears to be in Cisco format. Format must be xx-xx-xx-xx-xx-xx.'));
            }

            // Default error message
            return ValidationResult::failure(_('EUI48 record must be a valid MAC address in xx-xx-xx-xx-xx-xx format (where x is a hexadecimal digit).'));
        }

        // Check for special address types and add warnings
        $normalizedAddress = strtolower($data);
        $firstOctet = substr($normalizedAddress, 0, 2);

        // Check if it's a multicast address (bit 0 of first octet is 1)
        $firstByte = hexdec($firstOctet);
        if (($firstByte & 0x01) === 0x01) {
            $warnings[] = _('This appears to be a multicast address (the least significant bit of the first octet is set to 1).');
        }

        // Check if it's a locally administered address (bit 1 of first octet is 1)
        if (($firstByte & 0x02) === 0x02) {
            $warnings[] = _('This appears to be a locally administered address (the second least significant bit of the first octet is set to 1).');
        }

        // Check for all-zeros address
        if ($normalizedAddress === '00-00-00-00-00-00') {
            $warnings[] = _('This is the all-zeros address, which may not be valid in all contexts.');
        }

        // Check for all-ones address (broadcast)
        if ($normalizedAddress === 'ff-ff-ff-ff-ff-ff') {
            $warnings[] = _('This is the broadcast address (all-ones), which may not be valid in all contexts.');
        }

        // Check for well-known addresses
        if (substr($normalizedAddress, 0, 8) === '00-00-5e') {
            $warnings[] = _('This appears to be an IANA OUI (00-00-5E) address.');
        }

        return ValidationResult::success([
            'content' => $data, // Preserve case as allowed by RFC 7043
            'warnings' => $warnings
        ]);
    }

    /**
     * Validate priority for EUI48 records
     * EUI48 records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult with validated priority
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
