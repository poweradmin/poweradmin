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

class HttpClient implements ApiClient {

    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $baseEndpoint, string $apiKey) {
        $this->apiUrl = rtrim($baseEndpoint, '/');
        $this->apiKey = $apiKey;
    }

    public function makeRequest(string $method, string $endpoint, array $data = []): array {
        $url = $this->apiUrl . $endpoint;
        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n" .
                    "X-API-Key: $this->apiKey\r\n",
                'method' => strtoupper($method),
                'ignore_errors' => true,
            ]
        ];

        if (!empty($data)) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        $responseCode = $this->getResponseCode($http_response_header);
        $responseData = json_decode($response, true);

        if ($responseCode >= 400) {
            throw new ApiErrorException($responseData['error'] ?? 'An unknown API error occurred');
        }

        return [
            'responseCode' => $responseCode,
            'data' => $responseData
        ];
    }

    private function getResponseCode(array $headers): ?int {
        if (isset($headers[0])) {
            preg_match('/\s(\d{3})\s/', $headers[0], $match);
            return isset($match[1]) ? (int)$match[1] : null;
        }

        return null;
    }
}
