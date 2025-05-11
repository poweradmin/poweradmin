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
 * Abstract base API controller class with common functionality
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

abstract class AbstractApiController extends BaseController
{
    /**
     * @var Request The current HTTP request
     */
    protected Request $request;

    /**
     * @var Serializer The Symfony serializer
     */
    protected Serializer $serializer;

    /**
     * @var Request|null Temporary request for initialization
     */
    private static ?Request $tempRequest = null;

    /**
     * AbstractApiController constructor
     *
     * @param array $requestParams The request parameters
     * @param bool $authenticate Whether to authenticate the user (default: true)
     */
    public function __construct(array $requestParams, bool $authenticate = true)
    {
        // Create Request object before anything else for route determination
        // Store it in a static property for use before $this->request is initialized
        self::$tempRequest = Request::createFromGlobals();

        // Initialize config early
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        // Check if API is enabled in the system
        if (!$config->get('api', 'enabled', false)) {
            // Return API disabled error
            $response = new JsonResponse([
                'error' => true,
                'message' => 'The API feature is disabled in the system configuration.'
            ], 403);
            $response->send();
            exit;
        }

        // Call parent constructor with authenticate param for session handling if needed
        parent::__construct($requestParams, $authenticate);

        // Assign the already created Symfony Request object to the instance property
        $this->request = self::$tempRequest;

        // Initialize the serializer
        $encoders = [new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $this->serializer = new Serializer($normalizers, $encoders);
    }

    /**
     * Checks if the current request is a JSON request
     *
     * @return bool True if the request is JSON, false otherwise
     */
    protected function isJsonRequest(): bool
    {
        $contentType = $this->request->headers->get('Content-Type');
        if ($contentType && strpos($contentType, 'application/json') !== false) {
            return true;
        }

        // Support both JSON and form data for flexibility
        $content = $this->request->getContent();
        return !empty($content) && $this->isValidJson($content);
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
        if ($this->isJsonRequest()) {
            $content = $this->request->getContent();
            return json_decode($content, true);
        }

        // Fall back to POST data if no valid JSON in the body
        return $this->request->request->all() ?: null;
    }

    /**
     * Return a JSON response
     *
     * @param mixed $data The data to return
     * @param int $status HTTP status code
     * @param array $headers Additional headers to include
     * @return JsonResponse
     */
    protected function returnJsonResponse($data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Return an error response
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     * @param string|null $code Optional error code
     * @return JsonResponse
     */
    protected function returnErrorResponse(string $message, int $status = 400, ?string $code = null): JsonResponse
    {
        $response = [
            'error' => true,
            'message' => $message
        ];

        if ($code !== null) {
            $response['code'] = $code;
        }

        return $this->returnJsonResponse($response, $status);
    }

    /**
     * Helper method to serialize objects to JSON
     *
     * @param mixed $data The data to serialize
     * @param array $context Serialization context
     * @return string The serialized JSON
     */
    protected function serialize($data, array $context = []): string
    {
        return $this->serializer->serialize($data, 'json', $context);
    }

    /**
     * Determines if this is a public API route (v1, v2, etc.) or internal API route
     *
     * @return bool True if this is a public API route, false otherwise
     */
    protected function isPublicApiRoute(): bool
    {
        // Use the temporary request object which is initialized before this method is called
        $page = self::$tempRequest?->query->get('page', '');

        // Check if this is an API route
        if (!$page || !str_starts_with($page, 'api/')) {
            return false;
        }

        // Extract the API version from the route
        $parts = explode('/', $page);
        if (count($parts) < 2) {
            return false;
        }

        // Check if the second part is a version indicator (v1, v2, etc.)
        $versionPart = $parts[1] ?? '';
        return preg_match('/^v\d+$/i', $versionPart) === 1;
    }
}
