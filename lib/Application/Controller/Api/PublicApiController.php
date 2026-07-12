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
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Infrastructure\Service\ApiKeyAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class PublicApiController extends AbstractApiController
{
    /**
     * RFC 8594 sunset date for API v1. V1 is superseded by v2 and scheduled
     * for removal in Poweradmin 4.4.0. Clients should migrate before this date.
     */
    private const V1_SUNSET_DATE = 'Tue, 01 Sep 2026 00:00:00 GMT';

    protected const MAX_PAGE_SIZE = 10000;

    protected array $pathParameters;
    protected int $authenticatedUserId = 0;
    protected LoggerInterface $logger;

    /**
     * Permission scope of the API key used for this request. Null when the request
     * authenticated via HTTP Basic auth or no key scope could be resolved, in which
     * case {@see self::getApiKeyScope()} returns an unrestricted scope.
     */
    protected ?ApiKeyScope $apiKeyScope = null;

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

        // Initialize PSR-3 logger
        $config = $this->getConfig();
        $logHandler = LoggerHandlerFactory::create($config->getAll());
        $logLevel = $config->get('logging', 'level', 'info');
        $this->logger = new Logger($logHandler, $logLevel);

        // Authenticate the API request using API key or HTTP Basic auth
        $this->authenticateApiRequest();

        // Enforce the API key's read-only / operation scope before any handler runs
        $this->enforceApiKeyMethodScope();

        // HEAD passes the read-only scope check above; route it to the GET handler so
        // each v2 controller answers it instead of falling through to a 405. The
        // bootstrap buffers away the GET body so the client still gets headers only.
        if ($this->isV2Controller() && strtoupper($this->request->getMethod()) === 'HEAD') {
            $this->request->setMethod('GET');
        }

        // Log deprecation warning for V1 API requests
        if (!$this->isV2Controller()) {
            $this->logger->warning('Deprecated API v1 request: {method} {path} from {ip}', [
                'method' => $this->request->getMethod(),
                'path' => $this->request->getPathInfo(),
                'ip' => $this->request->getClientIp(),
            ]);
        }
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
            $this->apiKeyScope = $apiKeyMiddleware->getApiKeyScope($this->request);

            // The key authenticated, so its scope must resolve. A null here means a
            // lookup error (e.g. transient DB failure); fail closed rather than fall
            // back to an unrestricted scope and grant more than the key allows.
            if ($this->apiKeyScope === null) {
                $response = $this->isV2Controller()
                    ? $this->returnApiError('Unable to verify API key permissions', 403)
                    : $this->returnErrorResponse('Unable to verify API key permissions', 403);
                $response->send();
                exit;
            }
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

        // Make the authenticated identity visible to UserContextService so the
        // change log records the actor (instead of falling back to "system")
        // for record/zone mutations performed via the API.
        if ($this->authenticatedUserId > 0) {
            UserContextService::setApiUserContext(
                $this->authenticatedUserId,
                $this->getAuthenticatedUsername()
            );
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
     * Pick the right "cannot edit this zone's records" message: read-only zones
     * (Secondary, Consumer) replicate from a primary and are rejected for a
     * different reason than a missing edit permission. Keeps the public error
     * contract accurate for both cases.
     *
     * @param string|null $zoneType Zone kind (MASTER, SLAVE, NATIVE, CONSUMER) when known
     * @return string Error message describing why record edits are not allowed
     */
    protected function zoneEditDeniedMessage(?string $zoneType): string
    {
        return ZoneType::isReadOnly($zoneType)
            ? 'Records in Secondary and Consumer zones are read-only; they replicate from a primary'
            : 'You do not have permission to edit this zone';
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

    protected function getAuthenticatedUsername(): string
    {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute([':id' => $this->authenticatedUserId]);
        return $stmt->fetchColumn() ?: 'user_id:' . $this->authenticatedUserId;
    }

    /**
     * Get the permission scope of the API key for this request. Requests without a
     * key scope (Basic auth, or an unresolvable key) are treated as unrestricted.
     *
     * @return ApiKeyScope The resolved scope, never null
     */
    protected function getApiKeyScope(): ApiKeyScope
    {
        return $this->apiKeyScope ?? ApiKeyScope::unrestricted();
    }

    /**
     * Reject the request with 403 when the API key's read-only/operation scope
     * does not permit the HTTP method. This is a request-global gate and only
     * applies to the v2 API; v1 keys remain unrestricted.
     *
     * @return void
     */
    protected function enforceApiKeyMethodScope(): void
    {
        if (!$this->isV2Controller()) {
            return;
        }

        $scope = $this->getApiKeyScope();
        $method = strtoupper($this->request->getMethod());

        // Read-only is always method-based and always correct: only GET/HEAD pass.
        if ($scope->isReadonly() && !in_array($method, ['GET', 'HEAD'], true)) {
            $this->sendApiKeyOperationForbidden();
        }

        // Every operation this request performs must be permitted. The default is
        // the HTTP method's operation; controllers whose method does not map to a
        // single operation (upserts, DNSSEC toggles, bulk) override the hook below.
        foreach ($this->requiredApiKeyOperations() as $operation) {
            if (!$scope->isOperationTypeAllowed($operation)) {
                $this->sendApiKeyOperationForbidden();
            }
        }
    }

    /**
     * Operations the current request performs, all of which the API key must
     * permit. Defaults to the single operation implied by the HTTP method.
     * Override for endpoints where the method is not a 1:1 operation mapping:
     * return the exact set (e.g. [create, update] for an upsert), or [] to skip
     * the central check and enforce the operation scope inside the handler.
     *
     * @return string[]
     */
    protected function requiredApiKeyOperations(): array
    {
        return [ApiKeyScope::methodToOperation($this->request->getMethod())];
    }

    /**
     * Send a 403 for an operation the API key may not perform, and stop.
     *
     * @return never
     */
    protected function sendApiKeyOperationForbidden(): void
    {
        $response = $this->returnApiError(
            'Forbidden: this API key is not permitted to perform this operation',
            403
        );
        $response->send();
        exit;
    }

    /**
     * Guard a zone-scoped endpoint against the API key's zone restriction.
     * Returns a 403 response when the zone is out of scope, or null when allowed.
     * Callers return the response directly: `if (($r = $this->enforceApiKeyZoneScope($id)) !== null) { return $r; }`
     *
     * @param int $zoneId The zone (domain) ID the request targets
     * @return JsonResponse|null A 403 response, or null when the zone is in scope
     */
    protected function enforceApiKeyZoneScope(int $zoneId): ?JsonResponse
    {
        if ($this->getApiKeyScope()->isZoneAllowed($zoneId)) {
            return null;
        }

        return $this->returnApiError(
            'Forbidden: this API key does not have access to the requested zone',
            403
        );
    }

    /**
     * Override to inject deprecation headers for V1 API responses
     *
     * @param mixed $data The data to return
     * @param int $status HTTP status code
     * @param array $headers Additional headers to include
     * @return JsonResponse
     */
    protected function returnJsonResponse($data, int $status = 200, array $headers = []): JsonResponse
    {
        if (!$this->isV2Controller()) {
            $headers['Deprecation'] = 'true';
            $headers['Sunset'] = self::V1_SUNSET_DATE;
            $headers['Link'] = '</api/v2/>; rel="successor-version"';
        }

        return parent::returnJsonResponse($data, $status, $headers);
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
    /**
     * Apply V2 record-content formatting.
     *
     * V2 always quotes single-string TXT records, even when dns.txt_auto_quote is
     * off, so records round-trip: create quotes, read strips, update must re-quote.
     * Used by create and update paths alike so stored content stays consistent.
     */
    protected function formatV2RecordContent(string $type, string $content): string
    {
        $type = strtoupper($type);
        $content = (new DnsFormatter($this->getConfig()))->formatContent($type, $content);
        if ($type === 'TXT') {
            $content = trim($content);
            if (!str_starts_with($content, '"') || !str_ends_with($content, '"')) {
                $content = '"' . $content . '"';
            }
        }
        return $content;
    }

    protected function handleException(\Throwable $e, string $context, string $userMessage = 'An error occurred', int $statusCode = 500): JsonResponse
    {
        // Log detailed error information for debugging
        $this->logger->error('[API Error] {context} - {class}: {message} in {file}:{line}', [
            'context' => $context,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        // Return clean JSON error response to client
        return $this->returnApiError($userMessage . ': ' . $e->getMessage(), $statusCode);
    }
}
