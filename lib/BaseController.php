<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\Web\ThemeManager;

abstract class BaseController
{
    private Application $app;
    private LegacyApplicationInitializer $init;
    protected PDOLayer $db;

    abstract public function run(): void;

    public function __construct(bool $authenticate = true)
    {
        $this->app = AppFactory::create();

        $this->init = new LegacyApplicationInitializer($authenticate);
        $this->db = $this->init->getDb();
    }

    public function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    public function isGet(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    public function getConfig(): LegacyConfiguration
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

        $this->app->render($template, $params);

        $this->renderFooter();
    }

    public function redirect($script, $args = []): void
    {
        $args['time'] = time();
        $url = htmlentities($script, ENT_QUOTES) . "?" . http_build_query($args);
        header("Location: $url");
        exit;
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

    public function showMessage($template)
    {
        $script = pathinfo($template)['filename'];

        $message = $this->getMessage($script);
        if ($message) {
            switch ($message['type']) {
                case 'error':
                    $alertClass = 'alert-danger';
                    break;
                case 'warn':
                    $alertClass = 'alert-warning';
                    break;
                case 'success':
                    $alertClass = 'alert-success';
                    break;
                case 'info':
                    $alertClass = 'alert-info';
                    break;
                default:
                    $alertClass = '';
            }

            echo <<<EOF
<div class="alert {$alertClass} alert-dismissible fade show" role="alert">{$message['content']}
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
        if (!LegacyUsers::verify_permission($this->db, $permission)) {
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
        $ignore_install_dir = $this->app->config('ignore_install_dir');

        $vars = [
            'iface_title' => $this->app->config('iface_title'),
            'iface_style' => $themeManager->getSelectedTheme(),
            'file_version' => time(),
            'custom_header' => file_exists('templates/custom/header.html'),
            'install_error' => !$ignore_install_dir && file_exists('install') ? _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.') : false,
        ];

        $dblog_use = $this->app->config('dblog_use');
        $session_key = $this->app->config('session_key');

        if (isset($_SESSION["userid"])) {
            $perm_is_godlike = LegacyUsers::verify_permission($this->db,'user_is_ueberuser');

            $vars = array_merge($vars, [
                'user_logged_in' => isset($_SESSION["userid"]),
                'perm_search' => LegacyUsers::verify_permission($this->db,'search'),
                'perm_view_zone_own' => LegacyUsers::verify_permission($this->db,'zone_content_view_own'),
                'perm_view_zone_other' => LegacyUsers::verify_permission($this->db,'zone_content_view_others'),
                'perm_supermaster_view' => LegacyUsers::verify_permission($this->db,'supermaster_view'),
                'perm_zone_master_add' => LegacyUsers::verify_permission($this->db,'zone_master_add'),
                'perm_zone_slave_add' => LegacyUsers::verify_permission($this->db,'zone_slave_add'),
                'perm_supermaster_add' => LegacyUsers::verify_permission($this->db,'supermaster_add'),
                'perm_is_godlike' => $perm_is_godlike,
                'perm_templ_perm_edit' => LegacyUsers::verify_permission($this->db,'templ_perm_edit'),
                'perm_add_new' => LegacyUsers::verify_permission($this->db,'user_add_new'),
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
}