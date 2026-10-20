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

namespace Poweradmin\Application\Http;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Infrastructure\Utility\UserAgentService;

/**
 * The requesting client as resolved once per request: the address behind any
 * trusted proxy and the sanitized user agent. Built by the composition root
 * and handed to everything that logs, throttles or audits by client.
 */
final class ClientContext
{
    /**
     * @param string $ip Resolved client address, empty when none could be validated
     * @param string $userAgent Sanitized User-Agent header, "unknown" when absent
     * @param string $browser Browser name and version derived from the user agent
     */
    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $browser,
        public readonly bool $isBot
    ) {
    }

    /**
     * @param array<string, mixed> $server The request's $_SERVER array
     * @param ConfigurationInterface $config Source of the trusted proxy list
     */
    public static function fromServer(array $server, ConfigurationInterface $config): self
    {
        $agent = new UserAgentService($server);

        return new self(
            IpAddressRetriever::fromConfig($server, $config)->getClientIp(),
            $agent->getUserAgent(),
            $agent->getBrowserInfo(),
            $agent->isBot()
        );
    }
}
