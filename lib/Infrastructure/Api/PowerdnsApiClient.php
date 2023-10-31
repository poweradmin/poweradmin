<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

use Exception;

class PowerdnsApiClient {

    protected string $apiUrl;
    protected string $apiKey;
    protected string $serverName;

    public function __construct($baseEndpoint, $apiKey, $serverName) {
        $this->apiUrl = rtrim($baseEndpoint, '/');
        $this->apiKey = $apiKey;
        $this->serverName = $serverName;
    }

    protected function makeRequest($method, $endpoint, $data = []): mixed
    {
        $url = $this->apiUrl . $endpoint;

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n" .
                    "X-API-Key: {$this->apiKey}\r\n",
                'method'  => strtoupper($method)
            ]
        ];

        if (!empty($data)) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            throw new Exception("Error occurred while making the request.");
        }

        return json_decode($result, true);
    }

    public function rectifyZone($zone) {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/rectify";
        return $this->makeRequest('PUT', $endpoint);
    }

    public function secureZone(string $zone)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $data = ['dnssec' => true];
        $this->makeRequest('PUT', $endpoint, $data);

        return $this->isZoneSecured($zone);
    }

    public function unsecureZone($zone) {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $data = ['dnssec' => false];
        $this->makeRequest('PUT', $endpoint, $data);

        return !$this->isZoneSecured($zone);
    }

    public function isZoneSecured(string $zone)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $zoneDetails = $this->makeRequest('GET', $endpoint);
        return isset($zoneDetails['dnssec']) && $zoneDetails['dnssec'];
    }

    public function getKeys(string $zone)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys";
        return $this->makeRequest('GET', $endpoint);
    }

    public function activateZoneKey(string $zone, int $keyId)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $data = [
            'active' => true
        ];
        $this->makeRequest('PUT', $endpoint, $data);

        return true;
    }

    public function deactivateZoneKey(string $zone, int $keyId)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $data = [
            'active' => false
        ];
        $this->makeRequest('PUT', $endpoint, $data);

        return true;
    }

    public function addZoneKey(string $zone, string $keyType, int $keySize, string $algorithm)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys";
        $data = [
            'keytype' => $keyType,
            'bits' => $keySize,
            'algorithm' => $algorithm,
        ];

        $response = $this->makeRequest('POST', $endpoint, $data);

        return $response && $response['published'] === true;
    }

    public function removeZoneKey(string $zone, int $keyId)
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $this->makeRequest('DELETE', $endpoint);

        return true;
    }
}

