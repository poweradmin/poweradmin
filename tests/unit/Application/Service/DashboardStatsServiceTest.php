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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\DashboardStatsService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TestHelpers\FakeConfiguration;

class DashboardStatsServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSqlModeCountsThePowerdnsTables(): void
    {
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY)");
        $this->db->exec("INSERT INTO records (id) VALUES (1), (2), (3)");

        $stats = $this->makeService(false)->stats(5, true);

        $this->assertSame(['zones' => 2, 'records' => 3, 'users' => 9, 'groups' => 4], $stats);
    }

    public function testMissingPowerdnsTablesLeaveTheCountsEmpty(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneCount')->willThrowException(new RuntimeException('no such table: domains'));

        $stats = $this->makeService(false, null, $zones)->stats(5, false);

        $this->assertNull($stats['zones']);
        $this->assertNull($stats['records']);
        $this->assertSame(1, $stats['users']);
    }

    public function testApiModeCountsThroughTheBackend(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn([['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd']]);

        $stats = $this->makeService(true, $backend)->stats(5, true);

        $this->assertSame(4, $stats['zones']);
        $this->assertNull($stats['records']);
    }

    public function testApiOutageFallsBackToTheCachedZones(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willThrowException(new RuntimeException('down'));

        $this->assertSame(2, $this->makeService(true, $backend)->stats(5, true)['zones']);
    }

    public function testSwallowedApiErrorWithNoZonesFallsBackToTheCachedZones(): void
    {
        $_SESSION['pdns_api_last_error'] = ['message' => 'timeout', 'context' => [], 'timestamp' => 0];
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn([]);

        $this->assertSame(2, $this->makeService(true, $backend)->stats(5, true)['zones']);
    }

    private function makeService(bool $apiBackend, ?DnsBackendProviderInterface $backend = null, ?ZoneRepositoryInterface $zones = null): DashboardStatsService
    {
        if ($backend === null) {
            $backend = $this->createMock(DnsBackendProviderInterface::class);
            $backend->method('isApiBackend')->willReturn($apiBackend);
        }
        if ($zones === null) {
            $zones = $this->createMock(ZoneRepositoryInterface::class);
            $zones->method('getZoneCount')->willReturn(2);
        }
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getTotalUserCount')->willReturnCallback(fn(?int $restrictTo = null): int => $restrictTo === null ? 9 : 1);
        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('countAll')->willReturn(4);

        return new DashboardStatsService(
            $this->db,
            new FakeConfiguration(['database' => ['type' => 'sqlite', 'pdns_db_name' => '']]),
            $this->createMock(LoggerInterface::class),
            $users,
            $groups,
            $zones,
            $backend
        );
    }
}
