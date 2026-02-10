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

/**
 * Public API controller for external endpoints
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Infrastructure\Service\ApiKeyAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\MessageService;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class PublicApiController extends AbstractApiController
{
    protected array $pathParameters;
    protected int $authenticatedUserId = 0;

    /**
     * PublicApiController constructor
     *
     * @param array $requestParams The request parameters
     * @param array $pathParameters Optional path parameters for RESTful routes
     */
    public function __construct(array $requestParams, array $pathParameters = [])
    {
        // Call parent constructor with authentication disabled
        // We will handle authentication ourselves in this controller
        parent::__construct($requestParams, false);

        // Store path parameters for use by child classes
        $this->pathParameters = $pathParameters;

        // Authenticate the API request using API key or HTTP Basic auth
        $this->authenticateApiRequest();
    }

    /**
     * Authenticate the API request using API key or HTTP Basic auth
     *
     * @return void
     */
    protected function authenticateApiRequest(): void
    {
        // Skip authentication if it's not required for this endpoint
        if (!$this->requiresAuthentication()) {
            return;
        }

        // Create database connection with proper credentials
        $config = $this->getConfig();
        $credentials = DatabaseCredentialMapper::mapCredentials($config);

        // Create the database connection
        $databaseConnection = new PDODatabaseConnection();
        $databaseService = new DatabaseService($databaseConnection);
        $db = $databaseService->connect($credentials);

        // Try authentication methods in order:
        // 1. API Key auth
        // 2. HTTP Basic auth
        $authenticated = false;

        // Always try API key authentication when API is enabled
        $apiKeyMiddleware = new ApiKeyAuthenticationMiddleware($db, $config);
        $authenticated = $apiKeyMiddleware->process($this->request);

        // Get authenticated user ID in a stateless way
        if ($authenticated) {
            $this->authenticatedUserId = $apiKeyMiddleware->getAuthenticatedUserId($this->request);
        }

        // Try Basic auth if API key auth failed and it's enabled
        if (!$authenticated && $config->get('api', 'basic_auth_enabled', true)) {
            $basicAuthMiddleware = new BasicAuthenticationMiddleware($db, $config);
            $this->authenticatedUserId = $basicAuthMiddleware->getAuthenticatedUserId($this->request);
            $authenticated = ($this->authenticatedUserId > 0);
        }

        // If all authentication methods failed, return 401 Unauthorized
        if (!$authenticated) {
            // Use V2 response format for V2 controllers, V1 format for V1 controllers
            if ($this->isV2Controller()) {
                $response = $this->returnApiError('Unauthorized: Invalid credentials', 401);
            } else {
                $response = $this->returnErrorResponse('Unauthorized: Invalid credentials', 401);
            }
            $response->send();
            exit;
        }
    }

    /**
     * Get API key from request headers
     *
     * @return string|null The API key or null if not found
     */
    protected function getApiKeyFromRequest(): ?string
    {
        // Try to get API key from Authorization header (Bearer token)
        $authHeader = $this->request->headers->get('Authorization');
        if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        // Try to get API key from X-API-Key header
        return $this->request->headers->get('X-API-Key');
    }

    /**
     * Validate the API key
     *
     * @param string|null $apiKey The API key to validate
     * @return bool True if the API key is valid, false otherwise
     */
    protected function validateApiKey(?string $apiKey): bool
    {
        if ($apiKey === null) {
            return false;
        }

        // Create API key service to validate the key against the database
        $config = $this->getConfig();
        $apiKeyRepository = new DbApiKeyRepository($this->db, $config);
        $messageService = new MessageService();
        $apiKeyService = new ApiKeyService($apiKeyRepository, $this->db, $config, $messageService);

        // Authenticate using the API key service
        return $apiKeyService->authenticate($apiKey);
    }

    /**
     * Check if this controller is a V2 API controller
     *
     * @return bool True if V2 controller, false if V1
     */
    protected function isV2Controller(): bool
    {
        return str_contains(get_class($this), '\\V2\\');
    }

    /**
     * Check if the current API endpoint requires authentication
     * Can be overridden in child classes for public endpoints
     *
     * @return bool True if authentication is required, false otherwise
     */
    protected function requiresAuthentication(): bool
    {
        return true;
    }

    /**
     * Return API response with standard format
     *
     * @param mixed $data The data to return
     * @param bool $success Whether the request was successful
     * @param string|null $message Optional message
     * @param int $status HTTP status code
     * @param array $additionalFields Additional response fields (pagination, meta, etc.)
     * @return JsonResponse The JSON response object
     */
    protected function returnApiResponse($data, bool $success = true, ?string $message = null, int $status = 200, array $additionalFields = []): JsonResponse
    {
        $response = [
            'success' => $success,
            'data' => $data
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        // Merge additional fields (pagination, meta, etc.) into response
        $response = array_merge($response, $additionalFields);

        return $this->returnJsonResponse($response, $status);
    }

    /**
     * Return API error response
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     * @param mixed $data Additional error data
     * @param array $headers Additional headers
     * @return JsonResponse The JSON response object
     */
    protected function returnApiError(string $message, int $status = 400, $data = null, array $headers = []): JsonResponse
    {
        return $this->returnApiResponse($data, false, $message, $status, $headers);
    }

    /**
     * Get the authenticated user ID (stateless)
     *
     * @return int The authenticated user ID or 0 if not authenticated
     */
    protected function getAuthenticatedUserId(): int
    {
        return $this->authenticatedUserId;
    }

    /**
     * Handle exception and return JSON error response
     *
     * Catches all throwables (Exception, TypeError, Error, etc.) and returns a proper JSON
     * error response instead of letting PHP display HTML errors. Logs detailed error
     * information for debugging.
     *
     * @param \Throwable $e The exception/error to handle
     * @param string $context Context description (e.g., method name)
     * @param string $userMessage User-friendly error message
     * @param int $statusCode HTTP status code
     * @return JsonResponse JSON error response
     */
    protected function handleException(\Throwable $e, string $context, string $userMessage = 'An error occurred', int $statusCode = 500): JsonResponse
    {
        // Log detailed error information for debugging
        error_log(sprintf(
            '[API Error] %s - %s: %s in %s:%d',
            $context,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        // Return clean JSON error response to client
        return $this->returnApiError($userMessage . ': ' . $e->getMessage(), $statusCode);
    }
}
