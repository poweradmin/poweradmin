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
use Poweradmin\Application\Service\PdnsVersionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;

class PdnsVersionServiceTest extends TestCase
{
    private PowerdnsApiClient $mockClient;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->mockClient = $this->createMock(PowerdnsApiClient::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
    }

    public function testDetectFetchesFromApiAndCaches(): void
    {
        $this->mockClient->expects($this->once())
            ->method('getServerInfo')
            ->willReturn([
                'version' => '4.9.12',
                'daemon_type' => 'authoritative',
                'id' => 'localhost',
            ]);

        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $info = $service->detect();

        $this->assertSame('4.9.12', $info['version']);
        $this->assertSame('authoritative', $info['daemon_type']);
        $this->assertSame('localhost', $info['id']);
        $this->assertNotNull($service->getCached());
    }

    public function testDetectReturnsCachedResultWithinTtl(): void
    {
        // First call fetches from API.
        $this->mockClient->expects($this->once())
            ->method('getServerInfo')
            ->willReturn([
                'version' => '4.9.12',
                'daemon_type' => 'authoritative',
                'id' => 'localhost',
            ]);

        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $service->detect();

        // Second call should be served from the session cache - getServerInfo
        // must not be called again.
        $info = $service->detect();
        $this->assertSame('4.9.12', $info['version']);
    }

    public function testDetectReturnsNullAndDoesNotCacheOnEmptyResponse(): void
    {
        $this->mockClient->method('getServerInfo')->willReturn([]);

        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $this->assertNull($service->detect());
        $this->assertNull($service->getCached());
    }

    public function testDetectReturnsNullWhenVersionMissingFromResponse(): void
    {
        $this->mockClient->method('getServerInfo')->willReturn([
            'daemon_type' => 'authoritative',
            'id' => 'localhost',
            // no 'version' key
        ]);

        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $this->assertNull($service->detect());
    }

    public function testDetectLogsVersionOnFirstDetection(): void
    {
        $this->mockClient->method('getServerInfo')->willReturn([
            'version' => '4.9.12',
            'daemon_type' => 'authoritative',
            'id' => 'localhost',
        ]);

        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('PowerDNS'),
                $this->callback(function ($ctx) {
                    return isset($ctx['version']) && $ctx['version'] === '4.9.12';
                })
            );

        (new PdnsVersionService($this->mockClient, $this->mockLogger))->detect();
    }

    public function testGetCachedReturnsNullBeforeAnyDetection(): void
    {
        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $this->assertNull($service->getCached());
    }

    public function testDetectRefetchesAfterTtlExpiry(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('getServerInfo')
            ->willReturn([
                'version' => '4.9.12',
                'daemon_type' => 'authoritative',
                'id' => 'localhost',
            ]);

        $service = new PdnsVersionService($this->mockClient, $this->mockLogger);
        $service->detect();

        // Simulate TTL expiry by backdating the cache timestamp.
        $_SESSION['pdns_server_info']['fetched_at'] = time() - 400;

        $service->detect();
    }
}
