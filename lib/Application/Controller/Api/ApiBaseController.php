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
 * Base API controller class
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\BaseController;

abstract class ApiBaseController extends BaseController
{
    /**
     * Checks if the current request is a JSON request
     *
     * @return bool True if the request is JSON, false otherwise
     */
    protected function isJsonRequest(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            return true;
        }

        // Support both JSON and form data for flexibility
        $input = file_get_contents('php://input');
        return !empty($input) && $this->isValidJson($input);
    }

    /**
     * Check if input is valid JSON
     */
    private function isValidJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get JSON input from request body
     *
     * @return array|null Decoded JSON data or null if invalid
     */
    protected function getJsonInput(): ?array
    {
        // Try to get JSON from request body
        $jsonInput = file_get_contents('php://input');

        if (!empty($jsonInput)) {
            $data = json_decode($jsonInput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        // Fall back to POST data if no valid JSON in the body
        if (!empty($_POST)) {
            return $_POST;
        }

        return null;
    }

    /**
     * Return a JSON response
     *
     * @param mixed $data The data to return
     * @param int $status HTTP status code
     */
    protected function returnJsonResponse($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Return an error response
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     */
    protected function returnErrorResponse(string $message, int $status = 400): void
    {
        $this->returnJsonResponse([
            'error' => true,
            'message' => $message
        ], $status);
    }
}
