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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use ReflectionClass;

class PowerdnsStatusServiceTest extends TestCase
{
    private function serviceWith(array $serverInfo, array $metrics): PowerdnsStatusService
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('getServerInfo')->willReturn($serverInfo);
        $client->method('getMetrics')->willReturn($metrics);

        $ref = new ReflectionClass(PowerdnsStatusService::class);
        $service = $ref->newInstanceWithoutConstructor();
        foreach (
            [
                'apiClient' => $client,
                'apiEnabled' => true,
                'apiUrl' => '',
                'displayName' => 'PowerDNS',
                'serverName' => 'localhost',
            ] as $prop => $value
        ) {
            $p = $ref->getProperty($prop);
            $p->setValue($service, $value);
        }

        return $service;
    }

    public function testOddMetricEntriesAreIgnored(): void
    {
        $service = $this->serviceWith(
            ['daemon_type' => 'authoritative', 'version' => '4.9.0'],
            [
                ['name' => ['x'], 'value' => '1'],
                ['name' => 'udp-queries', 'value' => ['nested']],
                ['name' => 'tcp-queries', 'value' => 'not-a-number'],
                ['name' => 'latency', 'value' => null],
                'garbage',
                ['name' => 'uptime', 'value' => '120'],
                ['name' => 'packetcache-hit', 'value' => 42],
            ]
        );

        $status = $service->getServerStatus();

        $this->assertTrue($status['running']);
        $this->assertSame(['uptime' => '120', 'packetcache-hit' => 42], $status['metrics']);
        $this->assertSame(120, $status['uptime_seconds']);
    }

    public function testNonScalarServerInfoFallsBackToDefaults(): void
    {
        $service = $this->serviceWith(
            ['daemon_type' => ['a'], 'version' => ['b'], 'id' => ['c']],
            []
        );

        $status = $service->getServerStatus();

        $this->assertSame('unknown', $status['daemon_type']);
        $this->assertSame('unknown', $status['version']);
        $this->assertSame('localhost', $status['id']);
    }
}
