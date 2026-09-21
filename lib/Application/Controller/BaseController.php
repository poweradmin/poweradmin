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

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Boot\AppInitializer;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Presenter\OwnerOptionsPresenter;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\RequestValidator;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\PdnsVersionService;
use Poweradmin\Application\Service\ZoneCreateService;
use Poweradmin\Application\Boot\AppManager;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Application\Web\PageRenderer;
use Poweradmin\Module\ModuleRegistry;
use Psr\Log\LoggerInterface;

/**
 * Base for every web controller: config, database, session, permissions, CSRF, validation and Twig rendering.
 *
 * Provides common functionality for all controllers in the Poweradmin application.
 */
abstract class BaseController
{
    private ?AppManager $app = null;
    private ?AppInitializer $init = null;
    protected PDO $db;
    protected array $requestData;
    protected HttpRequest $httpRequest;
    private ?RequestValidator $requestValidator = null;
    private CsrfTokenService $csrfTokenService;
    protected MessageService $messageService;
    protected ConfigurationInterface $config;
    private UserContextService $userContextService;
    private string $pageTitle = '';
    protected LoggerInterface $logger;
    private ?ControllerServiceFactory $serviceFactory = null;
    private ?ChangeApprovalContext $changeApprovalContext = null;
    private ?ModuleRegistry $moduleRegistry = null;
    private ?PageRenderer $pageRenderer = null;

    /**
     * Abstract method to be implemented by subclasses to run the controller logic.
     */
    abstract public function run(): void;

    /**
     * Constructor for BaseController.
     *
     * @param array $request The request data.
     * @param bool $authenticate Whether to authenticate the user.
     * @param ControllerEnvironment|null $environment Pre-built collaborators; a test
     *        seam that skips the config/database/session bootstrap. The router
     *        never passes one, so production construction is unchanged.
     */
    public function __construct(array $request, bool $authenticate = true, ?ControllerEnvironment $environment = null)
    {
        if ($environment !== null) {
            $this->config = $environment->config;
            $this->logger = $environment->logger;
            $this->db = $environment->db;
            $this->serviceFactory = $environment->serviceFactory;

            $this->requestData = $request;
            $this->httpRequest = $environment->httpRequest ?? new HttpRequest();

            $this->csrfTokenService = $environment->csrfTokenService ?? new CsrfTokenService();
            $this->messageService = $environment->messageService ?? new MessageService();
            $this->userContextService = $environment->userContextService ?? new UserContextService();
        } else {
            // Create logger early so AppManager and ConfigurationManager can use it
            $manager = ConfigurationManager::getInstance();
            $manager->initialize();

            $this->logger = Logger::fromConfig($manager);

            $manager->setLogger($this->logger);
            $this->config = $manager;

            // Kept eager: the template stack below is lazy, and a broken configuration
            // should still stop the request rather than surface deep in a handler
            AppManager::assertConfigurationUsable($manager);

            $this->init = new AppInitializer($authenticate);
            $this->db = $this->init->getDb();

            $this->requestData = $request;
            $this->httpRequest = new HttpRequest();

            $this->csrfTokenService = new CsrfTokenService();
            $this->messageService = new MessageService();
            $this->userContextService = new UserContextService();
        }

        // Every state-changing web request is token-checked here rather than in each
        // controller, so a handler cannot be written without the guard
        if ($this->isPost() && $this->requiresCsrfValidation()) {
            $this->validateCsrfToken();
        }
    }

    /**
     * Whether a POST to this controller must carry a valid `_token`.
     *
     * Controllers that authenticate statelessly, receive third-party posts, or
     * validate their own flow-scoped token override this and return false.
     */
    protected function requiresCsrfValidation(): bool
    {
        return true;
    }

    /**
     * Checks if the current request is a POST request.
     *
     * @return bool True if the request method is POST, false otherwise.
     */
    public function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Gets the application configuration.
     *
     * @return ConfigurationInterface The application configuration.
     */
    public function getConfig(): ConfigurationInterface
    {
        return $this->config;
    }

    /**
     * Get a module config value with legacy fallback.
     *
     * Checks modules.<module>.<key> first, then falls back to <module>.<key>
     * for backward compatibility with pre-module config layouts.
     */
    protected function getModuleConfig(string $module, string $key, mixed $default = null): mixed
    {
        $value = $this->config->get('modules', "$module.$key", null);
        if ($value !== null) {
            return $value;
        }
        return $this->config->get($module, $key, $default);
    }

    /**
     * Renders a template with the given parameters.
     *
     * @param string $template The template to render.
     * @param array $params The parameters to pass to the template.
     */
    public function render(string $template, array $params): void
    {
        // The language selector vars are shared with the body template so the
        // login form's hidden userlang field carries the chosen language
        // through submission (PageRenderer memoizes them per request).
        $languageVars = $this->getPageRenderer()->languageVars();

        $this->renderHeader(
            $this->messageService->getMessages('system'),
            $this->messageService->getMessages(pathinfo($template)['filename'])
        );

        // csrf_token, base_url_prefix, pdns_caps, and pdns_server_info are Twig
        // globals (see PageRenderer::setupTwigEnvironment); page params still
        // override them.
        $params = array_merge($languageVars, $params);

        // Shared page chrome tested bare in many templates; the falsy defaults
        // keep strict_variables mode rendering identical to non-strict output.
        $params['message'] ??= false;
        $params['is_reverse_zone'] ??= false;
        $params['success'] ??= false;

        $this->app()->render($template, $params);
        $this->renderFooter();
    }

    /**
     * Build a PdnsCapabilities snapshot from the session-cached PowerDNS
     * version. Reads the cache without a network call while it is fresh;
     * once the entry expires it runs one rate-limited refresh, otherwise
     * every capability gate would silently turn off five minutes after the
     * last dashboard visit and hide catalog zones and newer record types.
     * A failed refresh keeps the expired entry rather than flipping the UI
     * to "unknown" over a transient API error.
     */
    protected function getPdnsCapabilities(): PdnsCapabilities
    {
        $info = PdnsVersionService::getCachedInfo($_SESSION ?? []);
        if ($info === null) {
            $this->refreshPdnsCapabilities();
            $info = PdnsVersionService::getCachedInfo($_SESSION ?? [], true);
        }
        return PdnsCapabilities::fromServerInfo($info);
    }

    /**
     * Capabilities to use when filtering selectable record types, or null to
     * skip version filtering entirely.
     *
     * Only API backends can report their PowerDNS version, so only there can we
     * safely hide types a newer-than-server feature would need. On SQL backends
     * the version is never known, and strict-unknown filtering would wrongly and
     * permanently drop valid types (HTTPS, SVCB, ZONEMD, ...) from every form.
     * Returning null there leaves the configured/default type list intact.
     */
    protected function getRecordTypeCapabilities(): ?PdnsCapabilities
    {
        if (!DnsBackendProviderFactory::isApiBackend($this->config)) {
            return null;
        }
        // An unknown version must not strict-filter: sessions that never ran the
        // dashboard refresh (or whose cache expired) would lose valid types.
        $caps = $this->getPdnsCapabilities();
        return $caps->isKnown() ? $caps : null;
    }

    /**
     * Trigger a session-cached refresh of PowerDNS version + capabilities.
     *
     * Runs in both API and SQL backend modes: the version display is useful in
     * either mode whenever `pdns_api` is configured, even though capability
     * gates only matter for API mode. getPdnsCapabilities() calls this itself
     * once the cached entry expires; call it directly only where a fresh
     * version matters more than the cached one - e.g. the dashboard.
     */
    protected function refreshPdnsCapabilities(): void
    {
        PdnsVersionService::refreshFromConfig($this->config, $this->logger, $_SESSION);
    }

    /**
     * Validates the CSRF token from the request.
     */
    public function validateCsrfToken(): void
    {
        if (!$this->config->get('security', 'global_token_validation', true)) {
            return;
        }

        $token = $this->getSafeRequestValue('_token');
        if (!$this->csrfTokenService->validateToken($token)) {
            $this->renderHeader();
            $this->messageService->addSystemError(_('Invalid CSRF token.'));
            $this->renderFooter();
            exit;
        }
    }

    /**
     * Redirects to a specified URL with optional arguments.
     * Automatically prepends base_url_prefix for subfolder deployments.
     *
     * @param string $url The URL to redirect to.
     * @param array $args The arguments to pass as query parameters.
     */
    public function redirect(string $url, array $args = []): void
    {
        // Clean URL implementation - all URLs should start with '/'
        if (!str_starts_with($url, '/')) {
            throw new InvalidArgumentException("URL must start with '/'. Got: $url");
        }

        // Prepend base_url_prefix for subfolder deployments
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        if (!empty($baseUrlPrefix)) {
            $url = $baseUrlPrefix . $url;
        }

        // Add query parameters if provided
        if (!empty($args)) {
            $url .= '?' . http_build_query($args);
        }

        $this->sendRedirect($url);
    }

    /**
     * Sets a message to be displayed for a specific script.
     *
     * @param string $script The script to set the message for.
     * @param string $type The type of message (error, warning, success, info).
     * @param string $content The content of the message.
     */
    public function setMessage(string $script, string $type, string $content): void
    {
        $this->messageService->addMessage($script, $type, $content);
    }

    /**
     * Gets messages for a specific script.
     *
     * @param string $script The script to get messages for.
     * @return array|null The messages for the script, or null if no messages are set.
     */
    public function getMessages(string $script): ?array
    {
        return $this->messageService->getMessages($script);
    }

    /**
     * Checks a condition and displays an error message if the condition is true.
     *
     * @param bool $condition The condition to check.
     * @param string $errorMessage The error message to display if the condition is true.
     */
    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            // Add as system message
            $this->addSystemMessage('error', $errorMessage);

            // Render the page with the message
            $systemMessages = $this->messageService->getMessages('system');
            $this->renderHeader($systemMessages);
            $this->renderFooter();
            exit;
        }
    }

    /**
     * Lazily builds the shared service factory so per-request memoized
     * instances (backend provider, permission cache) span all accessors.
     */
    protected function services(): ControllerServiceFactory
    {
        return $this->serviceFactory ??= new ControllerServiceFactory($this->db, $this->config, $this->logger);
    }

    /**
     * Resolves the page size for a listing: the request value wins, then the user's
     * stored preference, then interface.rows_per_page with $fallback when unset.
     */
    protected function resolveRowsPerPage(int $fallback = PaginationService::DEFAULT_ROWS_PER_PAGE): int
    {
        $default = (int) $this->config->get('interface', 'rows_per_page', $fallback);

        return $this->services()->paginationService()->getUserRowsPerPage(
            $default,
            $this->getCurrentUserId(),
            $this->httpRequest->getRowsPerPage()
        );
    }

    /**
     * Renders a pagination widget for a paginated listing.
     *
     * $path is the route with the `{PageNumber}` placeholder already in place
     * (e.g. '/zones/forward?start={PageNumber}'). $queryParams are extra
     * key => value pairs appended urlencoded, in order, skipping absent and blank values.
     */
    protected function presentPagination(int $totalItems, int $itemsPerPage, string $path, array $queryParams = []): string
    {
        $currentPage = $this->httpRequest->getPage();

        $pagination = $this->services()->paginationService()->createPagination($totalItems, $itemsPerPage, $currentPage);

        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $url = $baseUrlPrefix . $path;

        foreach ($queryParams as $key => $value) {
            // "0" is a real filter value (a comment of 0); only absent or blank ones are dropped
            if ($value === null || $value === '') {
                continue;
            }
            $url .= '&' . urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $presenter = new PaginationPresenter($pagination, $url, $this->httpRequest->getRowsPerPage());

        return $presenter->present();
    }

    protected function createZoneSortingService(): ZoneSortingService
    {
        return $this->services()->zoneSortingService($this->userContextService);
    }

    /**
     * Check if the logged-in user has the given permission
     */
    protected function hasPermission(string $permission): bool
    {
        $userId = $this->userContextService->getLoggedInUserId();
        return $userId !== null && $this->services()->permissionService()->hasPermission($userId, $permission);
    }

    /**
     * The logged-in user's zone view level: "all", "own" or "none"
     */
    protected function getViewPermissionLevel(): string
    {
        $userId = $this->userContextService->getLoggedInUserId();
        return $userId === null ? 'none' : $this->services()->permissionService()->getViewPermissionLevel($userId);
    }

    /**
     * Check if the logged-in user owns the given zone directly or via group membership
     */
    protected function isZoneOwner(int $zoneId): bool
    {
        $userId = $this->userContextService->getLoggedInUserId();
        return $userId !== null && $this->services()->permissionService()->userOwnsZone($userId, $zoneId);
    }

    /**
     * Stops the request unless the current user may open the zone (showError exits).
     */
    protected function requireZoneView(int $zoneId): void
    {
        $userId = $this->userContextService->getLoggedInUserId();
        if ($userId === null || !$this->services()->permissionService()->canViewZone($userId, $zoneId)) {
            $this->showError(_('You do not have permission to view this zone.'));
        }
    }

    /**
     * The zone service the API uses, told what the connected server supports.
     */
    protected function createZoneManagementService(): ZoneManagementService
    {
        return $this->services()->zoneManagementService(fn(): PdnsCapabilities => $this->getPdnsCapabilities());
    }

    /**
     * The zone creation flow of the add-zone forms, told what the connected server supports.
     */
    protected function createZoneCreateService(): ZoneCreateService
    {
        return $this->services()->zoneCreateService(fn(): PdnsCapabilities => $this->getPdnsCapabilities());
    }

    /**
     * Enabled modules, loaded once per request.
     */
    protected function moduleRegistry(): ModuleRegistry
    {
        if ($this->moduleRegistry === null) {
            $this->moduleRegistry = new ModuleRegistry($this->config);
            $this->moduleRegistry->loadModules();
        }

        return $this->moduleRegistry;
    }

    /**
     * What the enabled modules offer for a capability (wizard actions, lookup
     * links, export formats), honouring each module's admin restriction.
     *
     * @param array<string, mixed> $context Placeholders for the modules' url patterns, e.g. zone_id
     * @return array<array<string, string>>
     */
    protected function moduleCapabilityData(string $capability, array $context = []): array
    {
        return $this->moduleRegistry()->getCapabilityData($capability, $context, $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER));
    }

    protected function moduleProvides(string $capability): bool
    {
        foreach ($this->moduleRegistry()->getEnabledModules() as $module) {
            if (in_array($capability, $module->getCapabilities(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change-approval answers for one user and zone; callers pass the user id,
     * usually getCurrentUserId(). One instance per request.
     */
    protected function changeApproval(): ChangeApprovalContext
    {
        return $this->changeApprovalContext ??= $this->services()->changeApprovalContext();
    }

    protected function changeApprovalModeForZone(int $zoneId): string
    {
        return $this->changeApproval()->modeForZone($this->getCurrentUserId(), $zoneId);
    }

    private function pendingChangeRequestCount(): int
    {
        return $this->changeApproval()->pendingReviewCount($this->getCurrentUserId());
    }

    /**
     * Get current user ID
     *
     * @return int|null
     */
    protected function getCurrentUserId(): ?int
    {
        return $this->userContextService->getLoggedInUserId();
    }

    /**
     * The users a new zone may be given to: everyone when the create path lets
     * the caller assign other owners and they may see other users, otherwise
     * only the current user.
     *
     * @param list<array<string, mixed>> $users Rows with an 'id' key
     * @return list<array<string, mixed>>
     */
    protected function assignableOwners(array $users): array
    {
        return $this->services()->zoneOwnershipFormResolver()->assignableOwners($users, (int)$this->getCurrentUserId());
    }

    /**
     * The owner an add-zone form shows again after a failed submit: the posted
     * id when the picker can offer it, '' for an explicit "no user owner",
     * otherwise the current user.
     *
     * @param list<array<string, mixed>> $assignableOwners
     * @param mixed $ownerInput The raw posted value, if any
     */
    protected function preservedOwnerChoice(array $assignableOwners, mixed $ownerInput): int|string
    {
        return OwnerOptionsPresenter::preservedChoice($assignableOwners, $ownerInput, $this->getCurrentUserId());
    }

    /**
     * Flash the outcome of a zone write to a page: the given text on success,
     * the result's reason on failure.
     */
    protected function reportZoneWrite(string $script, ZoneWriteResult $result, string $successMessage): void
    {
        $this->setMessage($script, $result->success ? 'success' : 'error', $result->success ? $successMessage : (string)$result->message);
    }

    /**
     * Get the user context service
     *
     * @return UserContextService
     */
    protected function getUserContextService(): UserContextService
    {
        return $this->userContextService;
    }

    /**
     * Sets the current page identifier used for navigation highlighting.
     *
     * @param string $page The page identifier
     */
    protected function setCurrentPage(string $page): void
    {
        $this->requestData['page'] = $page;
    }

    /**
     * Sets the page title displayed in the header.
     *
     * @param string $title The page title
     */
    protected function setPageTitle(string $title): void
    {
        $this->pageTitle = $title;
    }

    /**
     * Checks if the user has a specific permission and displays an error message if not.
     *
     * @param string $permission The permission to check.
     * @param string $errorMessage The error message to display if the user does not have the permission.
     */
    public function checkPermission(string $permission, string $errorMessage): void
    {
        if (!$this->hasPermission($permission)) {
            $this->services()->auditService()->logAccessDenied($permission, $_SERVER['REQUEST_URI'] ?? '');

            // Check if this request expects JSON
            if (RequestContext::expectsJson()) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode([
                    'error' => true,
                    'message' => $errorMessage
                ]);
                exit;
            }

            // Add as system message
            $this->addSystemMessage('error', $errorMessage);

            // Render the page with the message
            $systemMessages = $this->messageService->getMessages('system');
            $this->renderHeader($systemMessages);
            $this->renderFooter();
            exit;
        }
    }

    /**
     * Reads a positive integer request parameter (an id) or ends the request
     * with an error page.
     */
    protected function requireNumericParam(string $name, ?string $error = null): int
    {
        $value = $this->getSafeRequestValue($name);
        if (!$value || !Validator::isNumber($value)) {
            $this->showError($error ?? _('Invalid or unexpected input given.'));
        }

        return (int)$value;
    }

    /**
     * Answers with the standard 404 page, for routes whose feature is switched off.
     */
    protected function renderNotFound(): void
    {
        http_response_code(404);
        $this->render('404.html', ['title' => _('Page Not Found')]);
    }

    /**
     * Displays an error message.
     *
     * @param string $error The error message to display.
     * @param string|null $recordName Optional record name for context
     */
    public function showError(string $error, ?string $recordName = null): void
    {
        // Format the error with record name if provided
        if ($recordName !== null) {
            $error = sprintf('%s (Record: %s)', $error, $recordName);
        }

        // Check if this request expects JSON
        if (RequestContext::expectsJson()) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'error' => true,
                'message' => $error
            ]);
            exit;
        }

        // Add as system message
        $this->addSystemMessage('error', $error);

        // Render the page with the message
        $systemMessages = $this->messageService->getMessages('system');
        $this->renderHeader($systemMessages);
        $this->renderFooter();
        exit;
    }

    /**
     * Lazily builds the Twig environment and translator. Both consumers are
     * presentation paths, so API controllers never pay for the template stack.
     */
    private function app(): AppManager
    {
        return $this->app ??= new AppManager($this->logger);
    }

    /**
     * Lazily builds the page chrome renderer. Deferred closures keep
     * permission, capability, and debug-query lookups out of controller
     * construction, and API controllers never build the renderer at all.
     */
    private function getPageRenderer(): PageRenderer
    {
        $userId = $this->userContextService->getLoggedInUserId();

        return $this->pageRenderer ??= new PageRenderer(
            $this->app(),
            $this->config,
            $this->csrfTokenService,
            $this->userContextService,
            $this->moduleRegistry(),
            DnsBackendProviderFactory::isApiBackend($this->config),
            $this->hasPermission(...),
            static fn(): ?array => PdnsVersionService::getCachedInfo($_SESSION ?? []),
            fn(): array => $this->init?->getDebugQueries() ?? [],
            $userId !== null && $this->services()->userPreferenceService()->getWideLayout($userId),
            fn(): int => $this->pendingChangeRequestCount()
        );
    }

    /**
     * Renders the header of the page.
     *
     * @param array|null $systemMessages System messages to be displayed
     */
    private function renderHeader(?array $systemMessages = null, ?array $scriptMessages = null): void
    {
        $this->getPageRenderer()->renderHeader($this->requestData, $this->pageTitle, $systemMessages, $scriptMessages);
    }

    /**
     * Renders the footer of the page.
     */
    private function renderFooter(): void
    {
        $this->getPageRenderer()->renderFooter();
    }

    /**
     * Gets the request data.
     *
     * @return array The request data.
     */
    public function getRequest(): array
    {
        return $this->requestData;
    }

    /**
     * Gets a safe value from the request data.
     *
     * @param string $key The key to get the value for.
     * @return string The safe value.
     */
    public function getSafeRequestValue(string $key): string
    {
        if (!array_key_exists($key, $this->requestData)) {
            return '';
        }

        // Any key can arrive as an array (?id[]=1), which htmlspecialchars rejects
        $value = $this->requestData[$key];
        if (!is_scalar($value)) {
            return '';
        }

        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    /**
     * Lazily builds the request validator: only the minority of controllers
     * that declare validation rules pay for the Symfony validator.
     */
    private function validator(): RequestValidator
    {
        return $this->requestValidator ??= new RequestValidator();
    }

    /**
     * Sets validation constraints for the request data.
     *
     * @param array $constraints The validation constraints.
     */
    public function setValidationConstraints(array $constraints): void
    {
        $this->validator()->setConstraints($constraints);
    }

    /**
     * Validates the request data.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->requestData
     * @return bool True if the request data is valid, false otherwise.
     */
    public function doValidateRequest(?array $data = null): bool
    {
        return $this->validator()->validate($data ?? $this->requestData)->count() === 0;
    }

    /**
     * Displays the first validation error.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->requestData
     */
    public function showFirstValidationError(?array $data = null): void
    {
        $errorMessage = $this->validator()->firstErrorMessage($data ?? $this->requestData);

        if ($errorMessage !== null) {
            $this->showError($errorMessage);
        }
    }

    /**
     * Adds a system-wide message that will be displayed on any page
     *
     * @param string $type The type of message (error, warning, success, info)
     * @param string $content The content of the message
     */
    public function addSystemMessage(string $type, string $content): void
    {
        $this->messageService->addMessage('system', $type, $content);
    }

    /**
     * Sends a redirect to the given URL.
     *
     * @param string $url The URL to redirect to.
     */
    private function sendRedirect(string $url): void
    {
        $sanitizeUrl = filter_var($url, FILTER_SANITIZE_URL);
        header("Location: $sanitizeUrl");
        exit;
    }
}
