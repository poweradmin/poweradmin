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

namespace Poweradmin\Tests\Unit\Infrastructure\Network;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\ProxyContextInterface;
use Poweradmin\Infrastructure\Network\EnvironmentProxyContext;
use Poweradmin\Infrastructure\Network\ProxyContext;

class EnvironmentProxyContextTest extends TestCase
{
    private const PROXY_VARS = ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::PROXY_VARS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::PROXY_VARS as $var) {
            $value = $this->savedEnv[$var];
            putenv($value === false ? $var : $var . '=' . $value);
        }
    }

    public function testAnswersAsTheStaticLookupDoes(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        $options = ['http' => ['method' => 'GET', 'timeout' => 10]];
        $port = new EnvironmentProxyContext();

        $this->assertInstanceOf(ProxyContextInterface::class, $port);
        $this->assertSame(ProxyContext::applyTo($options, 'https://api.example.com/zones'), $port->applyTo($options, 'https://api.example.com/zones'));
        $this->assertSame('tcp://proxy.internal:3128', $port->applyTo($options, 'https://api.example.com/zones')['http']['proxy']);
    }

    public function testLeavesOptionsAloneWithoutAProxy(): void
    {
        $options = ['http' => ['method' => 'GET']];

        $this->assertSame($options, (new EnvironmentProxyContext())->applyTo($options, 'https://api.example.com'));
    }
}
