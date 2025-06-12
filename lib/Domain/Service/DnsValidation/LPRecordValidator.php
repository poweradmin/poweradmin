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
 * LP record validator
 *
 * The LP (Locator Pointer) record is a DNS resource record type used with the
 * Identifier-Locator Network Protocol (ILNP). It is used to hold the name of
 * a subnetwork for ILNP which can then be used to look up L32 or L64 records.
 * LP is, effectively, a Locator Pointer to L32 and/or L64 records.
 *
 * Format: <preference> <FQDN>
 *
 * Where:
 * - preference: A 16-bit unsigned integer (0-65535) indicating relative preference
 *   Lower values are preferred over higher values.
 * - FQDN: A fully qualified domain name that points to one or more L32 or L64 records.
 *   It MUST NOT have the same value as the owner name of the LP record.
 *
 * Example: 10 mobile-net1.example.com.
 *
 * NOTE: The LP record type is defined in RFC 6742 as an experimental protocol.
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
class LPRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates LP record content
     *
     * LP format: <preference> <FQDN>
     * Example: 10 example.com.
     *
     * @param string $content The content of the LP record
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
        $contentResult = $this->validateLPContent($content, $name);
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
            _('NOTE: The LP record type is defined in RFC 6742 as an experimental protocol, not a formal IETF standard.'),
            _('LP records should only be used with the Identifier-Locator Network Protocol (ILNP).'),
            _('LP records MUST NOT be present for nodes that are not ILNP-capable (RFC 6742 Section 3.4).')
        ];

        // Extract the FQDN from the content
        $parts = preg_split('/\s+/', trim($content), 2);
        $fqdn = isset($parts[1]) ? $parts[1] : '';

        // Add warning for matching owner name and target
        if (trim($fqdn, '.') === trim($name, '.')) {
            $warnings[] = _('Warning: The FQDN in an LP record SHOULD NOT have the same value as the owner name (RFC 6742).');
        }

        // Add warning for missing trailing dot
        if (substr($fqdn, -1) !== '.' && !empty($fqdn)) {
            $warnings[] = _('It is recommended to end FQDN values with a trailing dot to ensure they are treated as absolute domain names rather than relative ones.');
        }

        // Add suggestion for longer TTL values
        if ($validatedTtl < 3600) { // Less than 1 hour
            $warnings[] = _('Consider using longer TTL values for LP records. Unlike L32/L64 records, LP records are stable and benefit from longer cache times (RFC 6742).');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an LP record
     * Format: <preference> <FQDN>
     *
     * @param string $content The content to validate
     * @param string $name The owner name of the record
     * @return ValidationResult Validation result with success or error message
     */
    private function validateLPContent(string $content, string $name): ValidationResult
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) !== 2) {
            return ValidationResult::failure(_('LP record must contain preference and FQDN separated by space.'));
        }

        [$preference, $fqdn] = $parts;

        // Validate preference (0-65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            return ValidationResult::failure(_('LP preference must be a number between 0 and 65535.'));
        }

        // Validate FQDN
        $hostnameResult = $this->hostnameValidator->validate($fqdn, true);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::failure(_('LP FQDN must be a valid fully qualified domain name.'));
        }

        // Check if the FQDN has the same value as the owner name (not recommended per RFC 6742)
        if (trim($fqdn, '.') === trim($name, '.')) {
            // We'll just warn about this in the main validation method, not fail validation
        }

        // Check if the FQDN ends with a dot (as recommended for DNS records)
        if (substr($fqdn, -1) !== '.') {
            // This is not an error, but will be mentioned in warnings
        }

        return ValidationResult::success(true);
    }

    /**
     * Extract preference value from LP record content
     *
     * @param string $content The LP record content
     * @return int The preference value
     */
    private function extractPreferenceFromContent(string $content): int
    {
        $parts = preg_split('/\s+/', trim($content));
        return isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 0;
    }
}
