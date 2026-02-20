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

namespace Poweradmin\Module\Whois\Service;

use Exception;
use Poweradmin\Domain\Service\DnsIdnService;

class WhoisService
{
    private array $whoisServers = [];
    private string $dataFile;
    private int $socketTimeout = 10;

    /**
     * @param string $dataFile Path to the whois servers PHP data file
     */
    public function __construct(string $dataFile = '')
    {
        $this->dataFile = $dataFile ?: __DIR__ . '/../data/whois_servers.php';
        $this->loadWhoisServers();
    }

    private function loadWhoisServers(): bool
    {
        if (!file_exists($this->dataFile)) {
            return false;
        }

        $servers = include $this->dataFile;

        if (!is_array($servers)) {
            return false;
        }

        $this->whoisServers = $servers;
        return true;
    }

    /**
     * @param string $tld The top-level domain (e.g. "com", "net", "org", "co.uk")
     * @return string|null The whois server hostname or null if not found
     */
    public function getWhoisServer(string $tld): ?string
    {
        $tld = strtolower(trim($tld));

        if (preg_match('/[^\x20-\x7E]/', $tld)) {
            $punycodeTld = DnsIdnService::toPunycode($tld);
            if ($punycodeTld !== false && isset($this->whoisServers[$punycodeTld])) {
                return $this->whoisServers[$punycodeTld];
            }
        } elseif (str_starts_with($tld, 'xn--')) {
            try {
                $unicodeTld = DnsIdnService::toUtf8($tld);
                if (
                    $unicodeTld !== false && $unicodeTld !== $tld &&
                    isset($this->whoisServers[$unicodeTld])
                ) {
                    return $this->whoisServers[$unicodeTld];
                }
            } catch (Exception $e) {
                // Conversion failed, continue with regular lookup
            }
        }

        if (isset($this->whoisServers[$tld])) {
            return $this->whoisServers[$tld];
        }

        return null;
    }

    /**
     * @param string $domain The domain name (e.g. "example.com")
     * @return string|null The whois server hostname or null if not found
     */
    public function getWhoisServerForDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return null;
        }

        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $possibleTld = implode('.', array_slice($parts, -$i));
            $whoisServer = $this->getWhoisServer($possibleTld);

            if ($whoisServer !== null) {
                return $whoisServer;
            }
        }

        return null;
    }

    public function hasTld(string $tld): bool
    {
        return $this->getWhoisServer($tld) !== null;
    }

    public function getAllWhoisServers(): array
    {
        return $this->whoisServers;
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

    public function refresh(): bool
    {
        return $this->loadWhoisServers();
    }

    public function setSocketTimeout(int $seconds): void
    {
        $this->socketTimeout = max(1, $seconds);
    }

    /**
     * @param string $domain The domain name to query
     * @param string|null $server Specific WHOIS server to query (optional)
     * @return string|null The WHOIS response or null if the query failed
     */
    public function query(string $domain, ?string $server = null): ?string
    {
        $domain = strtolower(trim($domain));

        $domainForQuery = $this->convertToIdnaPunycode($domain);

        if ($server === null) {
            $server = $this->getWhoisServerForDomain($domain);
            if ($server === null) {
                return null;
            }
        }

        $port = 43;

        $socket = @fsockopen($server, $port, $errno, $errstr, $this->socketTimeout);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, $this->socketTimeout);

        fwrite($socket, $domainForQuery . "\r\n");

        $response = '';
        while (!feof($socket)) {
            $buffer = fgets($socket, 1024);
            if ($buffer === false) {
                break;
            }
            $response .= $buffer;

            $info = stream_get_meta_data($socket);
            if ($info['timed_out']) {
                break;
            }
        }

        fclose($socket);

        return $response ?: null;
    }

    /**
     * @param string $domain The domain name to query
     * @return array An array with keys 'success', 'data', and 'error'
     */
    public function getWhoisInfo(string $domain): array
    {
        $result = [
            'success' => false,
            'data' => null,
            'error' => null
        ];

        try {
            $whoisServer = $this->getWhoisServerForDomain($domain);

            if ($whoisServer === null) {
                $result['error'] = 'No WHOIS server found for this domain';
                return $result;
            }

            $response = $this->query($domain, $whoisServer);

            if ($response === null) {
                $result['error'] = 'Failed to retrieve WHOIS information';
                return $result;
            }

            $result['success'] = true;
            $result['data'] = $this->formatWhoisResponse($response);
        } catch (Exception $e) {
            $result['error'] = 'Error: ' . $e->getMessage();
        }

        return $result;
    }

    public function formatWhoisResponse(string $response): string
    {
        $response = trim($response);
        $response = str_replace(["\r\n", "\r"], "\n", $response);
        $response = preg_replace("/\n{3,}/", "\n\n", $response);

        return $response;
    }
}
