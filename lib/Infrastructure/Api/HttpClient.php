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

                // Detect common misconfigurations and provide helpful error messages
                $errorMessage = $this->getHelpfulErrorMessage($errorDetails['error'], $url, $displayErrors);

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
     * Generate helpful error messages for common misconfigurations
     *
     * @param string $originalError The original error message
     * @param string $url The URL that failed
     * @param bool $displayErrors Whether to display detailed errors
     * @return string Helpful error message
     */
    private function getHelpfulErrorMessage(string $originalError, string $url, bool $displayErrors): string
    {
        // Check for "No such file or directory" - indicates missing protocol prefix
        if (strpos($originalError, 'No such file or directory') !== false) {
            if ($displayErrors) {
                return sprintf(
                    'PowerDNS API connection failed: URL "%s" is being treated as a file path. ' .
                    'Make sure the API URL starts with http:// or https:// (e.g., http://127.0.0.1:8081). ' .
                    'Also verify the port number is correct (PowerDNS API typically runs on port 8081).',
                    $url
                );
            }
            return 'PowerDNS API configuration error: Invalid API URL format. Check that URL starts with http:// or https://';
        }

        // Check for connection refused
        if (strpos($originalError, 'Connection refused') !== false || strpos($originalError, 'Failed to connect') !== false) {
            if ($displayErrors) {
                return sprintf(
                    'PowerDNS API connection refused at "%s". ' .
                    'Please verify: (1) PowerDNS API is running, (2) API port is correct (typically 8081), ' .
                    '(3) Firewall allows the connection.',
                    $url
                );
            }
            return 'Cannot connect to PowerDNS API. Verify the API is running and accessible.';
        }

        // Check for timeout
        if (strpos($originalError, 'timed out') !== false || strpos($originalError, 'timeout') !== false) {
            if ($displayErrors) {
                return sprintf(
                    'PowerDNS API request timed out at "%s". ' .
                    'The API server may be slow to respond or unreachable.',
                    $url
                );
            }
            return 'PowerDNS API request timed out. Check API server availability.';
        }

        // Check for name resolution failures
        if (strpos($originalError, 'resolve') !== false || strpos($originalError, 'Name or service not known') !== false) {
            if ($displayErrors) {
                return sprintf(
                    'Cannot resolve hostname in PowerDNS API URL "%s". ' .
                    'Check that the hostname is correct and DNS is working.',
                    $url
                );
            }
            return 'Cannot resolve PowerDNS API hostname. Check the URL configuration.';
        }

        // Default error message
        return $displayErrors
            ? sprintf('API request failed: %s', $originalError)
            : 'An unknown API error occurred';
    }
}
