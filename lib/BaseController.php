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
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\StyleManager;
use Symfony\Component\Validator\Constraints as Assert;
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
    protected PDOLayer $db;
    private array $request;
    private ValidatorInterface $validator;
    private array $validationConstraints = [];
    private CsrfTokenService $csrfTokenService;
    private MessageService $messageService;
    protected ConfigurationManager $config;

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
        $this->app = AppFactory::create();

        $this->init = new AppInitializer($authenticate);
        $this->db = $this->init->getDb();

        $this->request = $request;
        $this->validator = Validation::createValidator();

        $this->config = ConfigurationManager::getInstance();
        $this->csrfTokenService = new CsrfTokenService();
        $this->messageService = new MessageService();
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
        $params['csrf_token'] = $this->csrfTokenService->getToken();
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
     * Redirects to a specified script with optional arguments.
     *
     * @param string $script The script to redirect to.
     * @param array $args The arguments to pass to the script.
     */
    public function redirect(string $script, array $args = []): void
    {
        $url = $this->buildUrl($script, $args);
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
     * @return mixed The messages for the script, or null if no messages are set.
     */
    public function getMessages(string $script): mixed
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
     * Checks if the user has a specific permission and displays an error message if not.
     *
     * @param string $permission The permission to check.
     * @param string $errorMessage The error message to display if the user does not have the permission.
     */
    public function checkPermission(string $permission, string $errorMessage): void
    {
        if (!UserManager::verify_permission($this->db, $permission)) {
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

        // Add as system message
        $this->addSystemMessage('error', $error);

        // Render the page with the message
        $systemMessages = $this->messageService->getMessages('system');
        $this->renderHeader($systemMessages);
        $this->renderFooter();
        exit;
    }

    /**
     * Displays the first error from an array of errors.
     *
     * @param array $errors The array of errors.
     */
    public function showFirstError(array $errors): void
    {
        $validationErrors = array_values($errors);
        $firstError = reset($validationErrors);

        // Add as system message so it appears in the right place
        $this->addSystemMessage('error', $firstError[0]);

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

        $vars = [
            'iface_title' => $this->config->get('interface', 'title'),
            'iface_style' => $styleManager->getSelectedStyle(),
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
            'file_version' => time(),
            'custom_header' => file_exists($this->config->get('interface', 'theme_base_path', 'templates') . '/' . $this->config->get('interface', 'theme', 'default') . '/custom/header.html'),
            'install_error' => file_exists('install') ? _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.') : false,
        ];

        $dblog_use = $this->config->get('logging', 'database_enabled');
        $session_key = $this->config->get('security', 'session_key');

        if (isset($_SESSION["userid"])) {
            $perm_is_godlike = UserManager::verify_permission($this->db, 'user_is_ueberuser');

            $vars = array_merge($vars, [
                'user_logged_in' => isset($_SESSION["userid"]),
                'perm_search' => UserManager::verify_permission($this->db, 'search'),
                'perm_view_zone_own' => UserManager::verify_permission($this->db, 'zone_content_view_own'),
                'perm_view_zone_other' => UserManager::verify_permission($this->db, 'zone_content_view_others'),
                'perm_supermaster_view' => UserManager::verify_permission($this->db, 'supermaster_view'),
                'perm_zone_master_add' => UserManager::verify_permission($this->db, 'zone_master_add'),
                'perm_zone_slave_add' => UserManager::verify_permission($this->db, 'zone_slave_add'),
                'perm_supermaster_add' => UserManager::verify_permission($this->db, 'supermaster_add'),
                'perm_is_godlike' => $perm_is_godlike,
                'perm_templ_perm_edit' => UserManager::verify_permission($this->db, 'templ_perm_edit'),
                'perm_add_new' => UserManager::verify_permission($this->db, 'user_add_new'),
                'session_key_error' => $perm_is_godlike && $session_key == 'p0w3r4dm1n' ? _('Default session encryption key is used, please set it in your configuration file.') : false,
                'auth_used' => $_SESSION["auth_used"] != "ldap",
                'dblog_use' => $dblog_use,
                'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
                'whois_enabled' => $this->config->get('whois', 'enabled', false),
                'rdap_enabled' => $this->config->get('rdap', 'enabled', false),
                'whois_restrict_to_admin' => $this->config->get('whois', 'restrict_to_admin', true),
                'rdap_restrict_to_admin' => $this->config->get('rdap', 'restrict_to_admin', true)
            ]);
        }

        // Add system messages to header template variables
        if ($systemMessages) {
            $vars['system_messages'] = $systemMessages;
        }

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
            'version' => isset($_SESSION["userid"]) ? Version::VERSION : false,
            'custom_footer' => file_exists($this->config->get('interface', 'theme_base_path', 'templates') . '/' . $this->config->get('interface', 'theme', 'default') . '/custom/footer.html'),
            'display_stats' => $display_stats ? $this->app->displayStats() : false,
            'db_queries' => $db_debug ? $this->db->getQueries() : false,
            'show_style_switcher' => in_array($selected_style, ['light', 'dark']),
            'iface_style' => $selected_style,
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
        ]);

        $this->db->disconnect();
    }

    /**
     * Gets the request data.
     *
     * @return array The request data.
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * Gets a safe value from the request data.
     *
     * @param string $key The key to get the value for.
     * @return string The safe value.
     */
    public function getSafeRequestValue(string $key): string
    {
        if (!array_key_exists($key, $this->request)) {
            return '';
        }

        return htmlspecialchars($this->request[$key], ENT_QUOTES);
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
     * Validates the request data.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->request
     * @return bool True if the request data is valid, false otherwise.
     */
    public function doValidateRequest(?array $data = null): bool
    {
        $dataToValidate = $data ?? $this->request;

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
        $violations = $this->validator->validate($dataToValidate, $collectionConstraint);

        return $violations->count() === 0;
    }

    /**
     * Displays the first validation error.
     *
     * @param array|null $data Optional data to validate. If not provided, uses $this->request
     */
    public function showFirstValidationError(?array $data = null): void
    {
        $dataToValidate = $data ?? $this->request;

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
        $violations = $this->validator->validate($dataToValidate, $collectionConstraint);

        if ($violations->count() > 0) {
            $firstViolation = $violations->get(0);
            $errorMessage = $firstViolation->getMessage();
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
        if (isset($parsedUrl['host']) && !empty($allowedHosts) && !in_array($parsedUrl['host'], $allowedHosts)) {
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
