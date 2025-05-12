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
 * L32 record validator
 *
 * The L32 record is a DNS resource record type used with the Identifier-Locator
 * Network Protocol (ILNP). It maps a domain name to a 32-bit IPv4 locator value
 * that can be used for ILNP communications with the node. L32 records are typically
 * used for naming subnetworks, especially those that are, were, or might become mobile.
 *
 * Format: <preference> <locator>
 *
 * Where:
 * - preference: A 16-bit unsigned integer (0-65535) indicating relative preference
 *   Lower values are preferred over higher values.
 * - locator: A 32-bit IPv4 address representing the locator value.
 *
 * Example: 10 192.0.2.1
 *
 * NOTE: The L32 record type is defined in RFC 6742 as an experimental protocol.
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
class L32RecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = new IPAddressValidator();
    }

    /**
     * Validates L32 record content
     *
     * L32 format: <preference> <locator>
     * Example: 10 192.0.2.1
     *
     * @param string $content The content of the L32 record
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
        $contentResult = $this->validateL32Content($content);
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
            _('NOTE: The L32 record type is defined in RFC 6742 as an experimental protocol, not a formal IETF standard.'),
            _('L32 records should only be used with the Identifier-Locator Network Protocol (ILNP).')
        ];

        // Check for reserved or private IP addresses which may not be suitable for ILNP
        $parts = preg_split('/\s+/', trim($content));
        $locator = $parts[1] ?? '';

        // Check if using private IP addresses in L32 records
        if (
            strpos($locator, '10.') === 0 ||
            strpos($locator, '172.16.') === 0 ||
            strpos($locator, '192.168.') === 0
        ) {
            $warnings[] = _('Using private IP addresses (RFC 1918) as L32 locators may limit reachability in ILNP deployments.');
        }

        // Add recommendation for TTL values for mobile nodes
        if ($validatedTtl > 7200) { // 2 hours
            $warnings[] = _('RFC 6742 recommends very low TTL values for L32 records of mobile or multihomed nodes, as locator values might change frequently.');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an L32 record
     * Format: <preference> <locator>
     * The locator is a 32-bit IPv4 address.
     *
     * @param string $content The content to validate
     * @return ValidationResult Validation result with success or error message
     */
    private function validateL32Content(string $content): ValidationResult
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) !== 2) {
            return ValidationResult::failure(_('L32 record must contain preference and locator separated by space.'));
        }

        [$preference, $locator] = $parts;

        // Validate preference (0-65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            return ValidationResult::failure(_('L32 preference must be a number between 0 and 65535.'));
        }

        // Validate locator (must be a valid IPv4 address)
        $ipResult = $this->ipValidator->validateIPv4($locator);
        if (!$ipResult->isValid()) {
            return ValidationResult::failure(_('L32 locator must be a valid IPv4 address.'));
        }

        // Per RFC 6742, ensure the L32 locator is fully specified
        $octets = explode('.', $locator);
        if (count($octets) !== 4) {
            return ValidationResult::failure(_('L32 locator must be a fully specified IPv4 address.'));
        }

        // RFC 6742 doesn't explicitly forbid 0.0.0.0 or 255.255.255.255,
        // but these values are special in IPv4 and not suitable for ILNP locators
        if ($locator === '0.0.0.0' || $locator === '255.255.255.255') {
            return ValidationResult::failure(_('L32 locator cannot use broadcast or unspecified addresses (0.0.0.0 or 255.255.255.255).'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Extract preference value from L32 record content
     *
     * @param string $content The L32 record content
     * @return int The preference value
     */
    private function extractPreferenceFromContent(string $content): int
    {
        $parts = preg_split('/\s+/', trim($content));
        return isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 0;
    }
}
