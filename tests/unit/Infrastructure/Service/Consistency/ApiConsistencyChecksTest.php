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

namespace Poweradmin\Tests\Unit\Infrastructure\Service\Consistency;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Domain\Port\ApiStatusInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Service\Consistency\ApiConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * Pins every check and fix of the API backend strategy: zone and record state
 * comes from a stubbed backend provider, ownership from an in-memory SQLite
 * copy of the zones and zones_groups tables.
 */
#[CoversClass(ApiConsistencyChecks::class)]
class ApiConsistencyChecksTest extends TestCase
{
    private PDO $db;

    /** @var DnsBackendProviderInterface&MockObject */
    private DnsBackendProviderInterface $provider;

    /** @var array<int, list<array<string, mixed>>> */
    private array $records = [];

    /** @var list<int> */
    private array $deletedRecords = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NULL, zone_templ_id INTEGER DEFAULT 0, zone_name TEXT NULL)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");

        $this->provider = $this->createMock(DnsBackendProviderInterface::class);
        $this->provider->method('getRecordsByZoneId')->willReturnCallback(
            fn(int $zoneId, ?string $type = null): array => array_values(array_filter(
                $this->records[$zoneId] ?? [],
                fn(array $r): bool => $type === null || $r['type'] === $type
            ))
        );
        $this->provider->method('deleteRecord')->willReturnCallback(function (int|string $id): bool {
            $this->deletedRecords[] = (int)$id;
            return true;
        });

        (new ApiStatusService(new PhpSession()))->clearError();
    }

    protected function tearDown(): void
    {
        (new ApiStatusService(new PhpSession()))->clearError();
    }

    private function checker(?ApiStatusInterface $apiStatus = null): ApiConsistencyChecks
    {
        return new ApiConsistencyChecks($this->db, $this->provider, $apiStatus ?? new ApiStatusService(new PhpSession()), new ZoneOwnerRepair($this->db));
    }

    /** @param list<array<string, mixed>> $zones */
    private function zones(array $zones): void
    {
        $this->provider->method('getZones')->willReturn($zones);
    }

    private function zoneRow(int $id, ?int $domainId, ?int $owner, ?string $zoneName): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $domainId, $owner, $zoneName]);
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    }

    public function testZonesHaveOwnersPassesWithADirectOwnerOrAGroupLink(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'owned.example.com.', 'type' => 'NATIVE'],
            ['id' => 2, 'name' => 'grouped.example.com.', 'type' => 'NATIVE'],
            ['id' => 0, 'name' => 'unsynced.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->zoneRow(1, 1, 5, 'owned.example.com');
        $this->zoneRow(2, 2, null, 'grouped.example.com');
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, 3)");

        $result = $this->checker()->checkZonesHaveOwners();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have owners', 'data' => []], $result);
    }

    public function testZonesHaveOwnersFlagsZonesWithNoOwnerRowOrOnlyOwnerZeroRows(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'norow.example.com.', 'type' => 'NATIVE'],
            ['id' => 2, 'name' => 'zero.example.com.', 'type' => 'NATIVE'],
            ['id' => 3, 'name' => 'owned.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->zoneRow(2, 2, 0, 'zero.example.com');
        $this->zoneRow(3, 3, 7, 'owned.example.com');

        $result = $this->checker()->checkZonesHaveOwners();

        $this->assertSame('warning', $result['status']);
        $this->assertSame('2 zones found without owners', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'norow.example.com', 'owner' => null],
            ['id' => 2, 'name' => 'zero.example.com', 'owner' => null],
        ], $result['data']);
    }

    public function testZonesHaveOwnersCountsOwnersOnExtraRowsKeyedByTheCanonicalId(): void
    {
        $this->zones([['id' => 10, 'name' => 'shared.example.com.', 'type' => 'NATIVE']]);
        $this->zoneRow(10, 10, null, 'shared.example.com');
        $this->zoneRow(11, 10, 8, null);

        $this->assertSame('success', $this->checker()->checkZonesHaveOwners()['status']);
    }

    public function testCanonicalIdCheckFlagsZeroAndNullDomainIds(): void
    {
        $this->zoneRow(1, 0, 1, 'zero.example.com');
        $this->zoneRow(2, null, 1, 'null.example.com');
        $this->zoneRow(3, 3, 1, 'healthy.example.com');
        $this->zoneRow(4, null, 1, null);

        $result = $this->checker()->checkZonesHaveCanonicalIds();

        $this->assertSame('warning', $result['status']);
        $this->assertSame('2 zones found without a canonical ID', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'zero.example.com', 'domain_id' => 0],
            ['id' => 2, 'name' => 'null.example.com', 'domain_id' => null],
        ], $result['data']);
    }

    public function testCanonicalIdCheckPassesWhenEveryRowResolves(): void
    {
        $this->zoneRow(3, 3, 1, 'healthy.example.com');

        $result = $this->checker()->checkZonesHaveCanonicalIds();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have a canonical ID', 'data' => []], $result);
    }

    public function testFixZoneCanonicalIdRepairsOnlyStrandedNamedRows(): void
    {
        $this->zoneRow(1, 0, 1, 'zero.example.com');
        $this->zoneRow(4, 201, 1, 'migrated.example.com');
        $this->zoneRow(5, null, 1, null);

        $checker = $this->checker();

        $this->assertTrue($checker->fixZoneCanonicalId(1));
        $this->assertFalse($checker->fixZoneCanonicalId(1));
        $this->assertFalse($checker->fixZoneCanonicalId(4));
        $this->assertFalse($checker->fixZoneCanonicalId(5));
        $this->assertFalse($checker->fixZoneCanonicalId(0));
        $this->assertSame(1, $this->rows('zones', 'id = 1 AND domain_id = 1'));
        $this->assertSame(1, $this->rows('zones', 'id = 4 AND domain_id = 201'));
        $this->assertSame(1, $this->rows('zones', 'id = 5 AND domain_id IS NULL'));
    }

    public function testFixAllZonesWithCanonicalIdIssueCountsRepairs(): void
    {
        $this->zoneRow(1, 0, 1, 'zero.example.com');
        $this->zoneRow(2, null, 1, 'null.example.com');

        $checker = $this->checker();

        $this->assertSame(['fixed' => 2, 'failed' => 0], $checker->fixAllZonesWithCanonicalIdIssue());
        $this->assertSame(['fixed' => 0, 'failed' => 0], $checker->fixAllZonesWithCanonicalIdIssue());
    }

    public function testSlaveZonesHaveMastersReadsTheMasterFromTheZoneList(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'ok.example.com.', 'type' => 'Slave', 'master' => '192.0.2.1'],
            ['id' => 2, 'name' => 'empty.example.com.', 'type' => 'SLAVE', 'master' => ''],
            ['id' => 3, 'name' => 'zero.example.com.', 'type' => 'SLAVE', 'master' => '0.0.0.0'],
            ['id' => 4, 'name' => 'missing.example.com.', 'type' => 'SLAVE'],
            ['id' => 5, 'name' => 'native.example.com.', 'type' => 'NATIVE'],
        ]);

        $result = $this->checker()->checkSlaveZonesHaveMasters();

        $this->assertSame('warning', $result['status']);
        $this->assertSame('3 slave zones found without master IP addresses', $result['message']);
        $this->assertSame([
            ['id' => 2, 'name' => 'empty.example.com', 'master' => ''],
            ['id' => 3, 'name' => 'zero.example.com', 'master' => '0.0.0.0'],
            ['id' => 4, 'name' => 'missing.example.com', 'master' => ''],
        ], $result['data']);
    }

    public function testSlaveZonesHaveMastersPassesWhenEverySlaveHasAMaster(): void
    {
        $this->zones([['id' => 1, 'name' => 'ok.example.com.', 'type' => 'SLAVE', 'master' => '192.0.2.1']]);

        $result = $this->checker()->checkSlaveZonesHaveMasters();

        $this->assertSame(['status' => 'success', 'message' => 'All slave zones have master IP addresses', 'data' => []], $result);
    }

    public function testRecordsBelongToZonesAlwaysPassesInApiMode(): void
    {
        $result = $this->checker()->checkRecordsBelongToZones();

        $this->assertSame(['status' => 'success', 'message' => 'All records belong to existing zones', 'data' => []], $result);
    }

    public function testDuplicateSoaCheckCountsSoaRecordsPerSyncedZone(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'dup.example.com.', 'type' => 'NATIVE'],
            ['id' => 2, 'name' => 'clean.example.com.', 'type' => 'NATIVE'],
            ['id' => 0, 'name' => 'unsynced.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->records[1] = [['id' => 11, 'type' => 'SOA'], ['id' => 12, 'type' => 'SOA']];
        $this->records[2] = [['id' => 21, 'type' => 'SOA']];

        $result = $this->checker()->checkDuplicateSOARecords();

        $this->assertSame('error', $result['status']);
        $this->assertSame('1 zones have duplicate SOA records', $result['message']);
        $this->assertSame([['zone_id' => 1, 'zone_name' => 'dup.example.com', 'soa_count' => 2]], $result['data']);
    }

    public function testDuplicateSoaCheckPassesWithOneSoaPerZone(): void
    {
        $this->zones([['id' => 2, 'name' => 'clean.example.com.', 'type' => 'NATIVE']]);
        $this->records[2] = [['id' => 21, 'type' => 'SOA']];

        $result = $this->checker()->checkDuplicateSOARecords();

        $this->assertSame(['status' => 'success', 'message' => 'No duplicate SOA records found', 'data' => []], $result);
    }

    public function testZonesWithoutSoaReportsMasterAndNativeZonesButSkipsSlavesAndUnsynced(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'master.example.com.', 'type' => 'Master'],
            ['id' => 2, 'name' => 'native.example.com.', 'type' => 'NATIVE'],
            ['id' => 3, 'name' => 'slave.example.com.', 'type' => 'SLAVE'],
            ['id' => 0, 'name' => 'unsynced.example.com.', 'type' => 'NATIVE'],
            ['id' => 4, 'name' => 'fine.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->records[4] = [['id' => 41, 'type' => 'SOA']];

        $result = $this->checker()->checkZonesWithoutSOA();

        $this->assertSame('error', $result['status']);
        $this->assertSame('2 zones found without SOA records', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'master.example.com', 'type' => 'MASTER'],
            ['id' => 2, 'name' => 'native.example.com', 'type' => 'NATIVE'],
        ], $result['data']);
    }

    public function testZonesWithoutSoaPassesWhenEveryZoneHasOne(): void
    {
        $this->zones([['id' => 4, 'name' => 'fine.example.com.', 'type' => 'NATIVE']]);
        $this->records[4] = [['id' => 41, 'type' => 'SOA']];

        $result = $this->checker()->checkZonesWithoutSOA();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have SOA records', 'data' => []], $result);
    }

    public function testRunAllChecksFetchesTheZoneListOnceAndReturnsEveryCheck(): void
    {
        $this->provider->expects($this->once())->method('getZones')->willReturn([
            ['id' => 4, 'name' => 'fine.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->zoneRow(4, 4, 5, 'fine.example.com');
        $this->records[4] = [['id' => 41, 'type' => 'SOA']];

        $results = $this->checker()->runAllChecks();

        $this->assertSame([
            'zones_have_owners',
            'zones_have_canonical_ids',
            'slave_zones_have_masters',
            'records_belong_to_zones',
            'duplicate_soa_records',
            'zones_without_soa',
        ], array_keys($results));
        foreach ($results as $result) {
            $this->assertSame('success', $result['status']);
        }
    }

    public function testRunAllChecksReturnsNullWhenTheZoneListIsUnavailable(): void
    {
        $this->zones([]);
        (new ApiStatusService(new PhpSession()))->recordError('connection refused', ['endpoint' => 'zones']);

        $this->assertNull($this->checker()->runAllChecks());
    }

    public function testRunAllChecksReturnsNullWhenAPerZoneReadFails(): void
    {
        $this->zones([['id' => 4, 'name' => 'fine.example.com.', 'type' => 'NATIVE']]);
        $this->zoneRow(4, 4, 5, 'fine.example.com');
        $apiStatus = $this->createMock(ApiStatusInterface::class);
        $apiStatus->method('getLastError')->willReturn(['message' => '502 Bad Gateway', 'context' => [], 'timestamp' => 1]);

        $this->assertNull($this->checker($apiStatus)->runAllChecks());
    }

    public function testFixZoneWithoutOwnerWritesTheZonesRow(): void
    {
        $this->zoneRow(1, 1, null, 'update.example.com');

        $checker = $this->checker();

        $this->assertTrue($checker->fixZoneWithoutOwner(1, 9));
        $this->assertTrue($checker->fixZoneWithoutOwner(2, 9));
        $this->assertFalse($checker->fixZoneWithoutOwner(0, 9));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 1 AND owner = 9'));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 2 AND owner = 9'));
    }

    public function testFixAllZonesWithoutOwnerAssignsEveryOrphan(): void
    {
        $this->zones([
            ['id' => 1, 'name' => 'a.example.com.', 'type' => 'NATIVE'],
            ['id' => 2, 'name' => 'b.example.com.', 'type' => 'NATIVE'],
            ['id' => 3, 'name' => 'owned.example.com.', 'type' => 'NATIVE'],
        ]);
        $this->zoneRow(1, 1, null, 'a.example.com');
        $this->zoneRow(2, 2, null, 'b.example.com');
        $this->zoneRow(3, 3, 4, 'owned.example.com');

        $checker = $this->checker();

        $this->assertSame(['assigned' => 2, 'failed' => 0], $checker->fixAllZonesWithoutOwner(9));
        $this->assertSame(['assigned' => 0, 'failed' => 0], $checker->fixAllZonesWithoutOwner(9));
        $this->assertSame(2, $this->rows('zones', 'owner = 9'));
    }

    public function testDeleteSlaveZoneDeletesThroughTheApiThenTheOwnershipRows(): void
    {
        $this->provider->method('getZoneNameById')->willReturn('slave.example.com');
        $this->provider->expects($this->once())->method('deleteZone')->with(1, 'slave.example.com')->willReturn(true);
        $this->zoneRow(1, 1, 5, 'slave.example.com');
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (1, 2)");
        $this->zoneRow(2, 2, 5, 'keep.example.com');

        $this->assertTrue($this->checker()->deleteSlaveZone(1));

        $this->assertSame(0, $this->rows('zones', 'domain_id = 1'));
        $this->assertSame(0, $this->rows('zones_groups'));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 2'));
    }

    public function testDeleteSlaveZoneKeepsOwnershipWhenTheApiDeleteFails(): void
    {
        $this->provider->method('getZoneNameById')->willReturn(null);
        $this->provider->method('deleteZone')->with(1, '')->willReturn(false);
        $this->zoneRow(1, 1, 5, 'slave.example.com');

        $this->assertFalse($this->checker()->deleteSlaveZone(1));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 1'));
    }

    public function testDeleteSlaveZoneReportsFailureWhenTheOwnershipDeleteFails(): void
    {
        $this->provider->method('getZoneNameById')->willReturn('slave.example.com');
        $this->provider->method('deleteZone')->willReturn(true);
        $this->db->exec("DROP TABLE zones_groups");

        $this->assertFalse($this->checker()->deleteSlaveZone(1));
    }

    public function testDeleteOrphanedRecordGoesThroughTheProvider(): void
    {
        $this->assertTrue($this->checker()->deleteOrphanedRecord(77));
        $this->assertSame([77], $this->deletedRecords);
    }

    public function testFixDuplicateSoaKeepsTheFirstListedRecordAndDeletesTheRest(): void
    {
        $this->records[1] = [
            ['id' => 11, 'type' => 'SOA'],
            ['id' => 12, 'type' => 'SOA'],
            ['id' => 0, 'type' => 'SOA'],
            ['id' => 13, 'type' => 'SOA'],
        ];

        $this->assertTrue($this->checker()->fixDuplicateSOA(1));
        $this->assertSame([12, 13], $this->deletedRecords);
    }

    public function testFixDuplicateSoaIsANoOpWithAtMostOneSoa(): void
    {
        $this->records[1] = [['id' => 11, 'type' => 'SOA']];

        $this->assertTrue($this->checker()->fixDuplicateSOA(1));
        $this->assertTrue($this->checker()->fixDuplicateSOA(2));
        $this->assertSame([], $this->deletedRecords);
    }

    public function testCreateDefaultSoaAddsTheRecordThroughTheProvider(): void
    {
        $this->provider->method('getZoneNameById')->willReturn('example.com');
        $this->provider->expects($this->once())->method('addRecordGetId')
            ->with(1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com ' . date('Ymd') . '01 28800 7200 604800 86400', 86400, 0)
            ->willReturn('example.com./SOA');

        $this->assertTrue($this->checker()->createDefaultSOA(1));
    }

    public function testCreateDefaultSoaFailsWhenTheZoneIsUnknownOrTheAddFails(): void
    {
        $this->provider->method('getZoneNameById')->willReturnMap([[1, null], [2, 'example.com']]);
        $this->provider->method('addRecordGetId')->willReturn(null);

        $this->assertFalse($this->checker()->createDefaultSOA(1));
        $this->assertFalse($this->checker()->createDefaultSOA(2));
    }
}
