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

abstract class BaseController {
    private Application $app;

    abstract public function run(): void;

    public function __construct()
    {
        $this->app = AppFactory::create();
        include_once 'inc/header.inc.php';
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
        $this->app->render($template, $params);
        include_once('inc/footer.inc.php');
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
            include_once('inc/footer.inc.php');
            exit;
        }
    }

    public function showError($errors)
    {
        $validationErrors = array_values($errors);
        $firstError = reset($validationErrors);
        error($firstError[0]);
        include_once('inc/footer.inc.php');
        exit;
    }
}