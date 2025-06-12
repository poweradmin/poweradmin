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
 * Validator for APL (Address Prefix List) DNS records
 *
 * Validates APL records according to RFC 3123:
 * https://tools.ietf.org/html/rfc3123
 *
 * APL RR type has a value of 42 and is used for lists of address prefixes, primarily
 * for network access control or description purposes.
 *
 * Format: [!]afi:address/prefix [!]afi:address/prefix ...
 *
 * Where:
 * - ! (optional): Negation symbol
 * - afi: Address Family Identifier (1 for IPv4, 2 for IPv6)
 * - address: Network address in appropriate format (IPv4 dotted quad or IPv6 notation)
 * - prefix: Prefix length (0-32 for IPv4, 0-128 for IPv6)
 *
 * Multiple address prefix items can be listed, separated by whitespace.
 * An empty APL RR is valid and represents an empty list.
 *
 * Examples:
 * - "1:192.0.2.0/24" (IPv4 subnet)
 * - "2:2001:db8::/32" (IPv6 subnet)
 * - "!1:192.0.2.0/24 2:2001:db8::/32" (Negated IPv4 subnet plus IPv6 subnet)
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class APLRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = new IPAddressValidator();
        $this->config = $config;
    }

    /**
     * Validate APL record
     *
     * @param string $content APL content in format "1:192.0.2.0/24 2:2001:db8::/32"
     * @param string $name Record hostname
     * @param mixed $prio Priority (not used for APL records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Skip specific warning for empty APL record
        if (empty(trim($content))) {
            $warnings[] = _('Empty APL record represents an empty list of address prefixes.');
        }

        // Check for specific warning for trailing zeros in IPv4 address
        if (preg_match('/^1:(\d+\.\d+\.\d+\.\d+)\/(\d+)$/', $content, $matches)) {
            $address = $matches[1];
            $prefix = (int)$matches[2];

            // Check for trailing zeros in the IP that should be trimmed
            $parts = explode('.', $address);
            $significantOctets = ceil($prefix / 8);
            $trailingZeros = false;

            for ($i = (int)$significantOctets; $i < 4; $i++) {
                if (isset($parts[$i]) && $parts[$i] !== '0') {
                    $trailingZeros = true;
                    break;
                }
            }

            if ($trailingZeros) {
                $warnings[] = _('RFC 3123 recommends that trailing zero octets should not be present in APL address parts.');
            }
        }

        // 1. Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // 2. Validate APL content
        $contentResult = $this->validateAPLContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Check for warnings from content validation
        if ($contentResult->isValid() && $contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        // 3. Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // 4. Validate priority (should be 0 for APL records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        // Add security warning if this looks like an access control application
        if (strpos(strtolower($name), '_axfr') !== false || strpos(strtolower($name), 'access') !== false) {
            $warnings[] = _('RFC 3123 notes security considerations when using APL records for access control lists.');
        }

        $result = [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];

        // Return with explicit warnings parameter
        return ValidationResult::success($result, $warnings);
    }

    /**
     * Validate priority for APL records
     * APL records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for APL records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. APL records must have priority value of 0.'));
    }

    /**
     * Validate APL content format
     * Examples: "1:192.0.2.0/24" or "2:2001:db8::/32" or "1:192.0.2.0/24 !2:2001:db8::/32"
     *
     * According to RFC 3123, an empty APL RR is valid and represents an empty list.
     *
     * @param string $content The APL content to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateAPLContent(string $content): ValidationResult
    {
        // Handle empty content - RFC 3123 permits empty APL RR
        if (empty(trim($content))) {
            // Return success with a warning about empty APL RR
            return ValidationResult::success(
                [],
                [_('Empty APL record represents an empty list of address prefixes.')]
            );
        }

        // Split content by whitespace to handle multiple address prefix elements
        $prefixElements = preg_split('/\s+/', trim($content));
        $warnings = [];

        foreach ($prefixElements as $element) {
            $elementResult = $this->validateAPLElement($element);
            if (!$elementResult->isValid()) {
                return $elementResult;
            }

            // Check if there are any warnings from element validation
            if ($elementResult->isValid() && $elementResult->hasWarnings()) {
                $warnings = array_merge($warnings, $elementResult->getWarnings());
            }
        }

        return ValidationResult::success(true, $warnings);
    }

    /**
     * Validate a single APL element
     * Format: [!]afi:address/prefix
     *
     * According to RFC 3123:
     * - Trailing zero octets in the address part are ignored and SHOULD NOT be present in an APL RR RDATA element
     * - The address part MUST end on an octet boundary
     *
     * @param string $element The APL element to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateAPLElement(string $element): ValidationResult
    {
        $warnings = [];

        // Check if element starts with negation
        $negation = false;
        if (str_starts_with($element, '!')) {
            $negation = true;
            $element = substr($element, 1);
        }

        // Check for afi:address/prefix format
        if (!preg_match('/^(\d+):([^\/]+)\/(\d+)$/', $element, $matches)) {
            return ValidationResult::failure(_('Invalid APL element format. Expected [!]afi:address/prefix.'));
        }

        $afi = (int)$matches[1];
        $address = $matches[2];
        $prefix = (int)$matches[3];

        // Validate Address Family Identifier (AFI)
        // 1 = IPv4, 2 = IPv6 (as per RFC 3123)
        if ($afi !== 1 && $afi !== 2) {
            return ValidationResult::failure(_('Invalid Address Family Identifier (AFI). Must be 1 for IPv4 or 2 for IPv6.'));
        }

        // Validate address and prefix based on AFI
        if ($afi === 1) {
            // IPv4
            $ipv4Result = $this->ipValidator->validateIPv4($address);
            if (!$ipv4Result->isValid()) {
                return ValidationResult::failure(_('Invalid IPv4 address in APL record.'));
            }

            // IPv4 prefix must be between 0 and 32
            if ($prefix < 0 || $prefix > 32) {
                return ValidationResult::failure(_('IPv4 prefix must be between 0 and 32.'));
            }

            // Check for trailing zeros in the IP that should be trimmed
            $parts = explode('.', $address);
            $significantOctets = ceil($prefix / 8);
            $trailingZeros = false;

            for ($i = (int)$significantOctets; $i < 4; $i++) {
                if (isset($parts[$i]) && $parts[$i] !== '0') {
                    $trailingZeros = true;
                    break;
                }
            }

            if ($trailingZeros) {
                $warnings[] = _('RFC 3123 recommends that trailing zero octets should not be present in APL address parts.');
            }
        } else {
            // IPv6
            $ipv6Result = $this->ipValidator->validateIPv6($address);
            if (!$ipv6Result->isValid()) {
                return ValidationResult::failure(_('Invalid IPv6 address in APL record.'));
            }

            // IPv6 prefix must be between 0 and 128
            if ($prefix < 0 || $prefix > 128) {
                return ValidationResult::failure(_('IPv6 prefix must be between 0 and 128.'));
            }

            // Note: Checking for trailing zeros in IPv6 is complex and omitted here
            // But similar logic could be applied for IPv6 addresses
        }

        // For security purposes, warn users about using APL records for access control
        if (preg_match('/_axfr/i', $this->config->get('dns', 'domain', '')) && $afi === 1) {
            $warnings[] = _('Using APL records for AXFR access control should be combined with other security measures as noted in RFC 3123.');
        }

        return ValidationResult::success(true, $warnings);
    }
}
