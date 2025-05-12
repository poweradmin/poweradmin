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

/**
 * IP address validation service
 *
 * Validates IP addresses according to:
 * - RFC 791: Internet Protocol (IPv4)
 * - RFC 3596: DNS Extensions to Support IP Version 6
 * - RFC 4291: IP Version 6 Addressing Architecture
 * - RFC 5952: A Recommendation for IPv6 Address Text Representation
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class IPAddressValidator
{
    /**
     * Validate an IPv4 address
     *
     * @param string $ipv4 IPv4 address string
     *
     * @return ValidationResult ValidationResult with validated IP or error
     */
    public function validateIPv4(string $ipv4): ValidationResult
    {
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ValidationResult::failure(_('This is not a valid IPv4 address.'));
        }

        return ValidationResult::success($ipv4);
    }

    /**
     * Check if a string is a valid IPv4 address
     *
     * @param string $ipv4 IPv4 address string
     *
     * @return bool True if valid IPv4 address
     */
    public function isValidIPv4(string $ipv4): bool
    {
        return filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate an IPv6 address according to RFC 3596 and RFC 5952
     *
     * Comprehensive validation for IPv6 addresses used in AAAA records. This includes:
     * - Basic format validation using PHP's filter_var
     * - Validation of IPv6 address patterns per RFC 4291
     * - Canonical format recommendations from RFC 5952
     * - Special address validation (loopback, unspecified, etc.)
     * - Checks for deprecated IPv6 formats
     *
     * @param string $ipv6 IPv6 address string
     * @param bool $canonicalForm Whether to enforce RFC 5952 canonical form (lowercase, compression rules)
     *
     * @return ValidationResult ValidationResult with validated IP or error
     */
    public function validateIPv6(string $ipv6, bool $canonicalForm = false): ValidationResult
    {
        // Trim the input to remove any leading/trailing whitespace
        $ipv6 = trim($ipv6);

        // First use PHP's filter_var for basic validation
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return ValidationResult::failure(_('This is not a valid IPv6 address.'));
        }

        // RFC 5952 recommendation - IPv6 addresses should use lowercase for hex digits
        if ($canonicalForm && preg_match('/[A-F]/', $ipv6)) {
            return ValidationResult::failure(_('IPv6 address should use lowercase for hexadecimal digits.'));
        }

        // Additional validation for special IPv6 addresses

        // IPv4-mapped IPv6 addresses (::ffff:a.b.c.d)
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $ipv6, $matches)) {
            // Also validate the IPv4 part
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return ValidationResult::failure(_('Invalid IPv4 part in IPv4-mapped IPv6 address.'));
            }
        }

        // Deprecated IPv6 formats: IPv4-compatible IPv6 address (::a.b.c.d)
        if (preg_match('/^::((?!ffff:)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $ipv6)) {
            return ValidationResult::failure(_('IPv4-compatible IPv6 addresses (::a.b.c.d) are deprecated.'));
        }

        // Check for too many consecutive colons (more than 2 is invalid)
        if (preg_match('/:{3,}/', $ipv6)) {
            return ValidationResult::failure(_('Invalid IPv6 address: more than two consecutive colons.'));
        }

        // Canonicalize the address for further checks
        $expanded = $this->expandIPv6($ipv6);

        // Validate each hextet (8 groups of 4 hex digits)
        $hextets = explode(':', $expanded);
        if (count($hextets) !== 8) {
            return ValidationResult::failure(_('Invalid IPv6 address: must have 8 hextets when expanded.'));
        }

        foreach ($hextets as $hextet) {
            if (!preg_match('/^[0-9a-f]{4}$/i', $hextet)) {
                return ValidationResult::failure(_('Invalid IPv6 address: each hextet must be 4 hexadecimal digits.'));
            }
        }

        // If canonical form is requested, check RFC 5952 recommendations
        if ($canonicalForm) {
            // Convert to canonical form and compare
            $canonical = $this->canonicalizeIPv6($ipv6);
            if ($canonical !== $ipv6) {
                return ValidationResult::failure(sprintf(
                    _('IPv6 address is not in canonical form (RFC 5952). Canonical form: %s'),
                    $canonical
                ));
            }
        }

        return ValidationResult::success($ipv6);
    }

    /**
     * Expand an IPv6 address to its full form
     *
     * Converts abbreviated IPv6 addresses like ::1 to their full form
     *
     * @param string $ipv6 IPv6 address to expand
     * @return string Expanded IPv6 address
     */
    private function expandIPv6(string $ipv6): string
    {
        // If address contains ::, expand it
        if (strpos($ipv6, '::') !== false) {
            $parts = explode('::', $ipv6);
            $left = $parts[0] ? explode(':', $parts[0]) : [];
            $right = $parts[1] ? explode(':', $parts[1]) : [];

            $missing = 8 - count($left) - count($right);
            $expanded = $left;

            for ($i = 0; $i < $missing; $i++) {
                $expanded[] = '0000';
            }

            $expanded = array_merge($expanded, $right);

            // Pad each part to 4 digits
            for ($i = 0; $i < 8; $i++) {
                $expanded[$i] = isset($expanded[$i]) ? str_pad($expanded[$i], 4, '0', STR_PAD_LEFT) : '0000';
            }

            return implode(':', $expanded);
        }

        // If address is already expanded, just pad each part
        $parts = explode(':', $ipv6);
        for ($i = 0; $i < count($parts); $i++) {
            $parts[$i] = str_pad($parts[$i], 4, '0', STR_PAD_LEFT);
        }

        return implode(':', $parts);
    }

    /**
     * Convert IPv6 address to canonical form according to RFC 5952
     *
     * RFC 5952 canonical form rules:
     * 1. Leading zeros in each 16-bit field are suppressed
     * 2. The longest sequence of consecutive zero 16-bit fields is replaced with ::
     * 3. If there are multiple longest runs of zeros, the first one is compressed
     * 4. Lowercase hex digits a-f are used
     *
     * @param string $ipv6 IPv6 address to canonicalize
     * @return string Canonical form of the IPv6 address
     */
    private function canonicalizeIPv6(string $ipv6): string
    {
        // First expand the address fully
        $expanded = $this->expandIPv6($ipv6);
        $parts = explode(':', $expanded);

        // Remove leading zeros and convert to lowercase
        foreach ($parts as &$part) {
            $part = strtolower(ltrim($part, '0'));
            if ($part === '') {
                $part = '0';
            }
        }

        // Find longest sequence of zeros
        $longest = 0;
        $longestStart = -1;
        $currentRun = 0;
        $currentStart = -1;

        for ($i = 0; $i < 8; $i++) {
            if ($parts[$i] === '0') {
                if ($currentRun === 0) {
                    $currentStart = $i;
                }
                $currentRun++;
            } else {
                if ($currentRun > $longest) {
                    $longest = $currentRun;
                    $longestStart = $currentStart;
                }
                $currentRun = 0;
            }
        }

        // Check if the last run is the longest
        if ($currentRun > $longest) {
            $longest = $currentRun;
            $longestStart = $currentStart;
        }

        // Compress only if there are at least 2 consecutive zeros
        if ($longest >= 2) {
            $before = array_slice($parts, 0, $longestStart);
            $after = array_slice($parts, $longestStart + $longest);

            // Handle special cases for beginning and end
            if (empty($before)) {
                $result = ':' . implode(':', $after);
            } elseif (empty($after)) {
                $result = implode(':', $before) . ':';
            } else {
                $result = implode(':', $before) . '::' . implode(':', $after);
            }

            // Ensure :: is used properly
            return str_replace(':::', '::', $result);
        }

        // No compression needed
        return implode(':', $parts);
    }

    /**
     * Check if a string is a valid IPv6 address
     *
     * @param string $ipv6 IPv6 address string
     * @param bool $canonicalForm Whether to also check RFC 5952 canonical form
     *
     * @return bool True if valid IPv6 address
     */
    public function isValidIPv6(string $ipv6, bool $canonicalForm = false): bool
    {
        $result = $this->validateIPv6($ipv6, $canonicalForm);
        return $result->isValid();
    }

    /**
     * Validate multiple IP addresses separated by commas
     *
     * @param string $ips Comma separated IP addresses
     *
     * @return ValidationResult ValidationResult with array of validated IPs or error
     */
    public function validateMultipleIPs(string $ips): ValidationResult
    {
        // Multiple IP records are permitted and must be separated by commas
        // e.g. "192.0.0.1, 192.0.0.2, 2001:1::1"
        $multipleIps = explode(",", $ips);
        $validatedIps = [];
        $errors = [];

        foreach ($multipleIps as $ip) {
            $trimmedIp = trim($ip);

            $ipv4Result = $this->validateIPv4($trimmedIp);
            if ($ipv4Result->isValid()) {
                $validatedIps[] = $ipv4Result->getData();
                continue;
            }

            $ipv6Result = $this->validateIPv6($trimmedIp);
            if ($ipv6Result->isValid()) {
                $validatedIps[] = $ipv6Result->getData();
                continue;
            }

            $errors[] = sprintf(_('IP address "%s" is not valid. Must be a valid IPv4 or IPv6 address.'), $trimmedIp);
        }

        if (count($errors) > 0) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success($validatedIps);
    }

    /**
     * Check if a string contains valid IP addresses separated by commas
     *
     * @param string $ips Comma separated IP addresses
     *
     * @return bool True if all IPs are valid
     */
    public function areMultipleValidIPs(string $ips): bool
    {
        $result = $this->validateMultipleIPs($ips);
        return $result->isValid();
    }
}
