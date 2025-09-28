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

use Poweradmin\Application\Controller\NotFoundController;
use Poweradmin\Application\Routing\SymfonyRouter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';
require_once __DIR__ . '/lib/Domain/Model/TopLevelDomainInit.php';

// Initialize configuration
$configManager = ConfigurationManager::getInstance();
$configManager->initialize();

// Initialize session
initializeSession();

// Create and process routes
$router = new SymfonyRouter();

try {
    // Process the request
    $router->process();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    // Check if request expects JSON response
    $expectsJson = (
        str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') ||
        str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
         strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );

    if ($expectsJson) {
        header('Content-Type: application/json');

        if ($e->getCode() === 404 || str_contains($e->getMessage(), 'not found')) {
            http_response_code(404);
            echo json_encode([
                'error' => true,
                'message' => 'Endpoint not found'
            ]);
        } elseif ($e->getCode() === 405) {
            http_response_code(405);
            echo json_encode([
                'error' => true,
                'message' => 'Method not allowed'
            ]);
        } else {
            http_response_code(500);
            $showDebug = $configManager->get('misc', 'display_errors', false);
            echo json_encode([
                'error' => true,
                'message' => $showDebug ? $e->getMessage() : 'Internal server error',
                'file' => $showDebug ? $e->getFile() : null,
                'line' => $showDebug ? $e->getLine() : null,
                'trace' => $showDebug ? explode("\n", $e->getTraceAsString()) : null
            ]);
        }
    } else {
        // HTML error response
        if ($e->getCode() === 404 || str_contains($e->getMessage(), 'not found')) {
            http_response_code(404);
            try {
                $notFoundController = new NotFoundController([]);
                $notFoundController->run();
            } catch (Exception $notFoundError) {
                echo 'Page not found.';
            }
        } elseif ($configManager->get('misc', 'display_errors', false)) {
            displayHtmlError($e);
        } else {
            echo 'An error occurred while processing the request.';
        }
    }
}
