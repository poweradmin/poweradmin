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
 * Base controller for V1 Public API
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\v1;

use Poweradmin\Application\Controller\Api\ApiBaseController;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class V1ApiBaseController extends ApiBaseController
{
    /**
     * Constructor for V1ApiBaseController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request, false); // false means don't authenticate in base controller

        // Authenticate API request
        $this->authenticateApiRequest();
    }

    /**
     * Authenticate the API request using API key
     * Override this method in specific implementations if needed
     *
     * @return JsonResponse|null Returns error response if authentication fails, null otherwise
     */
    protected function authenticateApiRequest(): ?JsonResponse
    {
        // Get API key from headers
        $apiKey = $this->getApiKeyFromRequest();

        // If API key is required but not provided or invalid
        if ($this->requiresAuthentication() && !$this->validateApiKey($apiKey)) {
            $response = $this->returnErrorResponse('Invalid or missing API key', 401);
            $response->send();
            exit;
        }

        return null;
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

        // Get allowed API keys from configuration
        $allowedKeys = $this->getConfig()->get('api', 'keys', []);

        return in_array($apiKey, $allowedKeys);
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
     * @param array $headers Additional headers
     * @return JsonResponse The JSON response object
     */
    protected function returnApiResponse($data, bool $success = true, ?string $message = null, int $status = 200, array $headers = []): JsonResponse
    {
        $response = [
            'success' => $success,
            'data' => $data
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return $this->returnJsonResponse($response, $status, $headers);
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
}
