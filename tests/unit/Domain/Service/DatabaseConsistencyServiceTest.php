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
use Poweradmin\Application\Service\ApiStatusService;
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
        // ApiStatusService is session-backed; start each test with a clean slate.
        (new ApiStatusService())->clearError();
    }

    protected function tearDown(): void
    {
        (new ApiStatusService())->clearError();
        parent::tearDown();
    }

    private function apiBackend(array $zones): DnsBackendProvider&MockObject
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn($zones);
        return $backend;
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

    #[Test]
    public function apiBackendSkipsUnsyncedZoneInOwnerCheck(): void
    {
        // A zone PowerDNS reports but Poweradmin has not synced yet has id 0 and no
        // local row; it must not be flagged or queried for ownership.
        $backend = $this->apiBackend([['id' => 0, 'name' => 'unsynced.example.com.']]);

        $this->db->expects($this->never())->method('prepare');

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);
        $result = $service->checkZonesHaveOwners();

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['data']);
    }

    #[Test]
    public function fixZoneWithoutOwnerRejectsUnsyncedZone(): void
    {
        // domain_id 0 would insert a dangling zones row; the guard must refuse it.
        $this->db->expects($this->never())->method('prepare');

        $service = new DatabaseConsistencyService($this->db, $this->config);

        $this->assertFalse($service->fixZoneWithoutOwner(0, 1));
    }

    #[Test]
    public function runAllChecksReturnsNullWhenZoneListUnavailable(): void
    {
        // getZones() swallows an API outage into an empty list but records the error.
        $backend = $this->apiBackend([]);
        (new ApiStatusService())->recordError('connection refused', ['endpoint' => 'zones']);

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);

        $this->assertNull($service->runAllChecks());
    }

    #[Test]
    public function runAllChecksReturnsNullWhenPerZoneReadFails(): void
    {
        $backend = $this->apiBackend([['id' => 5, 'name' => 'z.example.com.', 'type' => 'NATIVE']]);
        // Zone has an owner, so the ownership check passes...
        $ownerStmt = $this->createMock(PDOStatement::class);
        $ownerStmt->method('execute')->willReturn(true);
        $ownerStmt->method('fetch')->willReturn(['owner_count' => 1, 'group_count' => 0]);
        $this->db->method('prepare')->willReturn($ownerStmt);

        // ...but the per-zone SOA read fails and is swallowed into an empty list.
        $backend->method('getRecordsByZoneId')->willReturnCallback(function () {
            (new ApiStatusService())->recordError('502 Bad Gateway', ['endpoint' => 'zone']);
            return [];
        });

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);

        $this->assertNull($service->runAllChecks());
    }

    #[Test]
    public function runAllChecksReturnsResultsWhenApiHealthy(): void
    {
        $backend = $this->apiBackend([['id' => 5, 'name' => 'z.example.com.', 'type' => 'NATIVE']]);
        $ownerStmt = $this->createMock(PDOStatement::class);
        $ownerStmt->method('execute')->willReturn(true);
        $ownerStmt->method('fetch')->willReturn(['owner_count' => 1, 'group_count' => 0]);
        $this->db->method('prepare')->willReturn($ownerStmt);

        // SOA read succeeds (one SOA record), leaving no recorded API error.
        $backend->method('getRecordsByZoneId')->willReturn([
            ['id' => 'enc', 'type' => 'SOA'],
        ]);

        $service = new DatabaseConsistencyService($this->db, $this->config, $backend);
        $results = $service->runAllChecks();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('zones_have_owners', $results);
        $this->assertArrayHasKey('zones_without_soa', $results);
    }
}
