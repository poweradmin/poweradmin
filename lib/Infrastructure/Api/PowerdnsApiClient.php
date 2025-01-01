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

use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;

// TODO
// - have smaller client classes for specific functionality
// - refactor response handling into separate method
// - add tests (unit, integration, functional)

class PowerdnsApiClient {

    private const API_VERSION_PATH = '/api/v1';

    private HttpClient $httpClient;
    private string $serverName;

    public function __construct(HttpClient $httpClient, string $serverName) {
        $this->httpClient = $httpClient;
        $this->serverName = $serverName;
    }

    private function buildEndpoint(string $path): string {
        return self::API_VERSION_PATH . "/servers/$this->serverName" . $path;
    }

    public function rectifyZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/rectify");
        $response = $this->httpClient->makeRequest('PUT', $endpoint);

        return $response && $response['responseCode'] === 200 && $response['data']['result'] === 'Rectified';
    }

    public function secureZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
        $data = ['dnssec' => true];
        $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function unsecureZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
        $data = ['dnssec' => false];
        $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function getAllZones(): array {
        $endpoint = $this->buildEndpoint("/zones");
        $response = $this->httpClient->makeRequest('GET', $endpoint);

        $zones = [];
        if ($response && $response['responseCode'] === 200) {
            foreach ($response['data'] as $zoneData) {
                $zones[] = new Zone($zoneData['name'], $zoneData['id'], $zoneData['dnssec']);
            }
        }

        return $zones;
    }

    public function createZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones");
        $data = [
            'name' => $zone->getName(),
        ];
        $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

        return $response && $response['responseCode'] === 201;
    }

    public function updateZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
        $data = [
            'name' => $zone->getName(),
        ];
        $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function deleteZone(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
        $response = $this->httpClient->makeRequest('DELETE', $endpoint);

        return $response && $response['responseCode'] === 204;
    }

    public function getZoneKeys(Zone $zone): array
    {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys");
        $response = $this->httpClient->makeRequest('GET', $endpoint);

        if ($response && $response['responseCode'] === 200) {
            $keys = [];
            foreach ($response['data'] as $keyData) {
                $keys[] = new CryptoKey(
                    $keyData['id'],
                    $keyData['keytype'],
                    $keyData['bits'],
                    $keyData['algorithm'],
                    $keyData['active'],
                    $keyData['dnskey'],
                    $keyData['ds'],
                );
            }
            return $keys;
        }

        return [];
    }

    public function addZoneKey(Zone $zone, CryptoKey $key): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys");
        $data = [
            'keytype' => $key->getType(),
            'bits' => $key->getSize(),
            'algorithm' => $key->getAlgorithm(),
        ];
        $response = $this->httpClient->makeRequest('POST', $endpoint, $data);

        return $response && $response['responseCode'] === 201;
    }

    public function activateZoneKey(Zone $zone, CryptoKey $key): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
        $data = ['active' => true];
        $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function deactivateZoneKey(Zone $zone, CryptoKey $key): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
        $data = ['active' => false];
        $response = $this->httpClient->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function removeZoneKey(Zone $zone, CryptoKey $key): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}/cryptokeys/{$key->getId()}");
        $response = $this->httpClient->makeRequest('DELETE', $endpoint);

        return $response && $response['responseCode'] === 204;
    }

    public function isZoneSecured(Zone $zone): bool {
        $endpoint = $this->buildEndpoint("/zones/{$zone->getName()}");
        $response = $this->httpClient->makeRequest('GET', $endpoint);

        return $response && $response['responseCode'] === 200 && isset($response['data']['dnssec']) && $response['data']['dnssec'];
    }

    public function getConfig(): array {
        $endpoint = $this->buildEndpoint("/config");
        $response = $this->httpClient->makeRequest('GET', $endpoint);

        return $response['data'];
    }
}
