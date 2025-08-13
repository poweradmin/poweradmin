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
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Pages;

require __DIR__ . '/vendor/autoload.php';

// Initialize the TopLevelDomain class
require_once __DIR__ . '/lib/Domain/Model/TopLevelDomainInit.php';

$configManager = ConfigurationManager::getInstance();
$configManager->initialize();

/**
 * Initialize secure session configuration
 */
function initializeSession(): void
{
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
}

initializeSession();

/**
 * Send JSON error response
 */
function sendJsonError(string $message, ?string $file = null, ?int $line = null, ?array $trace = null): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'trace' => $trace
    ]);
}

$router = new BasicRouter($_REQUEST);

$router->setDefaultPage('index');
$router->setPages(Pages::getPages());

// Load BaseController for error handling
require_once __DIR__ . '/lib/BaseController.php';

try {
    // Use BaseController's expectsJson method to determine if this expects JSON
    $expectsJson = \Poweradmin\BaseController::expectsJson();

    // For API requests, suppress display errors but still log them
    if ($expectsJson) {
        // Disable displaying errors in output for API responses
        ini_set('display_errors', 0);
        // But still log them for debugging
        error_reporting(E_ALL);
    }

    $router->process();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    // Use BaseController's expectsJson method
    $expectsJson = \Poweradmin\BaseController::expectsJson();

    // Check if this is a controller not found error
    if (str_contains($e->getMessage(), 'Class') && str_contains($e->getMessage(), 'not found')) {
        // Set 404 status and use NotFoundController
        http_response_code(404);

        try {
            $notFoundController = new \Poweradmin\Application\Controller\NotFoundController($_REQUEST);
            $notFoundController->run();
        } catch (Exception $notFoundError) {
            // Fallback error handling
            error_log('Error in NotFoundController: ' . $notFoundError->getMessage());

            if ($expectsJson) {
                sendJsonError('Page not found');
            } else {
                echo 'Page not found.';
            }
        }
    } elseif ($expectsJson || $configManager->get('misc', 'display_errors', false)) {
        // For JSON requests, always return JSON error
        if ($expectsJson) {
            $showDebug = $configManager->get('misc', 'display_errors', false);
            sendJsonError(
                $e->getMessage(),
                $showDebug ? $e->getFile() : null,
                $showDebug ? $e->getLine() : null,
                $showDebug ? explode("\n", $e->getTraceAsString()) : null
            );
        } else {
            // For HTML requests with display_errors enabled, show detailed error
            echo '<pre>';
            echo 'Error: ' . htmlspecialchars($e->getMessage()) . "\n";
            echo 'File: ' . htmlspecialchars($e->getFile()) . "\n";
            echo 'Line: ' . $e->getLine() . "\n";
            echo 'Trace: ' . "\n" . htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        }
    } else {
        // For HTML requests without display_errors, show generic message
        echo 'An error occurred while processing the request.';
    }
}
