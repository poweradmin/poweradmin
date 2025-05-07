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
     * Validate an IPv6 address
     *
     * @param string $ipv6 IPv6 address string
     *
     * @return ValidationResult ValidationResult with validated IP or error
     */
    public function validateIPv6(string $ipv6): ValidationResult
    {
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return ValidationResult::failure(_('This is not a valid IPv6 address.'));
        }

        return ValidationResult::success($ipv6);
    }

    /**
     * Check if a string is a valid IPv6 address
     *
     * @param string $ipv6 IPv6 address string
     *
     * @return bool True if valid IPv6 address
     */
    public function isValidIPv6(string $ipv6): bool
    {
        return filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
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
