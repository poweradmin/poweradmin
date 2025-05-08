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

namespace Poweradmin\Infrastructure\Service;

use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Key Authentication Middleware
 *
 * This middleware checks for API key in request headers and authenticates the user if the key is valid
 *
 * @package Poweradmin\Infrastructure\Service
 */
class ApiKeyAuthenticationMiddleware
{
    private ApiKeyService $apiKeyService;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param PDOLayer $db Database connection
     * @param ConfigurationManager $config Configuration manager
     */
    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->config = $config;
        $apiKeyRepository = new DbApiKeyRepository($db, $config);
        $messageService = new MessageService();
        $this->apiKeyService = new ApiKeyService($apiKeyRepository, $db, $config, $messageService);
    }

    /**
     * Process the request
     *
     * @param Request $request The HTTP request
     * @return bool True if authentication succeeded, false otherwise
     */
    public function process(Request $request): bool
    {
        // Check if API key authentication is enabled
        if (!$this->config->get('api', 'keys_enabled', false)) {
            return false;
        }

        // Check for API key in headers
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            return false;
        }

        // Authenticate with the API key
        return $this->apiKeyService->authenticate($apiKey);
    }

    /**
     * Handle anonymous request on API routes that require authentication
     *
     * @return JsonResponse
     */
    public function handleUnauthenticated(): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'message' => 'Authentication required',
            'code' => 'auth_required'
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Extract API key from request headers
     *
     * @param Request $request The HTTP request
     * @return string|null The API key if found, null otherwise
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check for API key in Authorization header (Bearer token)
        $authHeader = $request->headers->get('Authorization');
        if (!empty($authHeader) && strpos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }

        // Check for API key in X-API-Key header
        $apiKeyHeader = $request->headers->get('X-API-Key');
        if (!empty($apiKeyHeader)) {
            return trim($apiKeyHeader);
        }

        // Check for API key in api_key query parameter (not recommended but supported)
        $apiKeyParam = $request->query->get('api_key');
        if (!empty($apiKeyParam)) {
            return trim($apiKeyParam);
        }

        return null;
    }
}
