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
use Poweradmin\Application\Routing\BasicRouter;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Pages;

require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';
require_once __DIR__ . '/lib/Domain/Model/TopLevelDomainInit.php';

$configManager = ConfigurationManager::getInstance();
$configManager->initialize();

initializeSession();

$router = new BasicRouter($_REQUEST);

$router->setDefaultPage('index');
$router->setPages(Pages::getPages());

try {
    $expectsJson = BaseController::expectsJson();

    if ($expectsJson) {
        ini_set('display_errors', 0);
        error_reporting(E_ALL);
    }

    $router->process();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    $expectsJson = BaseController::expectsJson();

    if (str_contains($e->getMessage(), 'Class') && str_contains($e->getMessage(), 'not found')) {
        http_response_code(404);

        try {
            $notFoundController = new NotFoundController($_REQUEST);
            $notFoundController->run();
        } catch (Exception $notFoundError) {
            error_log('Error in NotFoundController: ' . $notFoundError->getMessage());

            if ($expectsJson) {
                sendJsonError('Page not found');
            } else {
                echo 'Page not found.';
            }
        }
    } elseif ($expectsJson || $configManager->get('misc', 'display_errors', false)) {
        if ($expectsJson) {
            $showDebug = $configManager->get('misc', 'display_errors', false);
            sendJsonError(
                $e->getMessage(),
                $showDebug ? $e->getFile() : null,
                $showDebug ? $e->getLine() : null,
                $showDebug ? explode("\n", $e->getTraceAsString()) : null
            );
        } else {
            displayHtmlError($e);
        }
    } else {
        echo 'An error occurred while processing the request.';
    }
}
