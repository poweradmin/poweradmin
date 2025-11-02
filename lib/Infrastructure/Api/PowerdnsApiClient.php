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

namespace Poweradmin\Infrastructure\Api;

use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;

// TODO
// - have smaller client classes for specific functionality
// - refactor response handling into separate method
// - add tests (unit, integration, functional)

class PowerdnsApiClient
{

    private const API_VERSION_PATH = '/api/v1';

    private HttpClient $httpClient;
    private string $serverName;

    public function __construct(HttpClient $httpClient, string $serverName)
    {
        $this->httpClient = $httpClient;
        $this->serverName = $serverName;
    }

    private function buildEndpoint(string $path): string
    {
        return self::API_VERSION_PATH . "/servers/$this->serverName" . $path;
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/rectify");
            $response = $this->httpClient->makeRequest('PUT', $endpoint);

            return $response &&
                   $response['responseCode'] === 200 &&
                   isset($response['data']['result']) &&
                   $response['data']['result'] === 'Rectified';
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to rectify zone %s: %s", $zone->getName(), $e->getMessage()));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
            $data = ['dnssec' => true];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to secure zone %s: %s", $zone->getName(), $e->getMessage()));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
            $data = ['dnssec' => false];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to unsecure zone %s: %s", $zone->getName(), $e->getMessage()));
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
                    $zones[] = new Zone($zoneData['name'], $zoneData['id'], $zoneData['dnssec']);
                }
            }

            return $zones;
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to get zones: %s", $e->getMessage()));

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
            error_log(sprintf("Failed to create zone %s: %s", $zone->getName(), $e->getMessage()));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
            $data = [
                'name' => $zone->getName(),
            ];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to update zone %s: %s", $zone->getName(), $e->getMessage()));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf("Failed to delete zone %s: %s", $zone->getName(), $e->getMessage()));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys");
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
            error_log(sprintf(
                "Failed to get DNSSEC keys for zone %s: %s",
                $zone->getName(),
                $e->getMessage()
            ));

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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys");
            $data = [
                'keytype' => $key->getType(),
                'bits' => $key->getSize(),
                'algorithm' => $key->getAlgorithm(),
            ];
            $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

            return $response && $response['responseCode'] === 201;
        } catch (ApiErrorException $e) {
            error_log(sprintf(
                "Failed to add key to zone %s: %s",
                $zone->getName(),
                $e->getMessage()
            ));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
            $data = ['active' => true];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf(
                "Failed to activate key %d for zone %s: %s",
                $key->getId(),
                $zone->getName(),
                $e->getMessage()
            ));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
            $data = ['active' => false];
            $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf(
                "Failed to deactivate key %d for zone %s: %s",
                $key->getId(),
                $zone->getName(),
                $e->getMessage()
            ));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
            $response = $this->httpClient->makeRequest('DELETE', $endpoint);

            return $response && $response['responseCode'] === 204;
        } catch (ApiErrorException $e) {
            error_log(sprintf(
                "Failed to remove key %d from zone %s: %s",
                $key->getId(),
                $zone->getName(),
                $e->getMessage()
            ));
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
            $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
            $response = $this->httpClient->makeRequest('GET', $endpoint);

            return $response &&
                   $response['responseCode'] === 200 &&
                   isset($response['data']['dnssec']) &&
                   $response['data']['dnssec'];
        } catch (ApiErrorException $e) {
            // Log the error but don't break the UI flow
            error_log(sprintf(
                "DNSSEC check failed for zone %s: %s",
                $zone->getName(),
                $e->getMessage()
            ));

            // Return false as a fallback - this assumes zone is not secured when we can't tell
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
            error_log(sprintf("Failed to get PowerDNS config: %s", $e->getMessage()));

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
            error_log(sprintf("Failed to get server info: %s", $e->getMessage()));

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
            error_log(sprintf("Failed to get metrics: %s", $e->getMessage()));

            // Return empty array as fallback
            return [];
        }
    }
}
