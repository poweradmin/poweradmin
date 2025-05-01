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

use Poweradmin\Infrastructure\Service\MessageService;

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
    private MessageService $messageService;

    public function __construct()
    {
        $this->messageService = new MessageService();
    }

    /** Test if IPv4 address is valid
     *
     * @param string $ipv4 IPv4 address string
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public function isValidIPv4(string $ipv4, bool $answer = true): bool
    {
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            if ($answer) {
                $this->messageService->addSystemError(_('This is not a valid IPv4 address.'));
            }
            return false;
        }

        return true;
    }

    /** Test if IPv6 address is valid
     *
     * @param string $ipv6 IPv6 address string
     * @param boolean $answer print error if true
     * [default=false]
     *
     * @return boolean true if valid, false otherwise
     */
    public function isValidIPv6(string $ipv6, bool $answer = false): bool
    {
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            if ($answer) {
                $this->messageService->addSystemError(_('This is not a valid IPv6 address.'));
            }
            return false;
        }

        return true;
    }

    /** Test if multiple IP addresses are valid
     *
     *  Takes a string of comma separated IP addresses and tests validity
     *
     * @param string $ips Comma separated IP addresses
     *
     * @return boolean true if valid, false otherwise
     */
    public function areMultipleValidIPs(string $ips): bool
    {
        // Multiple master NS-records are permitted and must be separated by ,
        // e.g. "192.0.0.1, 192.0.0.2, 2001:1::1"

        $areValid = false;
        $multipleIps = explode(",", $ips);

        if (is_array($multipleIps)) {
            foreach ($multipleIps as $ip) {
                $trimmedIp = trim($ip);
                if ($this->isValidIPv4($trimmedIp, false) || $this->isValidIPv6($trimmedIp)) {
                    $areValid = true;
                } else {
                    return false;
                }
            }
        } elseif ($this->isValidIPv4($ips, false) || $this->isValidIPv6($ips)) {
            $areValid = true;
        }

        return $areValid;
    }
}
