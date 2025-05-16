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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class HttpClient implements ApiClient
{

    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $baseEndpoint, string $apiKey)
    {
        $this->apiUrl = rtrim($baseEndpoint, '/');
        $this->apiKey = $apiKey;
    }

    public function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->apiUrl . $endpoint;
        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n" .
                    "X-API-Key: $this->apiKey\r\n",
                'method' => strtoupper($method),
                'ignore_errors' => true,
                'timeout' => 10, // Add a reasonable timeout
            ]
        ];

        if (!empty($data)) {
            $options['http']['content'] = json_encode($data);
        }

        try {
            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $error = error_get_last();
                $displayErrors = $this->shouldDisplayErrors();

                $errorDetails = [
                    'url' => $url,
                    'method' => $method,
                    'error' => $error['message'] ?? 'Unknown error',
                    'code' => $error['type'] ?? 0
                ];

                $errorMessage = $displayErrors
                    ? sprintf('API request failed: %s', $errorDetails['error'])
                    : 'An unknown API error occurred';

                $this->logApiError($errorMessage, $errorDetails);
                throw new ApiErrorException($errorMessage, 0, null, $errorDetails);
            }

            $responseCode = $this->getResponseCode($http_response_header);

            // For 204 No Content responses, don't try to parse JSON
            if ($responseCode === 204) {
                $responseData = []; // Empty array for 204 No Content
            } else {
                $responseData = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE && !empty($response)) {
                    $errorMessage = 'Invalid JSON response from API';
                    $errorDetails = [
                        'url' => $url,
                        'method' => $method,
                        'response' => substr($response, 0, 255), // Limit response size for logging
                        'json_error' => json_last_error_msg()
                    ];

                    $this->logApiError($errorMessage, $errorDetails);
                    throw new ApiErrorException($errorMessage, 0, null, $errorDetails);
                }
            }

            if ($responseCode >= 400) {
                $displayErrors = $this->shouldDisplayErrors();

                $errorDetails = [
                    'url' => $url,
                    'method' => $method,
                    'http_code' => $responseCode,
                    'response' => $responseData
                ];

                $errorMessage = $displayErrors
                    ? sprintf(
                        'HTTP Error %d: %s',
                        $responseCode,
                        isset($responseData['error']) ? $responseData['error'] : 'API error'
                    )
                    : 'An API request failed';

                $this->logApiError($errorMessage, $errorDetails);
                throw new ApiErrorException($errorMessage, $responseCode, null, $errorDetails);
            }

            return [
                'responseCode' => $responseCode,
                'data' => $responseData
            ];
        } catch (ApiErrorException $e) {
            // Re-throw API exceptions
            throw $e;
        } catch (\Throwable $e) {
            // Catch any other exceptions and convert to ApiErrorException
            $errorDetails = [
                'url' => $url,
                'method' => $method,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];

            $errorMessage = $this->shouldDisplayErrors()
                ? sprintf('API request error: %s', $e->getMessage())
                : 'An unexpected error occurred when connecting to the API';

            $this->logApiError($errorMessage, $errorDetails);
            throw new ApiErrorException($errorMessage, 0, $e, $errorDetails);
        }
    }

    /**
     * Log API errors for debugging
     *
     * @param string $message Error message
     * @param array $details Additional error details for logging
     * @return void
     */
    private function logApiError(string $message, array $details = []): void
    {
        $logMessage = sprintf(
            "API Error: %s; Details: %s",
            $message,
            json_encode($details, JSON_UNESCAPED_SLASHES)
        );

        error_log($logMessage);
    }

    private function getResponseCode(array $headers): ?int
    {
        if (isset($headers[0])) {
            preg_match('/\s(\d{3})\s/', $headers[0], $match);
            return isset($match[1]) ? (int)$match[1] : null;
        }

        return null;
    }

    private function shouldDisplayErrors(): bool
    {
        $configManager = ConfigurationManager::getInstance();
        return (bool)$configManager->get('misc', 'display_errors');
    }
}
