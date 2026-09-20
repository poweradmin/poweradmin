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

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\PdnsVersionService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\DatabaseCredentialMapper;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Service\ApiKeyAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Base for /api/v2 endpoints: API key or Basic auth, key scope enforcement, request logging and wrapped responses.
 */
abstract class PublicApiController extends AbstractApiController
{
    protected const MAX_PAGE_SIZE = 10000;

    /** Refusal for a direct write when the caller's changes to the zone go through review */
    public const CHANGE_REQUEST_REQUIRED = 'Changes to this zone require approval; create a change request instead';

    /** Every change request endpoint answers this while approval.enabled is off */
    public const CHANGE_APPROVAL_DISABLED = 'Change approval is not enabled';

    protected array $pathParameters;
    protected int $authenticatedUserId = 0;

    /**
     * Permission scope of the API key used for this request. Null when the request
     * authenticated via HTTP Basic auth or no key scope could be resolved, in which
     * case {@see self::getApiKeyScope()} returns an unrestricted scope.
     */
    protected ?ApiKeyScope $apiKeyScope = null;

    /** @var array<string, mixed> Request-scoped stand-in for the session version cache */
    private array $capabilityCache = [];

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

        // Enforce the API key's read-only / operation scope before any handler runs
        $this->enforceApiKeyMethodScope();

        // HEAD passes the read-only scope check above; route it to the GET handler so
        // each controller answers it instead of falling through to a 405. The
        // bootstrap buffers away the GET body so the client still gets headers only.
        if (strtoupper($this->request->getMethod()) === 'HEAD') {
            $this->request->setMethod('GET');
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
                $response = $this->returnApiError('Unable to verify API key permissions', 403);
                $response->send();
                exit;
            }
        }

        // Try Basic auth if API key auth failed and it's enabled
        if (!$authenticated && $config->get('api', 'basic_auth_enabled', false)) {
            $basicAuthMiddleware = new BasicAuthenticationMiddleware($db, $config);
            $this->authenticatedUserId = $basicAuthMiddleware->getAuthenticatedUserId($this->request);
            $authenticated = ($this->authenticatedUserId > 0);
        }

        // If all authentication methods failed, return 401 Unauthorized
        if (!$authenticated) {
            $response = $this->returnApiError('Unauthorized: Invalid credentials', 401);
            if ($config->get('api', 'basic_auth_enabled', false)) {
                $realm = addcslashes((string)$config->get('api', 'basic_auth_realm', 'Poweradmin API'), '\\"');
                $response->headers->set('WWW-Authenticate', 'Basic realm="' . $realm . '"');
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
        $apiKeyService = new ApiKeyService($apiKeyRepository, $this->db, $config);

        // Authenticate using the API key service
        return $apiKeyService->authenticate($apiKey);
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
     * The 403 a direct write gets when the caller's changes to the zone are
     * routed through change requests, or null when the write may proceed.
     */
    protected function refuseWhenChangeRequestRequired(ApiPermissionService $permissions, int $userId, int $zoneId): ?JsonResponse
    {
        if ($permissions->getChangeApprovalMode($userId, $zoneId) !== ChangeApprovalPolicy::MODE_REQUEST) {
            return null;
        }

        return $this->returnApiError(self::CHANGE_REQUEST_REQUIRED, 403);
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
     * API requests carry no session, so the PowerDNS version is fetched on first
     * use and kept for this request only. Callers ask lazily (a zone create with a
     * catalog kind), so ordinary requests never pay for the lookup.
     */
    protected function getPdnsCapabilities(): PdnsCapabilities
    {
        if ($this->capabilityCache === []) {
            PdnsVersionService::refreshFromConfig($this->config, $this->logger, $this->capabilityCache);
        }

        return PdnsCapabilities::fromServerInfo(PdnsVersionService::getCachedInfo($this->capabilityCache));
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
     * does not permit the HTTP method. This is a request-global gate.
     *
     * @return void
     */
    protected function enforceApiKeyMethodScope(): void
    {
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
     * Override to record every public API response in the audit log
     *
     * @param mixed $data The data to return
     * @param int $status HTTP status code
     * @param array $headers Additional headers to include
     * @return JsonResponse
     */
    protected function returnJsonResponse($data, int $status = 200, array $headers = []): JsonResponse
    {
        $this->logApiRequest($status);

        return parent::returnJsonResponse($data, $status, $headers);
    }

    /**
     * Record an API request in the audit log (log_api table).
     *
     * Permission violations (401/403) are always logged when database audit
     * logging is on, since they are a low-volume security signal. Successful and
     * other requests are logged only when the api_request_logging opt-in is set,
     * because per-request logging is high-volume. Logging never blocks the
     * response - any failure is swallowed.
     */
    private function logApiRequest(int $status): void
    {
        try {
            $config = $this->getConfig();
            $isViolation = $status === 401 || $status === 403;
            if (!$isViolation && !$config->get('logging', 'api_request_logging', false)) {
                return;
            }

            $operation = $isViolation ? 'api_violation' : 'api_request';
            // Resolve the API key id lazily - only now that a log row is actually
            // being written - so the default (logging off) path pays nothing.
            $keyId = '-';
            if ($this->authenticatedUserId > 0) {
                $id = (new ApiKeyAuthenticationMiddleware($this->db, $config))->getAuthenticatedApiKeyId($this->request);
                if ($id !== null) {
                    $keyId = (string)$id;
                }
            }
            $user = $this->authenticatedUserId > 0 ? $this->getAuthenticatedUsername() : '';
            // The constructor rewrites v2 HEAD to GET so handlers can serve it;
            // read the original method so the audit trail stays accurate.
            $method = $_SERVER['REQUEST_METHOD'] ?? $this->request->getMethod();
            // Cap the path so a pathological URL cannot push the event past the
            // log_api.event 2048-char column and get the whole row (incl. a
            // violation) rejected and silently dropped.
            $path = mb_substr($this->request->getPathInfo(), 0, 1500);

            $this->createAuditService()->logApiRequest($operation, $method, $path, $status, $keyId, $user);
            $this->pruneApiLog($config);
        } catch (\Throwable $e) {
            // Audit logging must never break the API response.
        }
    }

    /**
     * Occasionally drop API log rows older than the configured retention window.
     *
     * There is no scheduler in Poweradmin, so pruning piggybacks on writes at a
     * low probability (like PHP session GC) to keep the table bounded without a
     * DELETE on every request. Retention of 0 means keep forever.
     */
    private function pruneApiLog(ConfigurationManager $config): void
    {
        $retentionDays = (int)$config->get('logging', 'api_log_retention_days', 0);
        if ($retentionDays <= 0) {
            return;
        }
        if (random_int(1, 100) !== 1) {
            return;
        }
        (new DbApiLogger($this->db))->pruneOlderThan($retentionDays);
    }

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
        // Stored content is punycode, as the web forms write it.
        $content = DnsIdnService::convertContentToPunycode($type, $content);
        $content = (new DnsFormatter($this->getConfig()))->formatContent($type, $content);
        if ($type === 'TXT') {
            $content = trim($content);
            if (!str_starts_with($content, '"') || !str_ends_with($content, '"')) {
                $content = '"' . $content . '"';
            }
        }
        return $content;
    }

    /**
     * The API wording for a refused record write: a duplicate and a backend fault
     * keep the contract strings, every other refusal carries the manager's reason.
     */
    protected function recordWriteErrorMessage(RecordWriteResult $result, string $backendFailureText): string
    {
        return match (true) {
            $result->status === 409 => 'A record with this hostname, type, and content already exists',
            $result->status === 500 => $backendFailureText,
            default => (string)$result->message,
        };
    }

    /**
     * Record names are stored as punycode and always carry the zone suffix.
     */
    protected function normalizeV2RecordName(string $name, string $zoneName): string
    {
        return DnsHelper::restoreZoneSuffix(DnsIdnService::toPunycode(trim($name)), $zoneName);
    }

    /**
     * Strip quotes from single-string TXT records for V2 API responses
     *
     * V2 responses present single-string TXT content unquoted regardless of stored
     * form (zone records are force-quoted on write, template records only when
     * dns.txt_auto_quote is on). Multi-string TXT records (e.g., "part1" "part2")
     * are preserved as-is since they represent long values split across multiple strings.
     *
     * @param string $content The TXT record content from database
     * @param string $type The record type
     * @return string The formatted content (quotes stripped for single-string TXT records)
     */
    protected function stripTxtQuotes(string $content, string $type): string
    {
        if ($type !== 'TXT') {
            return $content;
        }

        $content = trim($content);
        $isMultiString = str_contains($content, '" "');

        // Only strip quotes for single-string TXT records
        if (!$isMultiString && str_starts_with($content, '"') && str_ends_with($content, '"') && strlen($content) > 1) {
            return substr($content, 1, -1);
        }

        return $content;
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
        $this->logger->error('[API Error] {context} - {class}: {message} in {file}:{line}', [
            'context' => $context,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        // The raw exception text is already logged above; the client only gets the
        // contextual message so internal details (SQL, paths) cannot leak
        return $this->returnApiError($userMessage, $statusCode);
    }
}
