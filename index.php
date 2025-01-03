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

use Poweradmin\Application\Routing\BasicRouter;
use Poweradmin\Pages;

require __DIR__ . '/vendor/autoload.php';

if (!function_exists('session_start')) {
    die("You have to install the PHP session extension!");
}

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'secure' => $secure,
    'httponly' => true,
]);

session_start();

$router = new BasicRouter($_REQUEST);

$router->setDefaultPage('index');
$router->setPages(Pages::getPages());

try {
    $router->process();
} catch (Exception $e) {
    error_log($e->getMessage());
    echo 'An error occurred while processing the request.';
}
