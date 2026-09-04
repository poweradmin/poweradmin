<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Application/Helpers/StartupHelpers.php';

// getInstance() only allocates; initialize() is what can fail, so it goes inside
// the guarded region. The responder tolerates a half-built configuration.
$configManager = ConfigurationManager::getInstance();

try {
    $configManager->initialize();
    CanonicalZoneSql::setRowIdFallback(DnsBackendProviderFactory::isApiBackend($configManager));

    initializeTimezone($configManager);

    // Neither a headless install nor the monitoring probes have any use for a session,
    // and starting one per scrape would leave a session file behind on every request.
    if (
        $configManager->get('interface', 'web_enabled', true)
        && !RequestContext::isHealthProbeRequest((string) $configManager->get('interface', 'base_url_prefix', ''))
    ) {
        initializeSession($configManager);
    }

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
    (new BootstrapErrorResponder($configManager))->handle($e);
}
