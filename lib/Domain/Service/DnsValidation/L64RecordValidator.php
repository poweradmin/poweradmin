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
 * The L64 record is a DNS resource record type used with the Identifier-Locator
 * Network Protocol (ILNP). It maps a domain name to a 64-bit IPv6 locator value
 * that can be used for ILNP communications with the node. L64 records are used
 * primarily for ILNPv6-capable nodes and help to separate the node identity from
 * its location.
 *
 * Format: <preference> <locator64>
 *
 * Where:
 * - preference: A 16-bit unsigned integer (0-65535) indicating relative preference
 *   Lower values are preferred over higher values.
 * - locator64: A 64-bit value (represented as 4 groups of hexadecimal digits separated
 *   by colons) that has the same syntax and semantics as a 64-bit IPv6 routing prefix.
 *
 * Example: 10 2001:0db8:1140:1000
 *
 * NOTE: The L64 record type is defined in RFC 6742 as an experimental protocol.
 * It is not a formal IETF standard but is published for examination, experimental
 * implementation, and evaluation.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6742 RFC 6742: DNS Resource Records for the Identifier-Locator Network Protocol (ILNP)
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
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
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

        // Add warnings according to RFC 6742
        $warnings = [
            _('NOTE: The L64 record type is defined in RFC 6742 as an experimental protocol, not a formal IETF standard.'),
            _('L64 records should only be used with the Identifier-Locator Network Protocol (ILNP).'),
            _('An IP host that is NOT ILNPv6-capable MUST NOT have L64 records in its DNS entries (RFC 6742 Section 3.2).')
        ];

        // Add recommendation for TTL values for mobile nodes
        if ($validatedTtl > 7200) { // 2 hours
            $warnings[] = _('RFC 6742 recommends very low TTL values for L64 records of mobile or multihomed nodes, as locator values might change frequently.');
        }

        // Extract locator part for further analysis
        $parts = preg_split('/\s+/', trim($content));
        $locator64 = $parts[1] ?? '';

        // Check for special usage cases from RFC 6742
        if (strpos($name, '*') !== false) {
            $warnings[] = _('L64 records for subnetworks (using wildcard DNS entries) are typically used when the named subnetwork is, was, or might become mobile (RFC 6742 Section 3.2).');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an L64 record
     * Format: <preference> <locator64>
     * The locator64 is a 64-bit value with the same syntax and semantics as a 64-bit IPv6 routing prefix.
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
            return ValidationResult::failure(_('L64 locator must be a valid 64-bit hexadecimal IPv6 address segment consisting of exactly 4 groups of hex digits (e.g., 2001:0db8:1140:1000).'));
        }

        // Check for all-zeros or all-ones values, which are special in IPv6 and may not be suitable for ILNP locators
        if (
            $locator64 === '0000:0000:0000:0000' ||
            $locator64 === 'ffff:ffff:ffff:ffff'
        ) {
            return ValidationResult::failure(_('L64 locator should not use unspecified (0000:0000:0000:0000) or all-ones (ffff:ffff:ffff:ffff) addresses, as they may not be suitable for ILNP locators.'));
        }

        // Normalize the format with leading zeros to ensure consistency
        $segments = explode(':', $locator64);
        foreach ($segments as $segment) {
            if (strlen($segment) > 4) {
                return ValidationResult::failure(_('Each hexadecimal group in L64 locator must be 4 or fewer characters.'));
            }
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
     * According to RFC 6742, the locator64 field has the same syntax as a 64-bit IPv6 routing prefix
     *
     * @param string $hex64 The hexadecimal string to validate
     * @return bool True if valid, false otherwise
     */
    private function isValid64BitHex(string $hex64): bool
    {
        // Regular expression for a valid 64-bit IPv6 address segment
        // Should be 4 groups of up to 4 hex digits, separated by colons
        if (!preg_match('/^([0-9a-fA-F]{1,4}:){3}[0-9a-fA-F]{1,4}$/', $hex64)) {
            return false;
        }

        // Verify that we have exactly 4 segments
        $segments = explode(':', $hex64);
        if (count($segments) !== 4) {
            return false;
        }

        // Verify that each segment is valid hexadecimal and not too long
        foreach ($segments as $segment) {
            if (!ctype_xdigit($segment) || strlen($segment) > 4) {
                return false;
            }
        }

        return true;
    }
}
