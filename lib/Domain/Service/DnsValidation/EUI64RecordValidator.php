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
 * The EUI64 resource record is used to store IEEE EUI-64 addresses in the DNS.
 * These addresses are used in various network interfaces, including IPv6 addresses
 * and some IEEE 802 network protocols.
 *
 * Format: xx-xx-xx-xx-xx-xx-xx-xx
 * Where each 'xx' is a two-digit hexadecimal number representing one octet of the
 * 64-bit address. The address is represented as eight two-digit hexadecimal numbers
 * separated by hyphens. The hexadecimal digits A-F may be uppercase or lowercase.
 *
 * Examples:
 * - 00-11-22-33-44-55-66-77
 * - 00-0A-95-9D-68-16-00-01
 * - 02-00-5E-10-00-00-00-01
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7043 RFC 7043: Resource Records for EUI-48 and EUI-64 Addresses in the DNS
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
        $warnings = [];

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

        // Get data from content validation
        $contentData = $contentResult->getData();

        // Add any warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        // Normalize content to standard format (preserving case as specified in RFC 7043)
        $normalizedContent = $contentData['content'] ?? $content;

        // Check for special patterns in the EUI-64 address
        $normalizedAddress = strtolower($normalizedContent);

        // Check for EUI-48 derived address (FF-FE pattern)
        if (strpos($normalizedAddress, '-ff-fe-') !== false) {
            $warnings[] = _('This appears to be an EUI-64 address derived from an EUI-48 address (contains FF-FE in the middle).');

            // Check for the universal/local bit to identify IPv6 interface identifiers
            $firstOctet = substr($normalizedAddress, 0, 2);
            $firstByte = hexdec($firstOctet);
            if (($firstByte & 0x02) === 0x02) {
                $warnings[] = _('This appears to be an IPv6 interface identifier (has the universal/local bit flipped compared to standard EUI-64).');
            }
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

        // Add privacy warning as recommended by RFC 7043 (Section 5)
        $warnings[] = _('RFC 7043 recommends against publishing EUI-64 addresses in the public DNS due to potential privacy implications.');

        return ValidationResult::success(['content' => $normalizedContent,
            'name' => $name,
            'prio' => 0, // EUI64 records don't use priority
            'ttl' => $validatedTtl], $warnings);
    }

    /**
     * Validate an EUI-64 address
     *
     * @param string $data The data to check
     * @return ValidationResult Validation result with success or error message
     */
    private function validateEUI64(string $data): ValidationResult
    {
        $warnings = [];

        // EUI-64 format: xx-xx-xx-xx-xx-xx-xx-xx where x is a hexadecimal digit
        if (!preg_match('/^([0-9a-fA-F]{2}-){7}[0-9a-fA-F]{2}$/', $data)) {
            // Check for alternative formats and provide helpful error messages
            if (preg_match('/^([0-9a-fA-F]{2}:){7}[0-9a-fA-F]{2}$/', $data)) {
                // Common mistake: using colons instead of hyphens
                return ValidationResult::failure(_('EUI64 record uses colon separators (xx:xx:xx:xx:xx:xx:xx:xx) but requires hyphen separators (xx-xx-xx-xx-xx-xx-xx-xx).'));
            } elseif (preg_match('/^([0-9a-fA-F]{2}){8}$/', $data)) {
                // No separators at all
                return ValidationResult::failure(_('EUI64 record is missing separators. Format must be xx-xx-xx-xx-xx-xx-xx-xx with hyphens between octets.'));
            } elseif (preg_match('/^([0-9a-fA-F]{4}[.-]){3}[0-9a-fA-F]{4}$/', $data)) {
                // Dotted format
                return ValidationResult::failure(_('EUI64 record appears to be in dotted format. Format must be xx-xx-xx-xx-xx-xx-xx-xx.'));
            }

            // Default error message
            return ValidationResult::failure(_('EUI64 record must be a valid EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format (where x is a hexadecimal digit).'));
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
        if ($normalizedAddress === '00-00-00-00-00-00-00-00') {
            $warnings[] = _('This is the all-zeros address, which may not be valid in all contexts.');
        }

        // Check for either EUI-48 derived address or IPv6 interface identifier
        if (substr($normalizedAddress, 6, 4) === 'ff-fe') {
            // This is a derived EUI-48 address
            $warnings[] = _('This appears to be an EUI-64 address derived from an EUI-48 address (contains FF-FE in the middle).');

            // If the universal/local bit is set, it's likely an IPv6 interface identifier
            if (($firstByte & 0x02) === 0x02) {
                $warnings[] = _('This appears to be an IPv6 interface identifier (has the universal/local bit flipped compared to standard EUI-64).');
            }
        }

        // Check for well-known addresses
        if (substr($normalizedAddress, 0, 8) === '00-00-5e') {
            $warnings[] = _('This appears to be an IANA OUI (00-00-5E) address.');
        }

        return ValidationResult::success([
            'content' => $data // Preserve case as allowed by RFC 7043
        ], $warnings);
    }
}
