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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

#[CoversClass(DatabaseConsistencyService::class)]
class DatabaseConsistencyServiceTest extends TestCase
{
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        // TableNameService asks the config for pdns_db_name; default to no prefix.
        $this->config->method('get')->willReturnCallback(
            fn(string $section, string $key, mixed $default = null) => $default
        );
    }

    #[Test]
    public function sqlBackendQueryExcludesZonesThatHaveAnyDirectOwnerOrGroup(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        // No orphan rows -> the query should report success.
        $stmt->method('fetch')->willReturn(false);

        $this->db->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'NOT EXISTS')
                    && str_contains($sql, 'zones z')
                    && str_contains($sql, 'z.owner IS NOT NULL AND z.owner <> 0')
                    && str_contains($sql, 'zones_groups zg');
            }))
            ->willReturn($stmt);

        $service = new DatabaseConsistencyService($this->db, $this->config);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['data']);
    }

    #[Test]
    public function sqlBackendReportsOrphanRowsReturnedByTheQuery(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 42, 'name' => 'orphan.example.com'],
            false
        );

        $this->db->method('query')->willReturn($stmt);

        $service = new DatabaseConsistencyService($this->db, $this->config);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('warning', $result['status']);
        $this->assertCount(1, $result['data']);
        $this->assertSame(42, $result['data'][0]['id']);
        $this->assertSame('orphan.example.com', $result['data'][0]['name']);
        $this->assertNull($result['data'][0]['owner']);
    }

    #[Test]
    public function apiBackendTreatsZoneWithGroupOwnershipAsHealthy(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn([
            ['id' => 7, 'name' => 'group-only.example.com.'],
        ]);

        // owner_count = 0, group_count = 1 -> not orphaned
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['owner_count' => 0, 'group_count' => 1]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(fn(string $sql) => str_contains($sql, 'c.zone_name = ?')))
            ->willReturn($stmt);

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['data']);
    }

    #[Test]
    public function apiBackendTreatsZoneWithDirectOwnerAsHealthy(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn([
            ['id' => 11, 'name' => 'user-owned.example.com.'],
        ]);

        // Multiple zones rows, at least one with owner -> healthy.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['owner_count' => 2, 'group_count' => 0]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(fn(string $sql) => str_contains($sql, 'c.zone_name = ?')))
            ->willReturn($stmt);

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('success', $result['status']);
    }

    #[Test]
    public function apiBackendFlagsZoneWithNeitherOwnerNorGroup(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn([
            ['id' => 99, 'name' => 'orphan.example.com.'],
        ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['owner_count' => 0, 'group_count' => 0]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->callback(fn(string $sql) => str_contains($sql, 'c.zone_name = ?')))
            ->willReturn($stmt);

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('warning', $result['status']);
        $this->assertCount(1, $result['data']);
        $this->assertSame(99, $result['data'][0]['id']);
        $this->assertSame('orphan.example.com', $result['data'][0]['name']);
    }
}
