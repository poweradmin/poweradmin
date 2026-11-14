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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Infrastructure\Session\ArraySession;

class PowerdnsApiClientWriteSuccessTest extends TestCase
{
    private function clientAnswering(array $response): PowerdnsApiClient
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('makeRequest')->willReturn($response);

        return new PowerdnsApiClient($http, 'localhost', new ApiStatusService(new ArraySession()), null, '5.0.0');
    }

    public static function successAnswers(): array
    {
        return [
            'no content' => [['responseCode' => 204, 'data' => []]],
            'ok with warnings' => [['responseCode' => 200, 'data' => ['warnings' => ['content was normalized']]]],
            'created' => [['responseCode' => 201, 'data' => []]],
        ];
    }

    #[DataProvider('successAnswers')]
    public function testAny2xxAnswerIsASuccessfulWrite(array $response): void
    {
        $client = $this->clientAnswering($response);

        $this->assertTrue($client->patchZoneRRsets('example.com.', []));
        $this->assertTrue($client->deleteZone(new Zone('example.com')));
        $this->assertTrue($client->createZoneMetadata(new Zone('example.com'), 'ALLOW-AXFR-FROM', ['192.0.2.1']));
    }

    public function testAnswerWithoutAStatusIsNotASuccessfulWrite(): void
    {
        $client = $this->clientAnswering([]);

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
    }
}
