<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

abstract class BaseController
{
    private Application $app;

    abstract public function run(): void;

    public function __construct()
    {
        $this->app = AppFactory::create();
    }

    public function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    public function isGet(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    public function config(string $key)
    {
        return $this->app->config($key);
    }

    public function render(string $template, array $params): void
    {
        include_once 'inc/header.inc.php';

        $this->showMessage($template);

        $this->app->render($template, $params);
        include_once('inc/footer.inc.php');
    }

    public function redirect($script, $args = [])
    {
        $args['time'] = time();
        $url = htmlentities($script, ENT_QUOTES) . "?" . http_build_query($args);
        header("Location: $url");
        exit;
    }

    public function setMessage($script, $type, $content)
    {
        $_SESSION['messages'][$script] = [
            'type' => $type,
            'content' => $content
        ];
    }

    public function getMessage($script)
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
            error($errorMessage);
            include_once('inc/footer.inc.php');
            exit;
        }
    }

    public function checkPermission(string $permission, string $errorMessage)
    {
        if (!do_hook('verify_permission', $permission)) {
            error($errorMessage);
            exit;
        }
    }

    public function showError(string $error)
    {
        include_once 'inc/header.inc.php';
        error($error);
        include_once('inc/footer.inc.php');
        exit;
    }

    public function showFirstError(array $errors)
    {
        include_once 'inc/header.inc.php';
        $validationErrors = array_values($errors);
        $firstError = reset($validationErrors);
        error($firstError[0]);
        include_once('inc/footer.inc.php');
        exit;
    }
}