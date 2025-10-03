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

namespace Poweradmin;

use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\UserAvatarService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserPreferenceRepository;
use Poweradmin\Infrastructure\Service\ApiKeyAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\StyleManager;
use Poweradmin\Version;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abstract class BaseController
 *
 * Provides common functionality for all controllers in the Poweradmin application.
 */
abstract class BaseController
{
    private AppManager $app;
    private AppInitializer $init;
    protected PDOCommon $db;
    protected array $requestData;
    private ValidatorInterface $validator;
    private array $validationConstraints = [];
    private CsrfTokenService $csrfTokenService;
    protected MessageService $messageService;
    protected ConfigurationManager $config;
    private UserContextService $userContextService;

    /**
     * Abstract method to be implemented by subclasses to run the controller logic.
     */
    abstract public function run(): void;

    /**
     * Constructor for BaseController.
     *
     * @param array $request The request data.
     * @param bool $authenticate Whether to authenticate the user.
     */
    public function __construct(array $request, bool $authenticate = true)
    {
        $this->app = new AppManager();

        $this->init = new AppInitializer($authenticate);
        $this->db = $this->init->getDb();

        $this->requestData = $request;
        $this->validator = Validation::createValidator();

        $this->config = ConfigurationManager::getInstance();
        $this->csrfTokenService = new CsrfTokenService();
        $this->messageService = new MessageService();
        $this->userContextService = new UserContextService();

        // If we're in an API context and the user is not authenticated,
        // check for API key authentication (but only for internal API routes)
        if ($authenticate && !$this->userContextService->isAuthenticated() && $this->isInternalApiRoute()) {
            $this->tryApiKeyAuthentication();
        }

        // Check for MFA requirement for regular controllers using our centralized manager
        if ($authenticate && !$this->isApiRequest() && $this->userContextService->isAuthenticated()) {
            $currentPage = $request['page'] ?? '';

            // Use our centralized MFA session manager to check if verification is required
            if (MfaSessionManager::isMfaRequired() && $currentPage !== 'mfa_verify') {
                // Ensure session is written before redirecting
                session_write_close();

                // Build redirect URL with base_url_prefix support
                $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
                $redirectUrl = $baseUrlPrefix . '/mfa/verify';
                header("Location: $redirectUrl");
                exit;
            }
        }
    }

    /**
     * Checks if the current request is any API route
     *
     * @return bool True if this is an API request, false otherwise
     */
    protected function isApiRequest(): bool
    {
        $page = $this->requestData['page'] ?? '';
        return str_starts_with($page, 'api/');
    }

    /**
     * Checks if the current request expects a JSON response
     * This is more comprehensive than just checking the route
     *
     * @return bool True if this request expects JSON, false otherwise
     */
    public static function expectsJson(): bool
    {
        // Check if it's an API route
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($requestUri, '/api/')) {
            return true;
        }

        // Check Accept header
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($acceptHeader, 'application/json') && !str_contains($acceptHeader, 'text/html')) {
            return true;
        }

        // Check if it's an AJAX request
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            return true;
        }

        // Check Content-Type for JSON requests
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the current request is an internal API route (api/internal/*)
     *
     * @return bool True if this is an internal API route, false otherwise
     */
    protected function isInternalApiRoute(): bool
    {
        $page = $this->requestData['page'] ?? '';
        return str_starts_with($page, 'api/internal/');
    }

    /**
     * Checks if the current request is a public API route (api/v1/*, api/v2/*, etc.)
     *
     * @return bool True if this is a public API route, false otherwise
     */
    protected function isPublicApiRoute(): bool
    {
        $page = $this->requestData['page'] ?? '';

        // Check if this is an API route
        if (!str_starts_with($page, 'api/')) {
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

    /**
     * Tries to authenticate using API key
     * Only used for internal API routes by default
     */
    protected function tryApiKeyAuthentication(): void
    {
        // Check if API functionality is enabled (which includes API keys)
        if (!$this->config->get('api', 'enabled', false)) {
            return;
        }

        // Create API key middleware
        $middleware = new ApiKeyAuthenticationMiddleware($this->db, $this->config);

        // Create request object from globals
        $request = Request::createFromGlobals();

        // Try to authenticate
        $middleware->process($request);
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
     * @return ConfigurationManager The application configuration.
     */
    public function getConfig(): ConfigurationManager
    {
        return $this->config;
    }


    /**
     * Renders a template with the given parameters.
     *
     * @param string $template The template to render.
     * @param array $params The parameters to pass to the template.
     */
    public function render(string $template, array $params): void
    {
        // Get system messages before rendering
        $systemMessages = $this->messageService->getMessages('system');

        // Pass system messages to header template
        $this->renderHeader($systemMessages);

        // Show template-specific messages
        $this->showMessage($template);

        // Render main template
        // Ensure CSRF token exists, generate one if missing
        $this->csrfTokenService->ensureTokenExists();
        $params['csrf_token'] = $this->csrfTokenService->getToken();

        // Add base_url_prefix for subfolder deployment support
        $params['base_url_prefix'] = $this->config->get('interface', 'base_url_prefix', '');

        $this->app->render($template, $params);
        $this->renderFooter();
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
            throw new \InvalidArgumentException("URL must start with '/'. Got: $url");
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
     * @param string $type The type of message (error, warn, success, info).
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
     * Displays messages for a specific template.
     *
     * @param string $template The template to display messages for.
     */
    public function showMessage(string $template): void
    {
        echo $this->messageService->renderMessages($template);
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
     * Create UserPreferenceService instance
     *
     * @return UserPreferenceService
     */
    protected function createUserPreferenceService(): UserPreferenceService
    {
        $db_type = $this->config->get('database', 'type');
        $repository = new DbUserPreferenceRepository($this->db, $db_type);
        return new UserPreferenceService($repository, $this->config);
    }

    /**
     * Create PaginationService with user preferences support
     *
     * @return PaginationService
     */
    protected function createPaginationService(): PaginationService
    {
        $userPreferenceService = $this->createUserPreferenceService();
        return new PaginationService($userPreferenceService);
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
     * Get the user context service
     *
     * @return UserContextService
     */
    protected function getUserContextService(): UserContextService
    {
        return $this->userContextService;
    }

    /**
     * Checks if the user has a specific permission and displays an error message if not.
     *
     * @param string $permission The permission to check.
     * @param string $errorMessage The error message to display if the user does not have the permission.
     */
    public function checkPermission(string $permission, string $errorMessage): void
    {
        if (!UserManager::verifyPermission($this->db, $permission)) {
            // Check if this request expects JSON
            if (self::expectsJson()) {
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
        if (self::expectsJson()) {
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
     * Renders the header of the page.
     *
     * @param array|null $systemMessages System messages to be displayed
     */
    private function renderHeader(?array $systemMessages = null): void
    {
        if (!headers_sent()) {
            header('Content-type: text/html; charset=utf-8');
        }

        $style = $this->config->get('interface', 'style', 'light');
        $themeBasePath = $this->config->get('interface', 'theme_base_path', 'templates');
        $theme = $this->config->get('interface', 'theme', 'default');
        $styleManager = new StyleManager($style, $themeBasePath, $theme);

        // Check for custom theme stylesheets
        $customLightExists = file_exists($themeBasePath . '/' . $theme . '/style/custom_light.css');
        $customDarkExists = file_exists($themeBasePath . '/' . $theme . '/style/custom_dark.css');
        $customThemeExists = file_exists($themeBasePath . '/' . $theme . '/style/custom_' . $styleManager->getSelectedStyle() . '.css');

        $vars = [
            'iface_title' => $this->config->get('interface', 'title'),
            'iface_style' => $styleManager->getSelectedStyle(),
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
            'base_url_prefix' => $this->config->get('interface', 'base_url_prefix', ''),
            'file_version' => time(),
            'custom_header' => file_exists($this->config->get('interface', 'theme_base_path', 'templates') . '/' . $this->config->get('interface', 'theme', 'default') . '/custom/header.html'),
            'custom_light_exists' => $customLightExists,
            'custom_dark_exists' => $customDarkExists,
            'custom_theme_exists' => $customThemeExists,
            'install_error' => file_exists('install') ? _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.') : false,
            'version' => Version::VERSION,
            'show_style_switcher' => true,
        ];

        $dblog_use = $this->config->get('logging', 'database_enabled');
        $session_key = $this->config->get('security', 'session_key');

        if ($this->userContextService->isAuthenticated()) {
            $perm_is_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

            $vars = array_merge($vars, [
                'user_logged_in' => $this->userContextService->isAuthenticated(),
                'user_name' => $this->userContextService->getDisplayName(),
                'perm_search' => UserManager::verifyPermission($this->db, 'search'),
                'perm_view_zone_own' => UserManager::verifyPermission($this->db, 'zone_content_view_own'),
                'perm_view_zone_other' => UserManager::verifyPermission($this->db, 'zone_content_view_others'),
                'perm_supermaster_view' => UserManager::verifyPermission($this->db, 'supermaster_view'),
                'perm_zone_master_add' => UserManager::verifyPermission($this->db, 'zone_master_add'),
                'perm_zone_slave_add' => UserManager::verifyPermission($this->db, 'zone_slave_add'),
                'perm_zone_templ_add' => UserManager::verifyPermission($this->db, 'zone_templ_add'),
                'perm_zone_templ_edit' => UserManager::verifyPermission($this->db, 'zone_templ_edit'),
                'perm_supermaster_add' => UserManager::verifyPermission($this->db, 'supermaster_add'),
                'perm_is_godlike' => $perm_is_godlike,
                'perm_templ_perm_edit' => UserManager::verifyPermission($this->db, 'templ_perm_edit'),
                'perm_add_new' => UserManager::verifyPermission($this->db, 'user_add_new'),
                'perm_view_others' => UserManager::verifyPermission($this->db, 'user_view_others'),
                'perm_edit_own' => UserManager::verifyPermission($this->db, 'user_edit_own'),
                'perm_edit_others' => UserManager::verifyPermission($this->db, 'user_edit_others'),
                'session_key_error' => $perm_is_godlike && $session_key == 'p0w3r4dm1n' ? _('Default session encryption key is used, please set it in your configuration file.') : false,
                'auth_used' => $this->userContextService->getAuthMethod() !== "ldap",  // Legacy variable for backward compatibility
                'auth_method' => $this->userContextService->getAuthMethod() ?? 'internal',
                'can_change_password' => !in_array($this->userContextService->getAuthMethod(), ['ldap', 'oidc', 'saml']),
                'session_userid' => $this->userContextService->getLoggedInUserId() ?? 0,
                'user_avatar_url' => $this->getUserAvatarUrl(),
                'request' => $this->requestData,
                'dblog_use' => $dblog_use,
                'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
                'whois_enabled' => $this->config->get('whois', 'enabled', false),
                'rdap_enabled' => $this->config->get('rdap', 'enabled', false),
                'api_enabled' => $this->config->get('api', 'enabled', false),
                'mfa_enabled' => $this->config->get('security', 'mfa.enabled', false),
                'whois_restrict_to_admin' => $this->config->get('whois', 'restrict_to_admin', true),
                'rdap_restrict_to_admin' => $this->config->get('rdap', 'restrict_to_admin', true),
                'enable_consistency_checks' => $this->config->get('interface', 'enable_consistency_checks', false),
                'email_previews_enabled' => $this->config->get('misc', 'email_previews_enabled', false),
                'api_docs_enabled' => $this->config->get('api', 'docs_enabled', false)
            ]);
        }

        // Add system messages to header template variables
        if ($systemMessages) {
            $vars['system_messages'] = $systemMessages;
        }

        // Add the current page to the header variables
        $currentPage = $this->requestData['page'] ?? 'index';
        $vars['current_page'] = $currentPage;

        $this->app->render('header.html', $vars);
    }

    /**
     * Renders the footer of the page.
     */
    private function renderFooter(): void
    {
        $style = $this->config->get('interface', 'style', 'light');
        $themeBasePath = $this->config->get('interface', 'theme_base_path', 'templates');
        $theme = $this->config->get('interface', 'theme', 'default');
        $styleManager = new StyleManager($style, $themeBasePath, $theme);
        $selected_style = $styleManager->getSelectedStyle();

        $display_stats = $this->config->get('misc', 'display_stats');
        $db_debug = $this->config->get('database', 'debug');

        $this->app->render('footer.html', [
            'version' => $this->userContextService->isAuthenticated() ? Version::VERSION : false,
            'custom_footer' => file_exists($this->config->get('interface', 'theme_base_path', 'templates') . '/' . $this->config->get('interface', 'theme', 'default') . '/custom/footer.html'),
            'display_stats' => $display_stats ? $this->app->displayStats() : false,
            'db_queries' => $db_debug ? $this->db->getQueries() : false,
            'show_style_switcher' => in_array($selected_style, ['light', 'dark']),
            'iface_style' => $selected_style,
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
            'base_url_prefix' => $this->config->get('interface', 'base_url_prefix', ''),
            'user_logged_in' => $this->userContextService->isAuthenticated(),
        ]);
    }

    /**
     * Gets the user's avatar URL if avatar functionality is enabled
     *
     * @return string|null The avatar URL or null if not available/enabled
     */
    private function getUserAvatarUrl(): ?string
    {
        $userAvatarService = new UserAvatarService($this->userContextService, $this->config);
        return $userAvatarService->getCurrentUserAvatarUrl();
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

        return htmlspecialchars($this->requestData[$key], ENT_QUOTES);
    }

    /**
     * Sets validation constraints for the request data.
     *
     * @param array $constraints The validation constraints.
     */
    public function setValidationConstraints(array $constraints): void
    {
        $this->validationConstraints = $constraints;
    }

    /**
     * Sets validation rules for the request data.
     *
     * @param array $rules The validation rules.
     */
    public function setRequestRules(array $rules): void
    {
        $constraints = [];

        // Convert rules to Symfony validator constraints
        if (isset($rules['required'])) {
            foreach ($rules['required'] as $field) {
                $constraints[$field] = new Assert\NotBlank(['message' => sprintf(_('The %s field is required.'), $field)]);
            }
        }

        if (isset($rules['integer'])) {
            foreach ($rules['integer'] as $field) {
                $constraints[$field] = new Assert\Type([
                    'type' => 'numeric',
                    'message' => sprintf(_('The %s field must be a number.'), $field)
                ]);
            }
        }

        $this->validationConstraints = $constraints;
    }

    /**
     * Validates data and returns constraint violations.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->requestData
     * @return ConstraintViolationListInterface
     */
    private function validateData(?array $data = null): ConstraintViolationListInterface
    {
        $dataToValidate = $data ?? $this->requestData;

        // Filter input data to remove empty values to prevent type errors
        foreach ($dataToValidate as $key => $value) {
            if ($value === '') {
                unset($dataToValidate[$key]);
            }
        }

        $collectionConstraint = new Assert\Collection([
            'fields' => $this->validationConstraints,
            'allowExtraFields' => true,
            'allowMissingFields' => true
        ]);

        return $this->validator->validate($dataToValidate, $collectionConstraint);
    }

    /**
     * Validates the request data.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->requestData
     * @return bool True if the request data is valid, false otherwise.
     */
    public function doValidateRequest(?array $data = null): bool
    {
        $violations = $this->validateData($data);
        return $violations->count() === 0;
    }

    /**
     * Displays the first validation error.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->requestData
     */
    public function showFirstValidationError(?array $data = null): void
    {
        $violations = $this->validateData($data);

        if ($violations->count() > 0) {
            $firstViolation = $violations->get(0);
            $errorMessage = (string) $firstViolation->getMessage();
            $this->showError($errorMessage);
        }
    }

    /**
     * Adds a system-wide message that will be displayed on any page
     *
     * @param string $type The type of message (error, warn, success, info)
     * @param string $content The content of the message
     */
    public function addSystemMessage(string $type, string $content): void
    {
        $this->messageService->addMessage('system', $type, $content);
    }

    /**
     * Builds a URL with the given script and arguments.
     *
     * @param string $script The script to build the URL for.
     * @param mixed $args The arguments to include in the URL.
     * @return string The built URL.
     */
    private function buildUrl(string $script, mixed $args): string
    {
        $parsedUrl = parse_url($script);
        $existingQueryParams = $this->parseQueryParams($parsedUrl);

        $args['time'] = time();
        $queryParams = array_merge($existingQueryParams, $args);

        $queryString = http_build_query($queryParams);

        if (isset($parsedUrl['query'])) {
            return $script . "&" . $queryString;
        } else {
            return $script . "?" . $queryString;
        }
    }

    /**
     * Parses query parameters from a URL.
     *
     * @param array $parsedUrl The parsed URL.
     * @return array The query parameters.
     */
    private function parseQueryParams(array $parsedUrl): array
    {
        $existingQueryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingQueryParams);
        }
        return $existingQueryParams;
    }

    /**
     * Sends a redirect to the given URL.
     *
     * @param string $url The URL to redirect to.
     */
    private function sendRedirect(string $url): void
    {
        # TODO: read hosts from config
        $allowedHosts = [];

        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['host']) && is_array($allowedHosts) && count($allowedHosts) > 0 && !in_array($parsedUrl['host'], $allowedHosts)) {
            $url = '/';
        }

        $sanitizeUrl = filter_var($url, FILTER_SANITIZE_URL);
        header("Location: $sanitizeUrl");
        exit;
    }

    /**
     * Gets system errors from the MessageService
     *
     * @return array System errors
     */
    public function getSystemErrors(): array
    {
        $messages = $this->messageService->getMessages('system');
        if ($messages) {
            $errors = [];
            foreach ($messages as $message) {
                if ($message['type'] === 'error') {
                    $errors[] = $message['content'];
                }
            }
            return $errors;
        }
        return [];
    }
}
