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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;

/**
 * The value object resolves the client once from the server array the way the
 * address and user-agent helpers do: proxy headers honoured only behind a
 * trusted peer, the user agent sanitized for logging.
 */
#[CoversClass(ClientContext::class)]
class ClientContextTest extends TestCase
{
    public function testResolvesAddressAndUserAgentFromTheServerArray(): void
    {
        $client = ClientContext::fromServer([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36',
        ]);

        $this->assertSame('203.0.113.9', $client->ip);
        $this->assertSame('Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36', $client->userAgent);
        $this->assertSame('Chrome/120.0.0.0', $client->browser);
        $this->assertFalse($client->isBot);
    }

    public function testHonoursForwardedHeadersOnlyBehindAPrivatePeer(): void
    {
        $viaProxy = ClientContext::fromServer(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4']);
        $direct = ClientContext::fromServer(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4']);

        $this->assertSame('198.51.100.4', $viaProxy->ip);
        $this->assertSame('203.0.113.9', $direct->ip);
    }

    public function testFallsBackToEmptyAddressAndUnknownAgentWhenNothingIsPresent(): void
    {
        $client = ClientContext::fromServer([]);

        $this->assertSame('', $client->ip);
        $this->assertSame('unknown', $client->userAgent);
        $this->assertSame('Unknown', $client->browser);
    }

    public function testFlagsCrawlersAsBots(): void
    {
        $client = ClientContext::fromServer(['HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);

        $this->assertTrue($client->isBot);
    }
}
