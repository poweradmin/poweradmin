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
 */

namespace Poweradmin\Module\Rdap\Service;

use Exception;
use Poweradmin\Domain\Service\DnsIdnService;

class RdapService
{
    private array $rdapServers = [];
    private string $dataFile;
    private int $requestTimeout = 10;

    /**
     * @param string $dataFile Path to the RDAP servers PHP data file
     */
    public function __construct(string $dataFile = '')
    {
        $this->dataFile = $dataFile ?: __DIR__ . '/../data/rdap_servers.php';
        $this->loadRdapServers();
    }

    private function loadRdapServers(): bool
    {
        if (!file_exists($this->dataFile)) {
            return false;
        }

        $servers = include $this->dataFile;

        if (!is_array($servers)) {
            return false;
        }

        $this->rdapServers = $servers;
        return true;
    }

    public function getRdapServer(string $tld): ?string
    {
        $tld = strtolower(trim($tld));

        if (preg_match('/[^\x20-\x7E]/', $tld)) {
            $punycodeTld = DnsIdnService::toPunycode($tld);
            if ($punycodeTld !== false && isset($this->rdapServers[$punycodeTld])) {
                return $this->rdapServers[$punycodeTld];
            }
        } elseif (str_starts_with($tld, 'xn--')) {
            try {
                $unicodeTld = DnsIdnService::toUtf8($tld);
                if (
                    $unicodeTld !== false && $unicodeTld !== $tld &&
                    isset($this->rdapServers[$unicodeTld])
                ) {
                    return $this->rdapServers[$unicodeTld];
                }
            } catch (Exception $e) {
                // Conversion failed, continue with regular lookup
            }
        }

        if (isset($this->rdapServers[$tld])) {
            return $this->rdapServers[$tld];
        }

        return null;
    }

    public function getRdapServerForDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return null;
        }

        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $possibleTld = implode('.', array_slice($parts, -$i));
            $rdapServer = $this->getRdapServer($possibleTld);

            if ($rdapServer !== null) {
                return $rdapServer;
            }
        }

        return null;
    }

    public function hasTld(string $tld): bool
    {
        return $this->getRdapServer($tld) !== null;
    }

    public function getAllRdapServers(): array
    {
        return $this->rdapServers;
    }

    public function refresh(): bool
    {
        return $this->loadRdapServers();
    }

    public function setRequestTimeout(int $seconds): void
    {
        $this->requestTimeout = max(1, $seconds);
    }

    private function convertToIdnaPunycode(string $domain): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $domain)) {
            return $domain;
        }

        $parts = explode('.', $domain);
        foreach ($parts as &$part) {
            if (preg_match('/[^\x20-\x7E]/', $part)) {
                $punycode = DnsIdnService::toPunycode($part);
                $part = $punycode !== false ? $punycode : $part;
            }
        }

        return implode('.', $parts);
    }

    private function httpRequest(string $url): ?string
    {
        if (!$this->isValidRdapUrl($url)) {
            return null;
        }

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Poweradmin RDAP Client',
                    'Accept: application/rdap+json'
                ],
                'timeout' => $this->requestTimeout,
                'follow_location' => 0
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        return $response !== false ? $response : null;
    }

    private function isValidRdapUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsedUrl = parse_url($url);
        if (
            !isset($parsedUrl['scheme'], $parsedUrl['host']) ||
            !in_array($parsedUrl['scheme'], ['http', 'https'], true)
        ) {
            return false;
        }

        if (strpos($url, '..') !== false || strpos($url, '\\') !== false) {
            return false;
        }

        return true;
    }

    public function query(string $domain, ?string $serverUrl = null): ?array
    {
        $domain = strtolower(trim($domain));

        $domainForQuery = $this->convertToIdnaPunycode($domain);

        if ($serverUrl === null) {
            $serverUrl = $this->getRdapServerForDomain($domain);
            if ($serverUrl === null) {
                return null;
            }
        }

        if (substr($serverUrl, -1) !== '/') {
            $serverUrl .= '/';
        }

        $url = $serverUrl . 'domain/' . urlencode($domainForQuery);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $response = $this->httpRequest($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

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
        } catch (Exception $e) {
            $result['error'] = 'Error: ' . $e->getMessage();
        }

        return $result;
    }

    public function formatRdapResponse(array $response): string
    {
        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
