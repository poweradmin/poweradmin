<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates API requests by the X-API-Key header and loads the key's user.
 *
 * This middleware checks for API key in request headers and authenticates the user if the key is valid
 */
class ApiKeyAuthenticationMiddleware
{
    private ApiKeyService $apiKeyService;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private bool $queryParamWarned = false;

    public function __construct(ApiKeyService $apiKeyService, ConfigurationInterface $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = $config;
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Process the request
     *
     * @param Request $request The HTTP request
     * @return bool True if authentication succeeded, false otherwise
     */
    public function process(Request $request): bool
    {
        // Check if API functionality is enabled (which includes API keys)
        if (!$this->config->get('api', 'enabled', false)) {
            return false;
        }

        // Check for API key in headers
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            return false;
        }

        // Authenticate with the API key
        $authenticated = $this->apiKeyService->authenticate($apiKey);

        return $authenticated;
    }

    /**
     * Get authenticated user ID from API key (stateless)
     *
     * @param Request $request The HTTP request
     * @return int User ID or 0 if not authenticated
     */
    public function getAuthenticatedUserId(Request $request): int
    {
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            return 0;
        }

        return $this->apiKeyService->getUserIdFromApiKey($apiKey);
    }

    /**
     * Get the api_keys row id for the request's key (stateless), for audit logging.
     *
     * @param Request $request The HTTP request
     * @return int|null The key id, or null when no key is present/resolvable
     */
    public function getAuthenticatedApiKeyId(Request $request): ?int
    {
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            return null;
        }

        return $this->apiKeyService->getIdFromApiKey($apiKey);
    }

    /**
     * Get the permission scope of the request's API key (stateless)
     *
     * @param Request $request The HTTP request
     * @return ApiKeyScope|null The scope, or null when no key is present/resolvable
     */
    public function getApiKeyScope(Request $request): ?ApiKeyScope
    {
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            return null;
        }

        return $this->apiKeyService->getScopeFromApiKey($apiKey);
    }

    /**
     * Extract API key from request headers
     *
     * @param Request $request The HTTP request
     * @return string|null The API key if found, null otherwise
     */
    private function extractApiKey(Request $request): ?string
    {
        // DIRECT ACCESS: Get API key directly from server superglobal which is more reliable
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $apiKey = $_SERVER['HTTP_X_API_KEY'];
            return $apiKey;
        }

        // Check for API key in Authorization header (Bearer token)
        $authHeader = $request->headers->get('Authorization');
        if (!empty($authHeader) && strpos($authHeader, 'Bearer ') === 0) {
            $key = trim(substr($authHeader, 7));
            return $key;
        }

        // Check for API key in X-API-Key header - case insensitive using all header names
        $apiKey = null;
        foreach ($request->headers->all() as $name => $values) {
            if (strtolower($name) === 'x-api-key') {
                $apiKey = trim($values[0]);
                return $apiKey;
            }
        }

        // Deprecated: the key ends up in web server and proxy access logs
        // An array value (api_key[]=x) is no key; get() would throw on it
        $apiKeyParam = $request->query->all()['api_key'] ?? null;
        if (is_string($apiKeyParam) && $apiKeyParam !== '') {
            $this->warnQueryParamDeprecated();
            return trim($apiKeyParam);
        }

        return null;
    }

    private function warnQueryParamDeprecated(): void
    {
        if ($this->queryParamWarned) {
            return;
        }
        $this->queryParamWarned = true;
        $this->logger->warning('The api_key query parameter is deprecated because the key is written to access logs; send it in the X-API-Key header instead');
    }
}
