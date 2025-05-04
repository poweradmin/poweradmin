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

namespace Poweradmin\Domain\Utility;

/**
 * Wrapper for network functions to allow for easier testing
 */
class NetworkUtility
{
    /**
     * Instance for testing mock purposes
     *
     * @var NetworkUtility|null
     */
    private static $instance = null;

    /**
     * Wrapper for inet_pton function
     *
     * @param string $ip IP address
     * @return string|false Binary representation of the IP address or false on failure
     */
    public static function inetPton(string $ip)
    {
        // If we have a test instance, use it
        if (self::$instance !== null) {
            return self::$instance->inetPton($ip);
        }

        // Default to real function
        if (!function_exists('inet_pton')) {
            // Fallback implementation if inet_pton is not available
            // This would be a simplified version, not for production
            return false;
        }

        return inet_pton($ip);
    }

    /**
     * Wrapper for inet_ntop function
     *
     * @param string $binary Binary representation of the IP address
     * @return string|false IP address or false on failure
     */
    public static function inetNtop(string $binary)
    {
        // If we have a test instance, use it
        if (self::$instance !== null) {
            return self::$instance->inetNtop($binary);
        }

        // Default to real function
        if (!function_exists('inet_ntop')) {
            // Fallback implementation if inet_ntop is not available
            return false;
        }

        return inet_ntop($binary);
    }
}
