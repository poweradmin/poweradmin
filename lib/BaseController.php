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

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\ThemeManager;
use Valitron;

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
    private Valitron\Validator $validator;
    private CsrfTokenService $csrfTokenService;

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
        $this->validator = new Valitron\Validator($this->getRequest());

        $this->csrfTokenService = new CsrfTokenService();
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
     * @return AppConfiguration The application configuration.
     */
    public function getConfig(): AppConfiguration
    {
        return $this->app->getConfig();
    }

    /**
     * Gets a specific configuration value.
     *
     * @param string $key The configuration key.
     * @return mixed The configuration value.
     */
    public function config(string $key): mixed
    {
        return $this->app->config($key);
    }

    /**
     * Renders a template with the given parameters.
     *
     * @param string $template The template to render.
     * @param array $params The parameters to pass to the template.
     */
    public function render(string $template, array $params): void
    {
        $this->renderHeader();
        $this->showMessage($template);
        $params['csrf_token'] = $this->csrfTokenService->getToken();
        $this->app->render($template, $params);
        $this->renderFooter();
    }

    /**
     * Validates the CSRF token from the request.
     */
    public function validateCsrfToken(): void
    {
        if (!$this->app->config('global_token_validation', true)) {
            return;
        }

        $token = $this->getSafeRequestValue('_token');
        if (!$this->csrfTokenService->validateToken($token)) {
            $error = new ErrorMessage(_('Invalid CSRF token.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
        if (!isset($_SESSION['messages'][$script])) {
            $_SESSION['messages'][$script] = [];
        }
        $_SESSION['messages'][$script][] = [
            'type' => $type,
            'content' => $content
        ];
    }

    /**
     * Gets messages for a specific script.
     *
     * @param string $script The script to get messages for.
     * @return mixed The messages for the script, or null if no messages are set.
     */
    public function getMessages(string $script): mixed
    {
        if (isset($_SESSION['messages'][$script])) {
            $messages = $_SESSION['messages'][$script];
            unset($_SESSION['messages'][$script]);
            return $messages;
        }
        return null;
    }

    /**
     * Displays messages for a specific template.
     *
     * @param string $template The template to display messages for.
     */
    public function showMessage(string $template): void
    {
        $script = pathinfo($template)['filename'];

        $messages = $this->getMessages($script);
        if ($messages) {
            foreach ($messages as $message) {
                $alertClass = match ($message['type']) {
                    'error' => 'alert-danger',
                    'warn' => 'alert-warning',
                    'success' => 'alert-success',
                    'info' => 'alert-info',
                    default => '',
                };

                echo <<<EOF
<div class="alert $alertClass alert-dismissible fade show" role="alert" data-testid="alert-message">{$message['content']}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
EOF;
            }
        }
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
            $error = new ErrorMessage($errorMessage);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
            $error = new ErrorMessage($errorMessage);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            exit;
        }
    }

    /**
     * Displays an error message.
     *
     * @param string $error The error message to display.
     */
    public function showError(string $error): void
    {
        $this->renderHeader();

        $error = new ErrorMessage($error);
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

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
        $this->renderHeader();

        $validationErrors = array_values($errors);
        $firstError = reset($validationErrors);

        $error = new ErrorMessage($firstError[0]);
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        $this->renderFooter();
        exit;
    }

    /**
     * Renders the header of the page.
     */
    private function renderHeader(): void
    {
        if (!headers_sent()) {
            header('Content-type: text/html; charset=utf-8');
        }

        $style = $this->app->config('iface_style');
        $themeManager = new ThemeManager($style);

        $vars = [
            'iface_title' => $this->app->config('iface_title'),
            'iface_style' => $themeManager->getSelectedTheme(),
            'file_version' => time(),
            'custom_header' => file_exists('templates/custom/header.html'),
            'install_error' => file_exists('install') ? _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.') : false,
        ];

        $dblog_use = $this->app->config('dblog_use');
        $session_key = $this->app->config('session_key');

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
                'dblog_use' => $dblog_use
            ]);
        }

        $this->app->render('header.html', $vars);
    }

    /**
     * Renders the footer of the page.
     */
    private function renderFooter(): void
    {
        $style = $this->app->config('iface_style');
        $themeManager = new ThemeManager($style);
        $selected_theme = $themeManager->getSelectedTheme();

        $display_stats = $this->app->config('display_stats');

        $this->app->render('footer.html', [
            'version' => isset($_SESSION["userid"]) ? Version::VERSION : false,
            'custom_footer' => file_exists('templates/custom/footer.html'),
            'display_stats' => $display_stats ? $this->app->displayStats() : false,
            'db_queries' => $this->app->config('db_debug') ? $this->db->getQueries() : false, // FIXME
            'show_theme_switcher' => in_array($selected_theme, ['ignite', 'spark']),
            'iface_style' => $selected_theme,
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
     * Sets validation rules for the request data.
     *
     * @param array $rules The validation rules.
     */
    public function setRequestRules(array $rules): void
    {
        $this->validator->rules($rules);
    }

    /**
     * Validates the request data.
     *
     * @return bool True if the request data is valid, false otherwise.
     */
    public function doValidateRequest(): bool
    {
        return $this->validator->validate();
    }

    /**
     * Displays the first validation error.
     */
    public function showFirstValidationError(): void
    {
        $this->showFirstError($this->validator->errors());
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
}
