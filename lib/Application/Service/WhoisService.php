<?php

namespace Poweradmin\Application\Service;

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

class WhoisService
{
    private array $whoisServers = [];
    private string $dataFile;

    /**
     * WhoisService constructor.
     *
     * @param string $dataFile Path to the whois servers JSON file
     */
    public function __construct(string $dataFile = '')
    {
        // Default path to whois servers data file
        $this->dataFile = $dataFile ?: __DIR__ . '/../../../data/whois_servers.json';
        $this->loadWhoisServers();
    }

    /**
     * Load whois servers data from JSON file
     *
     * @return bool True if loading was successful, false otherwise
     */
    private function loadWhoisServers(): bool
    {
        if (!file_exists($this->dataFile)) {
            return false;
        }

        $jsonData = file_get_contents($this->dataFile);
        if ($jsonData === false) {
            return false;
        }

        $servers = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($servers)) {
            return false;
        }

        $this->whoisServers = $servers;
        return true;
    }

    /**
     * Get whois server for a given TLD
     *
     * @param string $tld The top-level domain (e.g. "com", "net", "org", "co.uk")
     * @return string|null The whois server hostname or null if not found
     */
    public function getWhoisServer(string $tld): ?string
    {
        $tld = strtolower(trim($tld));

        // Direct match
        if (isset($this->whoisServers[$tld])) {
            return $this->whoisServers[$tld];
        }

        // No direct match found
        return null;
    }

    /**
     * Get whois server for a domain
     *
     * @param string $domain The domain name (e.g. "example.com")
     * @return string|null The whois server hostname or null if not found
     */
    public function getWhoisServerForDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        // Extract TLD from domain
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return null;
        }

        // Try more specific TLDs first (e.g., uk.com before com)
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $possibleTld = implode('.', array_slice($parts, -$i));
            $whoisServer = $this->getWhoisServer($possibleTld);

            if ($whoisServer !== null) {
                return $whoisServer;
            }
        }

        return null;
    }

    /**
     * Check if a TLD has a registered whois server
     *
     * @param string $tld The top-level domain to check
     * @return bool True if the TLD has a whois server, false otherwise
     */
    public function hasTld(string $tld): bool
    {
        return $this->getWhoisServer($tld) !== null;
    }

    /**
     * Get all available whois servers
     *
     * @return array Associative array with TLDs as keys and whois servers as values
     */
    public function getAllWhoisServers(): array
    {
        return $this->whoisServers;
    }

    /**
     * Refresh whois servers data by reloading from the JSON file
     *
     * @return bool True if refresh was successful, false otherwise
     */
    public function refresh(): bool
    {
        return $this->loadWhoisServers();
    }
}
