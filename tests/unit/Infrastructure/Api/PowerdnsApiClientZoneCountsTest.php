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

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Infrastructure\Session\ArraySession;

#[CoversClass(PowerdnsApiClient::class)]
class PowerdnsApiClientZoneCountsTest extends TestCase
{
    private function client(HttpClient $http): PowerdnsApiClient
    {
        return new PowerdnsApiClient($http, 'localhost', new ApiStatusService(new ArraySession()), null, '4.9.0');
    }

    public function testCountsKindsAndSignedZonesFromOneZoneList(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('makeRequest')
            ->willReturn([
                'responseCode' => 200,
                'data' => [
                    ['name' => 'a.example.', 'kind' => 'Native', 'dnssec' => true],
                    ['name' => 'b.example.', 'kind' => 'Master', 'dnssec' => false],
                    ['name' => 'c.example.', 'kind' => 'native', 'dnssec' => true],
                    ['name' => 'd.example.'],
                ],
            ]);

        $counts = $this->client($http)->getZoneCounts();

        $this->assertSame(['native' => 2, 'master' => 1, 'unknown' => 1], $counts['kinds']);
        $this->assertSame(2, $counts['dnssec_signed']);
    }

    public function testReturnsNullWhenPowerDnsDoesNotAnswer(): void
    {
        $http = $this->createMock(HttpClient::class);
        // HttpClient throws on a refused connection and on every 4xx/5xx answer
        $http->method('makeRequest')->willThrowException(new ApiErrorException('Connection refused', 0));

        $this->assertNull($this->client($http)->getZoneCounts());
    }
}
