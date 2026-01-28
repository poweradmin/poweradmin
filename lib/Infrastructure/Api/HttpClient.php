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
use Throwable;

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
                $jsonError = json_last_error();

                // Handle HTTP error responses (4xx, 5xx) before strict JSON validation
                // PowerDNS may return plain text errors (e.g., "Not Found" for 404)
                if ($responseCode >= 400) {
                    $displayErrors = $this->shouldDisplayErrors();

                    // For error responses, use parsed JSON if available, otherwise use raw response
                    $errorResponse = ($jsonError === JSON_ERROR_NONE && $responseData !== null)
                        ? $responseData
                        : ['raw_response' => substr($response, 0, 255)];

                    $errorDetails = [
                        'url' => $url,
                        'method' => $method,
                        'http_code' => $responseCode,
                        'response' => $errorResponse
                    ];

                    // Provide user-friendly messages for common HTTP errors
                    $errorMessage = $this->getHttpErrorMessage($responseCode, $errorResponse, $displayErrors);

                    // Don't log 404 errors as they are often expected (e.g., checking if zone exists)
                    if ($responseCode !== 404) {
                        $this->logApiError($errorMessage, $errorDetails);
                    }
                    throw new ApiErrorException($errorMessage, $responseCode, null, $errorDetails);
                }

                // For success responses, require valid JSON
                if ($jsonError !== JSON_ERROR_NONE && !empty($response)) {
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

            return [
                'responseCode' => $responseCode,
                'data' => $responseData
            ];
        } catch (ApiErrorException $e) {
            // Re-throw API exceptions
            throw $e;
        } catch (Throwable $e) {
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

    /**
     * Generate user-friendly error messages for HTTP error responses
     *
     * @param int $responseCode HTTP response code
     * @param array $errorResponse Parsed error response or raw response
     * @param bool $displayErrors Whether to display detailed errors
     * @return string User-friendly error message
     */
    private function getHttpErrorMessage(int $responseCode, array $errorResponse, bool $displayErrors): string
    {
        // Try to get error message from JSON response
        $apiError = $errorResponse['error'] ?? null;

        // For raw text responses (e.g., "Not Found")
        $rawResponse = $errorResponse['raw_response'] ?? null;

        // Common HTTP error codes with user-friendly messages
        $httpErrors = [
            400 => 'Bad Request',
            401 => 'Unauthorized - check API key configuration',
            403 => 'Forbidden - API key may lack required permissions',
            404 => 'Resource not found',
            405 => 'Method not allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        $statusText = $httpErrors[$responseCode] ?? 'Unknown error';

        if ($displayErrors) {
            if ($apiError) {
                return sprintf('HTTP Error %d: %s', $responseCode, $apiError);
            } elseif ($rawResponse) {
                return sprintf('HTTP Error %d: %s', $responseCode, $rawResponse);
            }
            return sprintf('HTTP Error %d: %s', $responseCode, $statusText);
        }

        return 'An API request failed';
    }
}
