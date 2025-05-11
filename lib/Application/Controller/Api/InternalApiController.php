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
 * Internal API controller for session-authenticated endpoints
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Domain\Model\UserManager;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class InternalApiController extends AbstractApiController
{
    /**
     * InternalApiController constructor
     *
     * @param array $requestParams The request parameters
     */
    public function __construct(array $requestParams)
    {
        // Call parent constructor with authentication enabled
        // This will use session-based authentication
        parent::__construct($requestParams, true);

        // Additional validation for internal API
        $this->validateAuthentication();
    }

    /**
     * Validate that the user is authenticated using session
     */
    protected function validateAuthentication(): void
    {
        if (!isset($_SESSION["userid"])) {
            $response = $this->returnErrorResponse('Unauthorized access', 401);
            $response->send();
            exit;
        }
    }

    /**
     * Check if the user has the required permission
     *
     * @param string $permission The permission to check
     * @return bool True if the user has the permission, false otherwise
     */
    protected function hasPermission(string $permission): bool
    {
        return UserManager::verifyPermission($this->db, $permission);
    }

    /**
     * Validate that the user has the required permission
     *
     * @param string $permission The permission to check
     */
    protected function validatePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            $response = $this->returnErrorResponse('Forbidden: insufficient permissions', 403);
            $response->send();
            exit;
        }
    }

    /**
     * Return API response with standard format for internal API
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
     * Return API error response for internal API
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
