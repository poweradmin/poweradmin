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
     * @return ValidationResult<array> Validation result with normalized hostname or error
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

        if (!empty($errors)) {
            return ValidationResult::errors(array_unique($errors));
        }

        // Check ARPA zones
        if ($hostname_labels[$label_count - 1] == "arpa" && (substr_count($hostname_labels[0], "/") == 1 xor substr_count($hostname_labels[1], "/") == 1)) {
            if (substr_count($hostname_labels[0], "/") == 1) {
                $array = explode("/", $hostname_labels[0]);
            } else {
                $array = explode("/", $hostname_labels[1]);
            }

            if (count($array) != 2) {
                return ValidationResult::failure(_('Invalid hostname.'));
            }

            if (!is_numeric($array[0]) || $array[0] < 0 || $array[0] > 255) {
                return ValidationResult::failure(_('Invalid hostname.'));
            }

            if (!is_numeric($array[1]) || $array[1] < 25 || $array[1] > 31) {
                return ValidationResult::failure(_('Invalid hostname.'));
            }
        } else {
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
            if (isset($name) && $name != "") {
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
     * @return true if ends with specified string, otherwise false
     */
    public static function endsWith(string $needle, string $haystack): bool
    {
        $length = strlen($haystack);
        $nLength = strlen($needle);
        return $nLength <= $length && strncmp(substr($haystack, -$nLength), $needle, $nLength) === 0;
    }
}
