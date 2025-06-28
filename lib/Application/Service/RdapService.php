<?php

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\DnsIdnService;

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

class RdapService
{
    private array $rdapServers = [];
    private string $dataFile;
    private int $requestTimeout = 10;

    /**
     * RdapService constructor.
     *
     * @param string $dataFile Path to the RDAP servers JSON file
     */
    public function __construct(string $dataFile = '')
    {
        // Default path to RDAP servers data file
        $this->dataFile = $dataFile ?: __DIR__ . '/../../../data/rdap_servers.json';
        $this->loadRdapServers();
    }

    /**
     * Load RDAP servers data from JSON file
     *
     * @return bool True if loading was successful, false otherwise
     */
    private function loadRdapServers(): bool
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

        $this->rdapServers = $servers;
        return true;
    }

    /**
     * Get RDAP server URL for a given TLD
     *
     * @param string $tld The top-level domain (e.g. "com", "net", "org", "co.uk")
     * @return string|null The RDAP server URL or null if not found
     */
    public function getRdapServer(string $tld): ?string
    {
        $tld = strtolower(trim($tld));

        // Check if this might be an IDN TLD
        if (preg_match('/[^\x20-\x7E]/', $tld)) {
            // Try to convert to punycode and check if we have a server for that
            $punycodeTld = DnsIdnService::toPunycode($tld);
            if ($punycodeTld !== false && isset($this->rdapServers[$punycodeTld])) {
                return $this->rdapServers[$punycodeTld];
            }
        } elseif (str_starts_with($tld, 'xn--')) {
            try {
                // Try to convert to Unicode and check if we have a server for that
                $unicodeTld = DnsIdnService::toUtf8($tld);
                if (
                    $unicodeTld !== false && $unicodeTld !== $tld &&
                    isset($this->rdapServers[$unicodeTld])
                ) {
                    return $this->rdapServers[$unicodeTld];
                }
            } catch (\Exception $e) {
                // Conversion failed, continue with regular lookup
            }
        }

        // Direct match
        if (isset($this->rdapServers[$tld])) {
            return $this->rdapServers[$tld];
        }

        // No direct match found
        return null;
    }

    /**
     * Get RDAP server URL for a domain
     *
     * @param string $domain The domain name (e.g. "example.com")
     * @return string|null The RDAP server URL or null if not found
     */
    public function getRdapServerForDomain(string $domain): ?string
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
            $rdapServer = $this->getRdapServer($possibleTld);

            if ($rdapServer !== null) {
                return $rdapServer;
            }
        }

        return null;
    }

    /**
     * Check if a TLD has a registered RDAP server
     *
     * @param string $tld The top-level domain to check
     * @return bool True if the TLD has an RDAP server, false otherwise
     */
    public function hasTld(string $tld): bool
    {
        return $this->getRdapServer($tld) !== null;
    }

    /**
     * Get all available RDAP servers
     *
     * @return array Associative array with TLDs as keys and RDAP servers as values
     */
    public function getAllRdapServers(): array
    {
        return $this->rdapServers;
    }

    /**
     * Refresh RDAP servers data by reloading from the JSON file
     *
     * @return bool True if refresh was successful, false otherwise
     */
    public function refresh(): bool
    {
        return $this->loadRdapServers();
    }

    /**
     * Set HTTP request timeout in seconds
     *
     * @param int $seconds Timeout in seconds
     * @return void
     */
    public function setRequestTimeout(int $seconds): void
    {
        $this->requestTimeout = max(1, $seconds);
    }

    /**
     * Convert domain to IDNA ASCII (punycode) format for RDAP query
     *
     * @param string $domain Domain name that might contain Unicode characters
     * @return string Domain name in ASCII format
     */
    private function convertToIdnaPunycode(string $domain): string
    {
        // Check if conversion is needed
        if (!preg_match('/[^\x20-\x7E]/', $domain)) {
            return $domain; // Already ASCII
        }

        // Split the domain into labels and convert each part
        $parts = explode('.', $domain);
        foreach ($parts as &$part) {
            if (preg_match('/[^\x20-\x7E]/', $part)) {
                $punycode = DnsIdnService::toPunycode($part);
                $part = $punycode !== false ? $punycode : $part;
            }
        }

        return implode('.', $parts);
    }

    /**
     * Perform HTTP request to RDAP server
     *
     * @param string $url The full URL to query
     * @return string|null The response content or null on failure
     */
    private function httpRequest(string $url): ?string
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Poweradmin RDAP Client',
                    'Accept: application/rdap+json'
                ],
                'timeout' => $this->requestTimeout
            ]
        ];

        // Validate URL before fetching to prevent path traversal
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        return $response !== false ? $response : null;
    }

    /**
     * Query an RDAP server for domain information
     *
     * @param string $domain The domain name to query
     * @param string|null $serverUrl Specific RDAP server URL to query (optional)
     * @return array|null The RDAP response as an associative array or null if the query failed
     */
    public function query(string $domain, ?string $serverUrl = null): ?array
    {
        $domain = strtolower(trim($domain));

        // Convert IDN to punycode for the RDAP query
        $domainForQuery = $this->convertToIdnaPunycode($domain);

        // If no server is specified, try to find one
        if ($serverUrl === null) {
            $serverUrl = $this->getRdapServerForDomain($domain);
            if ($serverUrl === null) {
                return null; // No RDAP server found for this domain
            }
        }

        // Ensure the server URL ends with a slash
        if (substr($serverUrl, -1) !== '/') {
            $serverUrl .= '/';
        }

        // Construct the full URL for the domain query
        $url = $serverUrl . 'domain/' . urlencode($domainForQuery);

        // Validate the constructed URL before proceeding
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Perform the HTTP request
        $response = $this->httpRequest($url);
        if ($response === null) {
            return null;
        }

        // Parse the JSON response
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Get RDAP information for a domain
     *
     * @param string $domain The domain name to query
     * @return array An array with keys 'success', 'data', and 'error'
     */
    public function getRdapInfo(string $domain): array
    {
        $result = [
            'success' => false,
            'data' => null,
            'error' => null
        ];

        try {
            $rdapServer = $this->getRdapServerForDomain($domain);

            if ($rdapServer === null) {
                $result['error'] = 'No RDAP server found for this domain';
                return $result;
            }

            $response = $this->query($domain, $rdapServer);

            if ($response === null) {
                $result['error'] = 'Failed to retrieve RDAP information';
                return $result;
            }

            $result['success'] = true;
            $result['data'] = $this->formatRdapResponse($response);
        } catch (\Exception $e) {
            $result['error'] = 'Error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Format RDAP response for better readability
     *
     * @param array $response RDAP response as an associative array
     * @return string Formatted response as JSON with indentation
     */
    public function formatRdapResponse(array $response): string
    {
        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
