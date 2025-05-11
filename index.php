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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Pages;

require __DIR__ . '/vendor/autoload.php';

// Initialize the TopLevelDomain class
require_once __DIR__ . '/lib/Domain/Model/TopLevelDomainInit.php';

$configManager = ConfigurationManager::getInstance();
$configManager->initialize();

if (!function_exists('session_start')) {
    require_once __DIR__ . '/lib/Infrastructure/Service/MessageService.php';
    (new MessageService())->displayDirectSystemError("You have to install the PHP session extension!");
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
    // Check if this is an API request
    $isApiRequest = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');

    // For API requests, suppress display errors but still log them
    if ($isApiRequest) {
        // Disable displaying errors in output for API responses
        ini_set('display_errors', 0);
        // But still log them for debugging
        error_reporting(E_ALL);
    }

    $router->process();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    if (
        $configManager->get('misc', 'display_errors', false) ||
        (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
    ) {
        // Show detailed error if it's an API request or display_errors is enabled
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ]);
    } else {
        echo 'An error occurred while processing the request.';
    }
}
