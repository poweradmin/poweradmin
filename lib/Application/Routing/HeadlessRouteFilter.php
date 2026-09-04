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

namespace Poweradmin\Application\Routing;

use Poweradmin\Application\Http\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Removes the web interface from the route collection when interface.web_enabled
 * is false, leaving an API-only (headless) surface.
 *
 * Filtering the collection rather than rejecting after a match means no web
 * controller is ever constructed: the unmatched path falls through to the
 * router's own not-found handling.
 */
final class HeadlessRouteFilter
{
    /**
     * Route path prefixes that survive with the web interface disabled.
     *
     * api/internal is deliberately absent: those endpoints are session-based and
     * are only ever called by the interface's own JavaScript. api/v1 stays so
     * retired v1 clients keep receiving their 410 rather than a bare 404.
     */
    private const ALLOWED_PREFIXES = [
        '/api/v1',
        '/api/v2',
        '/api/docs',
    ];

    private function __construct()
    {
    }

    public static function apply(RouteCollection $routes): void
    {
        foreach ($routes->all() as $name => $route) {
            if (!self::isAllowed($route->getPath())) {
                $routes->remove($name);
            }
        }
    }

    public static function isAllowed(string $path): bool
    {
        foreach ([...self::ALLOWED_PREFIXES, ...RequestContext::HEALTH_PROBE_PATHS] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
