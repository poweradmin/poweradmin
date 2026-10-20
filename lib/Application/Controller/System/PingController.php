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

namespace Poweradmin\Application\Controller\System;

use Poweradmin\Application\Boot\BootContext;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthenticated liveness probe. Answers if the process can serve a request
 * and checks nothing else, so it still reports ok while the database is down.
 * Disabled by default.
 *
 * Deliberately does not extend BaseController, whose constructor connects to the
 * database unconditionally - that would make this fail exactly when a liveness
 * probe is most useful. Use /api/health for dependency status.
 */
class PingController
{
    private ConfigurationInterface $config;

    /**
     * @param BootContext $context The booted context; the router passes it to every controller outside BaseController
     */
    public function __construct(BootContext $context)
    {
        $this->config = $context->config;
    }

    /**
     * Overridable so tests can substitute settings without a boot context.
     */
    protected function config(): ConfigurationInterface
    {
        return $this->config;
    }

    public function run(): void
    {
        $this->buildResponse()->send();
    }

    public function buildResponse(): Response
    {
        $enabled = (bool) $this->config()->get('health', 'ping_enabled', false);

        return new Response($enabled ? 'ok' : 'Not Found', $enabled ? 200 : 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
