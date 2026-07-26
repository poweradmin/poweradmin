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

use Poweradmin\Application\Http\BootstrapErrorResponder;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Routing\SymfonyRouter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';
require_once __DIR__ . '/lib/Domain/Model/TopLevelDomainInit.php';

// Only allocation happens out here: getInstance() and the responder cannot fail,
// and the responder tolerates a half-built configuration so it can shape the
// failure of the initialize() call below.
$configManager = ConfigurationManager::getInstance();
$errorResponder = new BootstrapErrorResponder($configManager);

try {
    $configManager->initialize();

    initializeTimezone($configManager);
    initializeSession();

    // A v2 HEAD request is dispatched through the GET handler (see PublicApiController),
    // so buffer the response and drop its body: HEAD must return headers only. The
    // callback runs when the response flushes its own output buffers during send().
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD' && RequestContext::isV2ApiRequest()) {
        ob_start(static fn(): string => '');
    }

    // Constructing the router parses routes.yaml and loads the module registry, so
    // it belongs inside the guarded region rather than ahead of it.
    $router = new SymfonyRouter();
    $router->process();
} catch (Throwable $e) {
    // Throwable, not Exception: a TypeError from mistyped-but-valid JSON (e.g. an
    // array where a string is expected) is an Error, and must still be shaped into
    // a JSON 500 instead of escaping as a blank/HTML fatal.
    $errorResponder->handle($e);
}
