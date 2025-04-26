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

namespace Poweradmin\Domain\Service;

/**
 * Service for IDN (Internationalized Domain Name) handling
 */
class DnsIdnService
{
    /**
     * Convert a domain name to its Unicode representation
     *
     * @param string $domainName The domain name to convert (can be IDN punycode or UTF-8)
     * @return string The domain name in UTF-8 format
     */
    public static function toUtf8(string $domainName): string
    {
        // Convert punycode (xn--) to UTF-8
        return idn_to_utf8(htmlspecialchars($domainName), IDNA_NONTRANSITIONAL_TO_ASCII);
    }

    /**
     * Convert a domain name to its Punycode representation
     *
     * @param string $domainName The domain name to convert (can be IDN UTF-8 or punycode)
     * @return string The domain name in Punycode format
     */
    public static function toPunycode(string $domainName): string
    {
        // Convert UTF-8 to punycode (xn--)
        return idn_to_ascii($domainName, IDNA_NONTRANSITIONAL_TO_ASCII);
    }

    /**
     * Check if a domain name is an IDN
     *
     * @param string $domainName The domain name to check
     * @return bool True if domain is an IDN
     */
    public static function isIdn(string $domainName): bool
    {
        return strpos($domainName, 'xn--') === 0;
    }

    /**
     * Get the first letter of a domain name in UTF-8 format
     *
     * @param string $domainName The domain name to get the first letter from
     * @return string The first letter of the domain in UTF-8 format
     */
    public static function getFirstLetter(string $domainName): string
    {
        $utf8name = self::toUtf8($domainName);
        return mb_substr($utf8name, 0, 1, 'UTF-8');
    }
}
