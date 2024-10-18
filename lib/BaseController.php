<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

abstract class BaseController
{
    private AppManager $app;
    private AppInitializer $init;
    protected PDOLayer $db;
    private array $request;
    private Valitron\Validator $validator;
    private CsrfTokenService $csrfTokenService;

    abstract public function run(): void;

    public function __construct(array $request, bool $authenticate = true)
    {
        $this->app = AppFactory::create();

        $this->init = new AppInitializer($authenticate);
        $this->db = $this->init->getDb();

        $this->request = $request;
        $this->validator = new Valitron\Validator($this->getRequest());

        $this->csrfTokenService = new CsrfTokenService();
    }

    public function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    public function getConfig(): AppConfiguration
    {
        return $this->app->getConfig();
    }

    public function config(string $key): mixed
    {
        return $this->app->config($key);
    }

    public function render(string $template, array $params): void
    {
        $this->renderHeader();
        $this->showMessage($template);
        $params['csrf_token'] = $this->csrfTokenService->getToken();
        $this->app->render($template, $params);
        $this->renderFooter();
    }

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

    public function redirect($script, $args = []): void
    {
        $url = $this->buildUrl($script, $args);
        $this->sendRedirect($url);
    }

    public function setMessage($script, $type, $content): void
    {
        $_SESSION['messages'][$script] = [
            'type' => $type,
            'content' => $content
        ];
    }

    public function getMessage($script): mixed
    {
        if (isset($_SESSION['messages'][$script])) {
            $messages = $_SESSION['messages'][$script];
            unset($_SESSION['messages'][$script]);
            return $messages;
        }
        return null;
    }

    public function showMessage($template): void
    {
        $script = pathinfo($template)['filename'];

        $message = $this->getMessage($script);
        if ($message) {
            $alertClass = match ($message['type']) {
                'error' => 'alert-danger',
                'warn' => 'alert-warning',
                'success' => 'alert-success',
                'info' => 'alert-info',
                default => '',
            };

            echo <<<EOF
<div class="alert $alertClass alert-dismissible fade show" role="alert">{$message['content']}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
EOF;
        }
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            $error = new ErrorMessage($errorMessage);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            exit;
        }
    }

    public function checkPermission(string $permission, string $errorMessage): void
    {
        if (!UserManager::verify_permission($this->db, $permission)) {
            $error = new ErrorMessage($errorMessage);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            exit;
        }
    }

    public function showError(string $error): void
    {
        $this->renderHeader();

        $error = new ErrorMessage($error);
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        $this->renderFooter();
        exit;
    }

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

    private function renderHeader(): void
    {
        if (!headers_sent()) {
            header('Content-type: text/html; charset=utf-8');
        }

        $themeManager = new ThemeManager($this->app->config('iface_style'));

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
            $perm_is_godlike = UserManager::verify_permission($this->db,'user_is_ueberuser');

            $vars = array_merge($vars, [
                'user_logged_in' => isset($_SESSION["userid"]),
                'perm_search' => UserManager::verify_permission($this->db,'search'),
                'perm_view_zone_own' => UserManager::verify_permission($this->db,'zone_content_view_own'),
                'perm_view_zone_other' => UserManager::verify_permission($this->db,'zone_content_view_others'),
                'perm_supermaster_view' => UserManager::verify_permission($this->db,'supermaster_view'),
                'perm_zone_master_add' => UserManager::verify_permission($this->db,'zone_master_add'),
                'perm_zone_slave_add' => UserManager::verify_permission($this->db,'zone_slave_add'),
                'perm_supermaster_add' => UserManager::verify_permission($this->db,'supermaster_add'),
                'perm_is_godlike' => $perm_is_godlike,
                'perm_templ_perm_edit' => UserManager::verify_permission($this->db,'templ_perm_edit'),
                'perm_add_new' => UserManager::verify_permission($this->db,'user_add_new'),
                'session_key_error' => $perm_is_godlike && $session_key == 'p0w3r4dm1n' ? _('Default session encryption key is used, please set it in your configuration file.') : false,
                'auth_used' => $_SESSION["auth_used"] != "ldap",
                'dblog_use' => $dblog_use
            ]);
        }

        $this->app->render('header.html', $vars);
    }

    private function renderFooter(): void
    {
        $iface_style = $this->app->config('iface_style');
        $themeManager = new ThemeManager($iface_style);
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

    public function getRequest(): array
    {
        return $this->request;
    }

    public function getSafeRequestValue(string $key): string
    {
        if (!array_key_exists($key, $this->request)) {
            return '';
        }

        return htmlspecialchars($this->request[$key], ENT_QUOTES);
    }

    public function setRequestRules(array $rules): void
    {
        $this->validator->rules($rules);
    }

    public function doValidateRequest(): bool
    {
        return $this->validator->validate();
    }

    public function showFirstValidationError(): void {
        $this->showFirstError($this->validator->errors());
    }

    private function buildUrl($script, mixed $args): string
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

    private function parseQueryParams(array $parsedUrl): array
    {
        $existingQueryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingQueryParams);
        }
        return $existingQueryParams;
    }

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

    private function sanitizeQueryParams(array $queryParams): array
    {
        $params = [];

        $allowedParams = [
            'id' => FILTER_VALIDATE_INT,
            'confirm' => fn($value) => $value === '1' ? '1' : null,
            'domain_id' => FILTER_VALIDATE_INT,
            'key_id' => FILTER_VALIDATE_INT,
            'letter' => fn($value) => preg_match('/^(?:[0-9]|[a-z]|all)$/i', $value) ? $value : null,
            'master_ip' => FILTER_VALIDATE_IP,
            'ns_name' => FILTER_VALIDATE_DOMAIN,
            'record_sort_by' => fn($value) => in_array($value, ['id', 'name', 'type', 'content', 'prio', 'ttl', 'disabled'], true) ? $value : null,
            'start' => FILTER_VALIDATE_INT,
            'page' => fn($value) => ($value && $value !== 'switch_theme' && in_array($value, Pages::GetPages())) ? $value : null,
            'zone_sort_by' => fn($value) => in_array($value, ['name', 'type', 'count_records', 'owner'], true) ? $value : null,
            'zone_templ_id' => FILTER_VALIDATE_INT,
        ];

        foreach ($allowedParams as $param => $rule) {
            if (isset($queryParams[$param])) {
                $value = $queryParams[$param];
                $validValue = is_callable($rule) ? $rule($value) : filter_var($value, $rule);

                if ($validValue !== false && $validValue !== null) {
                    $params[$param] = $validValue;
                }
            }
        }

        return $params;
    }

    protected function redirectToPreviousPage(array $server): void
    {
        $defaultUrl = 'index.php';
        $referer = $server['HTTP_REFERER'] ?? '';

        if (!empty($referer) && filter_var($referer, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($referer);
            $queryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
            }

            $page = $queryParams['page'] ?? null;
            if ($page && $page !== 'switch_theme' && in_array($page, Pages::GetPages())) {
                $params = $this->sanitizeQueryParams($queryParams);
                $path = $parsedUrl['path'] ?? '/index.php';
                $url = $path . (!empty($params) ? '?' . http_build_query($params) : '');
                $this->sendRedirect($url);
                return;
            }
        }

        $this->sendRedirect($defaultUrl);
    }
}