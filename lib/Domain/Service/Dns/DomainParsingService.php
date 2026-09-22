<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
 *
 */

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Port\PublicSuffixInterface;

/**
 * Splits a domain name into subdomain, domain and TLD parts.
 */
final class DomainParsingService
{
    private PublicSuffixInterface $publicSuffix;

    public function __construct(PublicSuffixInterface $publicSuffix)
    {
        $this->publicSuffix = $publicSuffix;
    }

    /**
     * Parse a domain into its components (domain name and TLD)
     *
     * @param string $domain The domain to parse
     * @return array Array with 'domain' and 'tld' keys
     */
    public function parseDomain(string $domain): array
    {
        // Reverse zones and IPs have no registrable part to split off
        if (preg_match('/in-addr\.arpa$/i', $domain) || filter_var($domain, FILTER_VALIDATE_IP)) {
            return [
                'domain' => $domain,
                'tld' => ''
            ];
        }

        $split = $this->publicSuffix->split($domain);
        if ($split['name'] !== '' && $split['suffix'] !== '') {
            return [
                'domain' => $split['name'],
                'tld' => $split['suffix']
            ];
        }

        // Names the suffix list cannot place, such as a single label
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return [
                'domain' => $domain,
                'tld' => ''
            ];
        }

        $tld = array_pop($parts);

        return [
            'domain' => $parts[count($parts) - 1],
            'tld' => $tld
        ];
    }
}
