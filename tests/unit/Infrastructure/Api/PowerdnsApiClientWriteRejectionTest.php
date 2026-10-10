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

namespace Unit\Infrastructure\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class PowerdnsApiClientWriteRejectionTest extends TestCase
{
    private function clientFailingWith(ApiErrorException $e): PowerdnsApiClient
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('makeRequest')->willThrowException($e);

        return new PowerdnsApiClient($http, 'localhost', null, '4.9.0');
    }

    private function rejection(int $status, array $response): ApiErrorException
    {
        return new ApiErrorException('An API request failed', $status, null, ['http_code' => $status, 'response' => $response]);
    }

    #[Test]
    public function testUnprocessableAnswerExposesPowerDnsReason(): void
    {
        $client = $this->clientFailingWith($this->rejection(422, ['error' => 'Record x.example.com./TXT: Not in expected format']));

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
        $this->assertSame('Record x.example.com./TXT: Not in expected format', $client->getLastWriteRejection());
    }

    #[Test]
    public function testAuthFailureHasNoReason(): void
    {
        $client = $this->clientFailingWith($this->rejection(403, ['error' => 'Forbidden']));

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
        $this->assertNull($client->getLastWriteRejection());
    }

    #[Test]
    public function testServerErrorHasNoReason(): void
    {
        $client = $this->clientFailingWith($this->rejection(500, ['error' => 'boom']));

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
        $this->assertNull($client->getLastWriteRejection());
    }

    #[Test]
    public function testTransportFailureHasNoReason(): void
    {
        $client = $this->clientFailingWith(new ApiErrorException('Connection refused', 0, null, ['error' => 'Connection refused']));

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
        $this->assertNull($client->getLastWriteRejection());
    }

    #[Test]
    public function testNonJsonBodyHasNoReason(): void
    {
        $client = $this->clientFailingWith($this->rejection(422, ['raw_response' => 'oops']));

        $this->assertFalse($client->patchZoneRRsets('example.com.', []));
        $this->assertNull($client->getLastWriteRejection());
    }
}
