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
 * Service for parsing domain names into components
 */
class DomainParsingService
{
    /**
     * Parse a domain into its components (domain name and TLD)
     *
     * @param string $domain The domain to parse
     * @return array Array with 'domain' and 'tld' keys
     */
    public function parseDomain(string $domain): array
    {
        $domainName = '';
        $tld = '';

        // First check if this is an IP address or special domain (e.g., in-addr.arpa)
        if (preg_match('/in-addr\.arpa$/i', $domain) || filter_var($domain, FILTER_VALIDATE_IP)) {
            // For reverse zones or IPs, return the whole domain as domain name
            return [
                'domain' => $domain,
                'tld' => ''
            ];
        }

        // Simple parsing logic that handles most common cases
        $parts = explode('.', $domain);

        if (count($parts) >= 2) {
            // Check for compound TLDs (e.g., co.uk, co.jp, etc.)
            $lastPart = $parts[count($parts) - 1];
            $secondLastPart = $parts[count($parts) - 2];

            // Common compound TLDs
            $compoundTldPatterns = [
                'co' => ['uk', 'jp', 'kr', 'nz', 'za', 'in'],
                'com' => ['au', 'br', 'cn', 'eg', 'hk', 'mx', 'sg', 'tr', 'tw', 'ua'],
                'net' => ['au', 'br', 'cn', 'in', 'nz', 'ua'],
                'org' => ['au', 'cn', 'in', 'nz', 'uk', 'ua'],
                'ac' => ['uk', 'jp', 'kr', 'nz', 'za'],
                'gov' => ['au', 'br', 'cn', 'in', 'uk', 'ua'],
                'edu' => ['au', 'cn', 'in', 'ua'],
                'ne' => ['jp'],
                'or' => ['jp', 'kr'],
                'go' => ['jp', 'kr'],
                'mil' => ['kr'],
                'nic' => ['in'],
                'res' => ['in'],
                'ltd' => ['uk'],
                'plc' => ['uk'],
                'me' => ['uk'],
                'sch' => ['uk'],
                'nhs' => ['uk'],
                'police' => ['uk'],
                'mod' => ['uk']
            ];

            // Check if this is a compound TLD
            if (
                isset($compoundTldPatterns[$secondLastPart]) &&
                in_array($lastPart, $compoundTldPatterns[$secondLastPart])
            ) {
                // It's a compound TLD like co.uk
                $tld = $secondLastPart . '.' . $lastPart;

                // Remove the compound TLD parts to get the domain
                array_pop($parts); // Remove last part (e.g., 'uk')
                array_pop($parts); // Remove second last part (e.g., 'co')
                $domainName = implode('.', $parts);

                // If we have subdomains, we need just the main domain
                if (count($parts) > 1) {
                    $domainName = $parts[count($parts) - 1];
                }
            } else {
                // Simple TLD
                $tld = array_pop($parts);
                $domainName = implode('.', $parts);

                // If we have subdomains, we need just the main domain
                if (count($parts) > 1) {
                    $domainName = $parts[count($parts) - 1];
                }
            }
        } else {
            // Single part domain (e.g., 'localhost')
            $domainName = $domain;
            $tld = '';
        }

        return [
            'domain' => $domainName,
            'tld' => $tld
        ];
    }
}
