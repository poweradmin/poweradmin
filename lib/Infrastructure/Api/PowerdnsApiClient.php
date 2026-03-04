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

namespace Poweradmin\Infrastructure\Api;

use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// TODO
// - have smaller client classes for specific functionality
// - refactor response handling into separate method
// - add tests (unit, integration, functional)

class PowerdnsApiClient
{

    private const API_VERSION_PATH = '/api/v1';

    private HttpClient $httpClient;
    private string $serverName;
    private LoggerInterface $logger;

    public function __construct(HttpClient $httpClient, string $serverName, ?LoggerInterface $logger = null)
    {
        $this->httpClient = $httpClient;
        $this->serverName = $serverName;
        $this->logger = $logger ?? new NullLogger();
    }

    private function buildEndpoint(string $path): string
    {
        return self::API_VERSION_PATH . "/servers/$this->serverName" . $path;
    }

    private function buildZoneEndpoint(string $zoneName, string $suffix = ''): string
    {
        return $this->buildEndpoint("/zones/" . rawurlencode($zoneName) . $suffix);
    }

    /**
     * Rectify a zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function rectifyZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/rectify");
            $response = $this->httpClient->makeRequest('PUT', $endpoint);

            return $response &&
                   $response['responseCode'] === 200 &&
                   isset($response['data']['result']) &&
                   $response['data']['result'] === 'Rectified';
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to rectify zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Enable DNSSEC for a zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function secureZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName());
            $data = ['dnssec' => true];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to secure zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Disable DNSSEC for a zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function unsecureZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName());
            $data = ['dnssec' => false];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to unsecure zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all zones from the PowerDNS server
     *
     * @return array
     */
    public function getAllZones(): array
    {
        try {
            $endpoint = $this->buildEndpoint("/zones");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            $zones = [];
            if ($response && $response['responseCode'] === 200) {
                foreach ($response['data'] as $zoneData) {
                    $zones[] = new Zone($zoneData['name'], $zoneData['dnssec'] ?? false);
                }
            }

            return $zones;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get zones: {error}', ['error' => $e->getMessage()]);

            // Return empty array instead of breaking the UI
            return [];
        }
    }

    /**
     * Create a new zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function createZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildEndpoint("/zones");
            $data = [
                'name' => $zone->getName(),
            ];
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            return $response && $response['responseCode'] === 201;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to create zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update a zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function updateZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName());
            $data = [
                'name' => $zone->getName(),
            ];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to update zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete a zone
     *
     * @param Zone $zone
     * @return bool
     */
    public function deleteZone(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName());
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to delete zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get DNSSEC keys for a zone
     *
     * @param Zone $zone
     * @return array
     */
    public function getZoneKeys(Zone $zone): array
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/cryptokeys");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                $keys = [];
                foreach ($response['data'] as $keyData) {
                    // Normalize optional fields from PowerDNS API
                    // ZSK keys don't have DS records, only KSK/CSK do
                    $dsRecords = [];
                    if (array_key_exists('ds', $keyData) && is_array($keyData['ds'])) {
                        $dsRecords = $keyData['ds'];
                    }

                    $keys[] = new CryptoKey(
                        $keyData['id'],
                        $keyData['keytype'],
                        $keyData['bits'],
                        $keyData['algorithm'],
                        $keyData['active'],
                        $keyData['dnskey'],
                        $dsRecords,
                    );
                }
                return $keys;
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get DNSSEC keys for zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);

            // Return empty array instead of breaking the UI
            return [];
        }
    }

    /**
     * Add a DNSSEC key to a zone
     *
     * @param Zone $zone
     * @param CryptoKey $key
     * @return bool
     */
    public function addZoneKey(Zone $zone, CryptoKey $key): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/cryptokeys");
            $data = [
                'keytype' => $key->getType(),
                'bits' => $key->getSize(),
                'algorithm' => $key->getAlgorithm(),
            ];
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            return $response && $response['responseCode'] === 201;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to add key to zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Activate a DNSSEC key
     *
     * @param Zone $zone
     * @param CryptoKey $key
     * @return bool
     */
    public function activateZoneKey(Zone $zone, CryptoKey $key): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/cryptokeys/{$key->getId()}");
            $data = ['active' => true];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to activate key {keyId} for zone {zone}: {error}', ['keyId' => $key->getId(), 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Deactivate a DNSSEC key
     *
     * @param Zone $zone
     * @param CryptoKey $key
     * @return bool
     */
    public function deactivateZoneKey(Zone $zone, CryptoKey $key): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/cryptokeys/{$key->getId()}");
            $data = ['active' => false];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to deactivate key {keyId} for zone {zone}: {error}', ['keyId' => $key->getId(), 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remove a DNSSEC key
     *
     * @param Zone $zone
     * @param CryptoKey $key
     * @return bool
     */
    public function removeZoneKey(Zone $zone, CryptoKey $key): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/cryptokeys/{$key->getId()}");
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to remove key {keyId} from zone {zone}: {error}', ['keyId' => $key->getId(), 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if a zone is secured with DNSSEC
     *
     * @param Zone $zone
     * @return bool
     */
    public function isZoneSecured(Zone $zone): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName());
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            return $response &&
                   $response['responseCode'] === 200 &&
                   isset($response['data']['dnssec']) &&
                   $response['data']['dnssec'];
        } catch (ApiErrorException $e) {
            // Log the error but don't break the UI flow
            $this->logger->error('DNSSEC check failed for zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);

            // Return false as a fallback - this assumes zone is not secured when we can't tell
            return false;
        }
    }

    /**
     * Get all metadata for a zone
     *
     * @param Zone $zone
     * @return array Array of metadata entries [['kind' => string, 'metadata' => string[]], ...]
     */
    public function getZoneMetadata(Zone $zone): array
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/metadata");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'];
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get metadata for zone {zone}: {error}', ['zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a specific metadata kind for a zone
     *
     * @param Zone $zone
     * @param string $kind Metadata kind (e.g., 'ALLOW-AXFR-FROM', 'TSIG-ALLOW-AXFR')
     * @return array Metadata entry ['kind' => string, 'metadata' => string[]]
     */
    public function getZoneMetadataKind(Zone $zone, string $kind): array
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/metadata/" . rawurlencode($kind));
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'];
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get metadata kind {kind} for zone {zone}: {error}', ['kind' => $kind, 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create metadata for a zone
     *
     * @param Zone $zone
     * @param string $kind Metadata kind
     * @param array $metadata Array of metadata values
     * @return bool
     */
    public function createZoneMetadata(Zone $zone, string $kind, array $metadata): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/metadata");
            $data = [
                'kind' => $kind,
                'metadata' => $metadata,
            ];
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to create metadata kind {kind} for zone {zone}: {error}', ['kind' => $kind, 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update metadata for a zone, replacing existing entries of the given kind
     *
     * @param Zone $zone
     * @param string $kind Metadata kind
     * @param array $metadata Array of metadata values
     * @return bool
     */
    public function updateZoneMetadata(Zone $zone, string $kind, array $metadata): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/metadata/" . rawurlencode($kind));
            $data = [
                'kind' => $kind,
                'metadata' => $metadata,
            ];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 200;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to update metadata kind {kind} for zone {zone}: {error}', ['kind' => $kind, 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete all metadata of a given kind for a zone
     *
     * @param Zone $zone
     * @param string $kind Metadata kind
     * @return bool
     */
    public function deleteZoneMetadata(Zone $zone, string $kind): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zone->getName(), "/metadata/" . rawurlencode($kind));
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to delete metadata kind {kind} for zone {zone}: {error}', ['kind' => $kind, 'zone' => $zone->getName(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get PowerDNS configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        try {
            $endpoint = $this->buildEndpoint("/config");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            return $response['data'] ?? [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get PowerDNS config: {error}', ['error' => $e->getMessage()]);

            // Return empty array as fallback
            return [];
        }
    }

    /**
     * Get PowerDNS server information
     *
     * @return array
     */
    public function getServerInfo(): array
    {
        try {
            $endpoint = $this->buildEndpoint("");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            return $response['data'] ?? [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get server info: {error}', ['error' => $e->getMessage()]);

            // Return empty array as fallback
            return [];
        }
    }

    /**
     * Get PowerDNS server metrics
     *
     * @return array
     */
    public function getMetrics(): array
    {
        try {
            $endpoint = $this->buildEndpoint("/statistics");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            return $response['data'] ?? [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get metrics: {error}', ['error' => $e->getMessage()]);

            // Return empty array as fallback
            return [];
        }
    }

    // ---------------------------------------------------------------
    // Zone operations (extended for DNS backend integration)
    // ---------------------------------------------------------------

    /**
     * Get a single zone with its RRsets
     *
     * @param string $zoneName Zone name (with trailing dot)
     * @return array|null Zone data or null if not found
     */
    public function getZone(string $zoneName): ?array
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zoneName);
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'];
            }

            return null;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get zone {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create a zone with full data and return the response
     *
     * @param array $zoneData Zone creation payload
     * @return array|null Zone data from response or null on failure
     */
    public function createZoneWithData(array $zoneData): ?array
    {
        try {
            $endpoint = $this->buildEndpoint("/zones");
            $response = $this->httpClient->makeRequest('POST', $endpoint, $zoneData);

            if ($response && $response['responseCode'] === 201) {
                return $response['data'] ?? [];
            }

            return null;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to create zone: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Update zone properties (kind, masters, account, etc.)
     *
     * @param string $zoneName Zone name (with trailing dot)
     * @param array $data Zone properties to update
     * @return bool
     */
    public function updateZoneProperties(string $zoneName, array $data): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zoneName);
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to update zone {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ---------------------------------------------------------------
    // RRset operations
    // ---------------------------------------------------------------

    /**
     * Patch zone RRsets (add, modify, or delete records)
     *
     * @param string $zoneName Zone name (with trailing dot)
     * @param array $rrsets Array of RRset change objects
     * @return bool
     */
    public function patchZoneRRsets(string $zoneName, array $rrsets): bool
    {
        try {
            $endpoint = $this->buildZoneEndpoint($zoneName);
            $data = ['rrsets' => $rrsets];
            $this->logger->debug('patchZoneRRsets: endpoint={endpoint}, data={data}', ['endpoint' => $endpoint, 'data' => json_encode($data)]);
            $response = $this->httpClient->makeRequest('PATCH', $endpoint, $data);
            $this->logger->debug('patchZoneRRsets: response={response}', ['response' => json_encode($response)]);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to patch RRsets for zone {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------

    /**
     * Search across zones, records, and comments
     *
     * @param string $query Search query (supports * and ? wildcards)
     * @param string $objectType Filter: 'all', 'zone', 'record', or 'comment'
     * @param int $max Maximum number of results
     * @return array Search results
     */
    public function searchData(string $query, string $objectType = 'all', int $max = 100): array
    {
        try {
            $params = http_build_query([
                'q' => $query,
                'max' => $max,
                'object_type' => $objectType,
            ]);
            $endpoint = $this->buildEndpoint("/search-data?{$params}");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'] ?? [];
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to search: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ---------------------------------------------------------------
    // Autoprimary (supermaster) operations
    // ---------------------------------------------------------------

    /**
     * Get all autoprimaries
     *
     * @return array
     */
    public function getAutoprimaries(): array
    {
        try {
            $endpoint = $this->buildEndpoint("/autoprimaries");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'] ?? [];
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get autoprimaries: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Add an autoprimary
     *
     * @param string $ip IP address
     * @param string $nameserver Nameserver hostname
     * @param string $account Account name
     * @return bool
     */
    public function addAutoprimary(string $ip, string $nameserver, string $account = ''): bool
    {
        try {
            $endpoint = $this->buildEndpoint("/autoprimaries");
            $data = [
                'ip' => $ip,
                'nameserver' => $nameserver,
                'account' => $account,
            ];
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            return $response && ($response['responseCode'] === 201 || $response['responseCode'] === 204);
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to add autoprimary: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete an autoprimary
     *
     * @param string $ip IP address
     * @param string $nameserver Nameserver hostname
     * @return bool
     */
    public function deleteAutoprimary(string $ip, string $nameserver): bool
    {
        try {
            $endpoint = $this->buildEndpoint("/autoprimaries/" . rawurlencode($ip) . "/" . rawurlencode($nameserver));
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to delete autoprimary: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ---------------------------------------------------------------
    // TSIG key operations
    // ---------------------------------------------------------------

    /**
     * Get all TSIG keys
     *
     * @return array
     */
    public function getTsigKeys(): array
    {
        try {
            $endpoint = $this->buildEndpoint("/tsigkeys");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            if ($response && $response['responseCode'] === 200) {
                return $response['data'] ?? [];
            }

            return [];
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get TSIG keys: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create a TSIG key
     *
     * @param string $name Key name
     * @param string $algorithm Key algorithm (e.g., hmac-md5, hmac-sha256)
     * @param string $key Key material (empty to let server generate)
     * @return array|null Created key data or null on failure
     */
    public function createTsigKey(string $name, string $algorithm, string $key = ''): ?array
    {
        try {
            $endpoint = $this->buildEndpoint("/tsigkeys");
            $data = [
                'name' => $name,
                'algorithm' => $algorithm,
            ];
            if ($key !== '') {
                $data['key'] = $key;
            }
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            if ($response && $response['responseCode'] === 201) {
                return $response['data'] ?? [];
            }

            return null;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to create TSIG key: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Delete a TSIG key
     *
     * @param string $keyId TSIG key ID
     * @return bool
     */
    public function deleteTsigKey(string $keyId): bool
    {
        try {
            $endpoint = $this->buildEndpoint("/tsigkeys/" . rawurlencode($keyId));
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to delete TSIG key: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update a TSIG key
     *
     * @param string $keyId TSIG key ID
     * @param array $data Key properties to update
     * @return bool
     */
    public function updateTsigKey(string $keyId, array $data): bool
    {
        try {
            $endpoint = $this->buildEndpoint("/tsigkeys/" . rawurlencode($keyId));
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 200;
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to update TSIG key: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
