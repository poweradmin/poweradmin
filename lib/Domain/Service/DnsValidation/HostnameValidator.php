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

use Poweradmin\Domain\Model\TopLevelDomain;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Hostname validation service
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class HostnameValidator
{
    private ConfigurationManager $config;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
    }

    /**
     * Validate hostname as FQDN
     *
     * @param mixed $hostname Hostname string
     * @param bool $allowWildcard Allow wildcard (*) in hostname
     *
     * @return ValidationResult Validation result with normalized hostname or error
     */
    public function validate(mixed $hostname, bool $allowWildcard = false): ValidationResult
    {
        $dns_top_level_tld_check = $this->config->get('dns', 'top_level_tld_check');
        $dns_strict_tld_check = $this->config->get('dns', 'strict_tld_check');

        $normalizedHostname = $hostname;

        // Special case for root zone (@) or @.domain format
        if ($normalizedHostname == "." || $normalizedHostname == "@" || str_starts_with($normalizedHostname, "@.")) {
            return ValidationResult::success(['hostname' => $normalizedHostname]);
        }

        $normalizedHostname = preg_replace("/\.$/", "", $normalizedHostname);

        # The full domain name may not exceed a total length of 253 characters.
        if (strlen($normalizedHostname) > 253) {
            return ValidationResult::failure(_('The hostname is too long.'));
        }

        $hostname_labels = explode('.', $normalizedHostname);
        $label_count = count($hostname_labels);

        if ($dns_top_level_tld_check && $label_count == 1) {
            return ValidationResult::failure(_('Single-label hostnames are not allowed.'));
        }

        $errors = [];

        foreach ($hostname_labels as $hostname_label) {
            if ($allowWildcard && !isset($first)) {
                if (!preg_match('/^(\*|[\w\-\/]+)$/', $hostname_label)) {
                    $errors[] = _('You have invalid characters in your zone name.');
                }
                $first = 1;
            } else {
                if (!preg_match('/^[\w\-\/]+$/', $hostname_label)) {
                    $errors[] = _('You have invalid characters in your zone name.');
                }
            }

            if (str_starts_with($hostname_label, "-")) {
                $errors[] = _('A hostname can not start or end with a dash.');
            }

            if (str_ends_with($hostname_label, "-")) {
                $errors[] = _('A hostname can not start or end with a dash.');
            }

            if (strlen($hostname_label) < 1 || strlen($hostname_label) > 63) {
                $errors[] = _('Given hostname or one of the labels is too short or too long.');
            }
        }

        if (count($errors) > 0) {
            return ValidationResult::errors(array_unique($errors));
        }

        // Check ARPA zones - validate RFC 2317 classless reverse delegation
        if ($hostname_labels[$label_count - 1] == "arpa") {
            // RFC 2317 supports classless reverse delegation for subnets smaller than /24
            // Two valid formats:
            // 1. Slash notation: 0/26.1.0.192.in-addr.arpa (subnet/prefix)
            // 2. Range notation: 0-63.1.0.192.in-addr.arpa (start-end)

            $slashCount = substr_count($hostname, "/");

            if ($slashCount > 0) {
                // Validate slash notation (RFC 2317 preferred format)
                // Find label containing slash
                $slashLabel = null;
                foreach ($hostname_labels as $label) {
                    if (strpos($label, '/') !== false) {
                        if ($slashLabel !== null) {
                            return ValidationResult::failure(_('Multiple slashes in different labels are not allowed in ARPA zones.'));
                        }
                        $slashLabel = $label;
                    }
                }

                if ($slashLabel !== null) {
                    $parts = explode("/", $slashLabel);

                    if (count($parts) != 2) {
                        return ValidationResult::failure(_('Invalid RFC 2317 format. Use format: subnet/prefix (e.g., 0/26).'));
                    }

                    $subnet = $parts[0];
                    $prefix = $parts[1];

                    // Determine if this is IPv4 or IPv6 based on zone suffix (must do this before subnet validation)
                    $isIPv4 = ($label_count >= 2 && isset($hostname_labels[$label_count - 2]) &&
                               $hostname_labels[$label_count - 2] == 'in-addr');
                    $isIPv6 = ($label_count >= 2 && isset($hostname_labels[$label_count - 2]) &&
                               $hostname_labels[$label_count - 2] == 'ip6');

                    // Validate subnet based on IP version
                    if ($isIPv4) {
                        // IPv4: subnet must be numeric 0-255
                        if (!is_numeric($subnet) || $subnet < 0 || $subnet > 255) {
                            return ValidationResult::failure(_('Invalid subnet number in RFC 2317 notation. Must be 0-255 for IPv4.'));
                        }
                    } elseif ($isIPv6) {
                        // IPv6: subnet must be hexadecimal (nibbles)
                        if (!ctype_xdigit($subnet)) {
                            return ValidationResult::failure(_('Invalid subnet in RFC 2317 notation. Must be hexadecimal (0-9, a-f) for IPv6.'));
                        }
                    } else {
                        // Unknown ARPA type: accept both numeric and hex for forward compatibility
                        if (!ctype_xdigit($subnet)) {
                            return ValidationResult::failure(_('Invalid subnet in RFC 2317 notation. Must be numeric or hexadecimal.'));
                        }
                    }

                    // Validate prefix length
                    // For in-addr.arpa (IPv4): /25 to /32 (though /32 is uncommon)
                    // For ip6.arpa (IPv6): /0 to /128 (more flexible)
                    if (!is_numeric($prefix)) {
                        return ValidationResult::failure(_('Invalid prefix length in RFC 2317 notation. Must be numeric.'));
                    }

                    // Cast to integers for numeric operations (safe after validation)
                    // Note: For IPv6, we convert hex to decimal for range checking
                    $subnetInt = $isIPv4 ? (int)$subnet : hexdec($subnet);
                    $prefix = (int)$prefix;

                    if ($isIPv4) {
                        // IPv4: /25 to /31 are typical for RFC 2317
                        // Allow /24 to /32 for flexibility
                        if ($prefix < 24 || $prefix > 32) {
                            return ValidationResult::failure(_('Invalid IPv4 prefix length for RFC 2317. Typically 24-32 (classless delegation usually 25-31).'));
                        }

                        // Validate that subnet number is aligned with prefix
                        // For example, 0/26 is valid, but 65/26 is not (should be 64/26)
                        $blockSize = pow(2, 32 - $prefix);
                        if ($subnetInt % $blockSize != 0) {
                            return ValidationResult::failure(
                                sprintf(
                                    _('Subnet %d is not aligned with prefix /%d. Should be multiple of %d.'),
                                    $subnetInt,
                                    $prefix,
                                    $blockSize
                                )
                            );
                        }
                    } elseif ($isIPv6) {
                        // IPv6: allow /0 to /128
                        if ($prefix < 0 || $prefix > 128) {
                            return ValidationResult::failure(_('Invalid IPv6 prefix length. Must be 0-128.'));
                        }
                    } else {
                        // Unknown ARPA type, allow but warn
                        // This is permissive for future ARPA types
                        if ($prefix < 0 || $prefix > 128) {
                            return ValidationResult::failure(_('Invalid prefix length. Must be 0-128.'));
                        }
                    }
                }
            }
        } else {
            // Non-ARPA zones should not have slashes
            if (substr_count($hostname, "/") > 0) {
                return ValidationResult::failure(_('Given hostname has too many slashes.'));
            }
        }

        if ($dns_strict_tld_check && !TopLevelDomain::isValidTopLevelDomain($hostname)) {
            return ValidationResult::failure(_('You are using an invalid top level domain.'));
        }

        return ValidationResult::success(['hostname' => $normalizedHostname]);
    }

    /**
     * Legacy method for compatibility with existing code
     *
     * @param mixed $hostname Hostname string
     * @param mixed $wildcard Whether wildcards are allowed (1/0 or true/false)
     *
     * @return array|bool Returns array with normalized hostname if valid, false otherwise
     * @deprecated Use validate() instead
     */
    public function isValidHostnameFqdn(mixed $hostname, mixed $wildcard): array|bool
    {
        $allowWildcard = (bool)$wildcard;
        $result = $this->validate($hostname, $allowWildcard);

        if (!$result->isValid()) {
            return false;
        }

        return $result->getData();
    }

    /**
     * Simple validator for hostname validity
     *
     * @param string $hostname Hostname to validate
     * @param bool $allowWildcard Allow wildcard (*) in hostname
     *
     * @return bool True if hostname is valid
     */
    public function isValid(string $hostname, bool $allowWildcard = false): bool
    {
        $result = $this->validate($hostname, $allowWildcard);
        return $result->isValid();
    }

    /**
     * Normalize a DNS record name by ensuring it is fully qualified with the zone name
     *
     * @param string $name Name to normalize
     * @param string $zone Zone name
     *
     * @return string Normalized name
     */
    public function normalizeRecordName(string $name, string $zone): string
    {
        // Check if name already ends with the zone name
        if (!$this->endsWith(strtolower($zone), strtolower($name))) {
            // Append zone name if not already there
            if ($name !== "") {
                return $name . "." . $zone;
            } else {
                return $zone;
            }
        }

        // Name already includes zone, return unchanged
        return $name;
    }

    /**
     * Matches end of string
     *
     * Matches end of string (haystack) against another string (needle)
     *
     * @param string $needle
     * @param string $haystack
     *
     * @return bool true if ends with specified string, otherwise false
     */
    public static function endsWith(string $needle, string $haystack): bool
    {
        $length = strlen($haystack);
        $nLength = strlen($needle);
        return $nLength <= $length && strncmp(substr($haystack, -$nLength), $needle, $nLength) === 0;
    }
}
