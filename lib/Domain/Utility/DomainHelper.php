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

class DomainHelper
{
    private const NEWLINE_PATTERNS = '/\r\n|\r|\n/';

    public static function getDomains(string $domainsString): array
    {
        if (empty($domainsString)) {
            return [];
        }

        $lines = preg_split(self::NEWLINE_PATTERNS, $domainsString);
        $domains = [];

        foreach ($lines as $line) {
            $domain = trim($line);
            if (empty($domain)) {
                continue;
            }

            $asciiDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII);
            if ($asciiDomain !== false) {
                $domains[] = strtolower($asciiDomain);
            }
        }

        return $domains;
    }
}
