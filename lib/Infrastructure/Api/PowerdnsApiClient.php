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

use Poweradmin\Domain\Exception\ApiErrorException;

class PowerdnsApiClient {

    protected string $apiUrl;
    protected string $apiKey;
    protected string $serverName;

    public function __construct($baseEndpoint, $apiKey, $serverName) {
        $this->apiUrl = rtrim($baseEndpoint, '/');
        $this->apiKey = $apiKey;
        $this->serverName = $serverName;
    }

    private function errorResponse(string $message): void
    {
        throw new ApiErrorException($message);
    }

    private function getResponseCode($headers): ?int
    {
        if (isset($headers[0])) {
            preg_match('/\s(\d{3})\s/', $headers[0], $match);
            return isset($match[1]) ? (int)$match[1] : null;
        }

        return null;
    }

    protected function makeRequest($method, $endpoint, $data = []): array
    {
        $url = $this->apiUrl . $endpoint;

        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n" .
                    "X-API-Key: {$this->apiKey}\r\n",
                'method' => strtoupper($method)
            ]
        ];

        if (!empty($data)) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->errorResponse(error_get_last()['message']);
        }

        $responseCode = $this->getResponseCode($http_response_header);
        $responseData = json_decode($result, true);

        return [
            'responseCode' => $responseCode,
            'data' => $responseData
        ];
    }

    public function rectifyZone($zone): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/rectify";
        $response = $this->makeRequest('PUT', $endpoint);

        return $response && $response['responseCode'] === 200 && $response['data']['result'] === 'Rectified';
    }

    public function secureZone(string $zone): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $data = ['dnssec' => true];
        $response = $this->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204 && $this->isZoneSecured($zone);
    }

    public function unsecureZone($zone): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $data = ['dnssec' => false];
        $response = $this->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204 && !$this->isZoneSecured($zone);
    }

    public function isZoneSecured(string $zone): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}";
        $response = $this->makeRequest('GET', $endpoint);

        return $response && $response['responseCode'] === 200 && isset($response['data']['dnssec']) && $response['data']['dnssec'];
    }

    public function getKeys(string $zone): array
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys";
        $response = $this->makeRequest('GET', $endpoint);

        return $response && $response['responseCode'] === 200 ? $response['data'] : [];
    }

    public function activateZoneKey(string $zone, int $keyId): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $data = [
            'active' => true
        ];
        $response = $this->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function deactivateZoneKey(string $zone, int $keyId): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $data = [
            'active' => false
        ];
        $response = $this->makeRequest('PUT', $endpoint, $data);

        return $response && $response['responseCode'] === 204;
    }

    public function addZoneKey(string $zone, string $keyType, int $keySize, string $algorithm): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys";
        $data = [
            'keytype' => $keyType,
            'bits' => $keySize,
            'algorithm' => $algorithm,
        ];

        $response = $this->makeRequest('POST', $endpoint, $data);

        return $response && $response['responseCode'] === 201;
    }

    public function removeZoneKey(string $zone, int $keyId): bool
    {
        $endpoint = "/api/v1/servers/{$this->serverName}/zones/{$zone}/cryptokeys/{$keyId}";
        $response = $this->makeRequest('DELETE', $endpoint);

        return $response && $response['responseCode'] === 204;
    }
}

