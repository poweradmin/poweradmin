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
        // Dump complete request headers for debugging
        error_log('[ApiKeyAuthenticationMiddleware] REQUEST HEADERS DUMP:');
        foreach ($request->headers->all() as $name => $values) {
            error_log("  Header: $name => " . implode(', ', $values));
        }

        // Check if API functionality is enabled (which includes API keys)
        if (!$this->config->get('api', 'enabled', false)) {
            // Add debug logs
            error_log('[ApiKeyAuthenticationMiddleware] API is disabled in configuration');
            return false;
        }

        // Check for API key in headers
        $apiKey = $this->extractApiKey($request);
        if (empty($apiKey)) {
            // Add debug logs
            error_log('[ApiKeyAuthenticationMiddleware] No API key found in request');
            return false;
        }

        // Log API key length for debugging (don't log the full key for security)
        $keyLength = strlen($apiKey);
        $keyPrefix = substr($apiKey, 0, 4) . '...';
        error_log(sprintf('[ApiKeyAuthenticationMiddleware] Found API key: %s (length: %d)', $keyPrefix, $keyLength));

        // Authenticate with the API key - add more debug information
        error_log('[ApiKeyAuthenticationMiddleware] Authenticating with API key...');
        $authenticated = $this->apiKeyService->authenticate($apiKey);
        error_log(sprintf('[ApiKeyAuthenticationMiddleware] Authentication %s', $authenticated ? 'succeeded' : 'failed'));

        if (!$authenticated) {
            // For debugging only - compare with a known good key
            error_log('[ApiKeyAuthenticationMiddleware] Attempting to find key in database directly...');

            try {
                $db = $this->apiKeyService->getDb();
                $stmt = $db->prepare("SELECT COUNT(*) FROM api_keys WHERE secret_key = :key");
                $stmt->bindValue(':key', $apiKey);
                $stmt->execute();
                $found = $stmt->fetchColumn() > 0;

                error_log(sprintf(
                    '[ApiKeyAuthenticationMiddleware] Direct DB check: Key %s in database',
                    $found ? 'found' : 'NOT found'
                ));

                if (!$found) {
                    // Show a sample of what's in the DB
                    $stmt = $db->query("SELECT LEFT(secret_key, 8) as prefix, RIGHT(secret_key, 8) as suffix, LENGTH(secret_key) as len FROM api_keys LIMIT 3");
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        error_log(sprintf(
                            '[ApiKeyAuthenticationMiddleware] Sample key in DB: %s...%s (len: %d)',
                            $row['prefix'],
                            $row['suffix'],
                            $row['len']
                        ));
                    }
                }
            } catch (\Exception $e) {
                error_log('[ApiKeyAuthenticationMiddleware] Error checking DB: ' . $e->getMessage());
            }
        }

        return $authenticated;
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
        // DIRECT ACCESS: Get API key directly from server superglobal which is more reliable
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $apiKey = $_SERVER['HTTP_X_API_KEY'];
            error_log("[ApiKeyMiddleware] Found API key directly in \$_SERVER['HTTP_X_API_KEY']");
            return $apiKey;
        }

        // Check for API key in Authorization header (Bearer token)
        $authHeader = $request->headers->get('Authorization');
        if (!empty($authHeader) && strpos($authHeader, 'Bearer ') === 0) {
            $key = trim(substr($authHeader, 7));
            error_log("[ApiKeyMiddleware] Found API key in Authorization header");
            return $key;
        }

        // Check for API key in X-API-Key header - case insensitive using all header names
        $apiKey = null;
        foreach ($request->headers->all() as $name => $values) {
            if (strtolower($name) === 'x-api-key') {
                $apiKey = trim($values[0]);
                error_log("[ApiKeyMiddleware] Found API key in $name header");
                return $apiKey;
            }
        }

        // Check for API key in api_key query parameter (not recommended but supported)
        $apiKeyParam = $request->query->get('api_key');
        if (!empty($apiKeyParam)) {
            error_log("[ApiKeyMiddleware] Found API key in query parameter");
            return trim($apiKeyParam);
        }

        error_log("[ApiKeyMiddleware] No API key found in request");
        return null;
    }
}
