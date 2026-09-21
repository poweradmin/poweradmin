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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Service\Consistency\SqlConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;

/**
 * Pins every check and fix of the SQL backend strategy against an in-memory
 * SQLite copy of the domains, records, zones and zones_groups tables.
 */
#[CoversClass(SqlConsistencyChecks::class)]
class SqlConsistencyChecksTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NULL, zone_templ_id INTEGER DEFAULT 0, zone_name TEXT NULL)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
    }

    private function checker(): SqlConsistencyChecks
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        return new SqlConsistencyChecks($this->db, new TableNameService($config), new ZoneOwnerRepair($this->db));
    }

    private function domain(int $id, string $name, string $type = 'MASTER', ?string $master = null): void
    {
        $stmt = $this->db->prepare("INSERT INTO domains (id, name, type, master) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $type, $master]);
    }

    private function record(int $id, int $domainId, string $name, string $type, string $content = ''): void
    {
        $stmt = $this->db->prepare("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, 3600, 0)");
        $stmt->execute([$id, $domainId, $name, $type, $content]);
    }

    private function zoneRow(int $domainId, ?int $owner, ?string $zoneName = null): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, zone_name) VALUES (?, ?, ?)");
        $stmt->execute([$domainId, $owner, $zoneName]);
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    }

    public function testZonesHaveOwnersPassesWhenEveryDomainHasADirectOwnerOrGroup(): void
    {
        $this->domain(1, 'owned.example.com');
        $this->zoneRow(1, 5);
        $this->domain(2, 'grouped.example.com');
        $this->zoneRow(2, null);
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, 3)");

        $result = $this->checker()->checkZonesHaveOwners();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have owners', 'data' => []], $result);
    }

    public function testZonesHaveOwnersFlagsDomainsWithNoOwnerRowAndOwnerZeroRows(): void
    {
        $this->domain(1, 'norow.example.com');
        $this->domain(2, 'zero.example.com');
        $this->zoneRow(2, 0);
        $this->domain(3, 'owned.example.com');
        $this->zoneRow(3, 7);

        $result = $this->checker()->checkZonesHaveOwners();

        $this->assertSame('warning', $result['status']);
        $this->assertSame('2 zones found without owners', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'norow.example.com', 'owner' => null],
            ['id' => 2, 'name' => 'zero.example.com', 'owner' => null],
        ], $result['data']);
    }

    public function testCanonicalIdCheckAlwaysPassesInSqlMode(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 0, 1, 'stranded.example.com')");

        $result = $this->checker()->checkZonesHaveCanonicalIds();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have a canonical ID', 'data' => []], $result);
    }

    public function testCanonicalIdRepairIsRefusedInSqlMode(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 0, 1, 'stranded.example.com')");

        $checker = $this->checker();

        $this->assertFalse($checker->fixZoneCanonicalId(1));
        $this->assertSame(['fixed' => 0, 'failed' => 0], $checker->fixAllZonesWithCanonicalIdIssue());
        $this->assertSame(0, (int)$this->db->query("SELECT domain_id FROM zones WHERE id = 1")->fetchColumn());
    }

    public function testSlaveZonesHaveMastersPassesWhenEverySlaveHasAMaster(): void
    {
        $this->domain(1, 'slave.example.com', 'SLAVE', '192.0.2.1');
        $this->domain(2, 'master.example.com', 'MASTER');

        $result = $this->checker()->checkSlaveZonesHaveMasters();

        $this->assertSame(['status' => 'success', 'message' => 'All slave zones have master IP addresses', 'data' => []], $result);
    }

    public function testSlaveZonesHaveMastersFlagsNullEmptyAndZeroMasters(): void
    {
        $this->domain(1, 'null.example.com', 'SLAVE', null);
        $this->domain(2, 'empty.example.com', 'SLAVE', '');
        $this->domain(3, 'zero.example.com', 'SLAVE', '0.0.0.0');
        $this->domain(4, 'ok.example.com', 'SLAVE', '192.0.2.1');

        $result = $this->checker()->checkSlaveZonesHaveMasters();

        $this->assertSame('warning', $result['status']);
        $this->assertSame('3 slave zones found without master IP addresses', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'null.example.com', 'master' => null],
            ['id' => 2, 'name' => 'empty.example.com', 'master' => ''],
            ['id' => 3, 'name' => 'zero.example.com', 'master' => '0.0.0.0'],
        ], $result['data']);
    }

    public function testRecordsBelongToZonesPassesWhenEveryRecordHasADomain(): void
    {
        $this->domain(1, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA', 'ns1 hostmaster 1 2 3 4 5');

        $result = $this->checker()->checkRecordsBelongToZones();

        $this->assertSame(['status' => 'success', 'message' => 'All records belong to existing zones', 'data' => []], $result);
    }

    public function testRecordsBelongToZonesReportsOrphansAsErrors(): void
    {
        $this->domain(1, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA');
        $this->record(2, 99, 'lost.example.org', 'A', '192.0.2.9');

        $result = $this->checker()->checkRecordsBelongToZones();

        $this->assertSame('error', $result['status']);
        $this->assertSame('1 orphaned records found', $result['message']);
        $this->assertSame([
            ['id' => 2, 'name' => 'lost.example.org', 'type' => 'A', 'content' => '192.0.2.9', 'domain_id' => 99],
        ], $result['data']);
    }

    public function testDuplicateSoaCheckPassesWithOneSoaPerZone(): void
    {
        $this->domain(1, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA');

        $result = $this->checker()->checkDuplicateSOARecords();

        $this->assertSame(['status' => 'success', 'message' => 'No duplicate SOA records found', 'data' => []], $result);
    }

    public function testDuplicateSoaCheckReportsZoneNameAndCount(): void
    {
        $this->domain(1, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA');
        $this->record(2, 1, 'example.com', 'SOA');
        $this->record(3, 1, 'example.com', 'SOA');
        $this->domain(2, 'clean.example.com');
        $this->record(4, 2, 'clean.example.com', 'SOA');

        $result = $this->checker()->checkDuplicateSOARecords();

        $this->assertSame('error', $result['status']);
        $this->assertSame('1 zones have duplicate SOA records', $result['message']);
        $this->assertSame([['zone_id' => 1, 'zone_name' => 'example.com', 'soa_count' => 3]], $result['data']);
    }

    public function testZonesWithoutSoaIgnoresSlaveZones(): void
    {
        $this->domain(1, 'slave.example.com', 'SLAVE', '192.0.2.1');
        $this->domain(2, 'master.example.com', 'MASTER');
        $this->record(1, 2, 'master.example.com', 'SOA');

        $result = $this->checker()->checkZonesWithoutSOA();

        $this->assertSame(['status' => 'success', 'message' => 'All zones have SOA records', 'data' => []], $result);
    }

    public function testZonesWithoutSoaReportsMasterAndNativeZones(): void
    {
        $this->domain(1, 'master.example.com', 'MASTER');
        $this->domain(2, 'native.example.com', 'NATIVE');
        $this->record(1, 2, 'native.example.com', 'A', '192.0.2.1');

        $result = $this->checker()->checkZonesWithoutSOA();

        $this->assertSame('error', $result['status']);
        $this->assertSame('2 zones found without SOA records', $result['message']);
        $this->assertSame([
            ['id' => 1, 'name' => 'master.example.com', 'type' => 'MASTER'],
            ['id' => 2, 'name' => 'native.example.com', 'type' => 'NATIVE'],
        ], $result['data']);
    }

    public function testRunAllChecksReturnsEveryCheckKeyedByName(): void
    {
        $this->domain(1, 'example.com');
        $this->zoneRow(1, 5);
        $this->record(1, 1, 'example.com', 'SOA');

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

    public function testFixZoneWithoutOwnerUpdatesAnExistingRowAndInsertsOtherwise(): void
    {
        $this->domain(1, 'update.example.com');
        $this->zoneRow(1, null);
        $this->domain(2, 'insert.example.com');

        $checker = $this->checker();

        $this->assertTrue($checker->fixZoneWithoutOwner(1, 9));
        $this->assertTrue($checker->fixZoneWithoutOwner(2, 9));
        $this->assertFalse($checker->fixZoneWithoutOwner(0, 9));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 1 AND owner = 9'));
        $this->assertSame(1, $this->rows('zones', 'domain_id = 2 AND owner = 9 AND zone_templ_id = 0'));
    }

    public function testFixAllZonesWithoutOwnerCountsAssignedZones(): void
    {
        $this->domain(1, 'a.example.com');
        $this->domain(2, 'b.example.com');
        $this->domain(3, 'owned.example.com');
        $this->zoneRow(3, 4);

        $checker = $this->checker();

        $this->assertSame(['assigned' => 2, 'failed' => 0], $checker->fixAllZonesWithoutOwner(9));
        $this->assertSame(['assigned' => 0, 'failed' => 0], $checker->fixAllZonesWithoutOwner(9));
        $this->assertSame(2, $this->rows('zones', 'owner = 9'));
        $this->assertSame(1, $this->rows('zones', 'owner = 4'));
    }

    public function testDeleteSlaveZoneRemovesDomainRecordsAndOwnership(): void
    {
        $this->domain(1, 'slave.example.com', 'SLAVE', '');
        $this->record(1, 1, 'slave.example.com', 'NS', 'ns1');
        $this->zoneRow(1, 5);
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (1, 2)");
        $this->domain(2, 'keep.example.com');
        $this->record(2, 2, 'keep.example.com', 'SOA');
        $this->zoneRow(2, 5);

        $this->assertTrue($this->checker()->deleteSlaveZone(1));

        $this->assertSame(0, $this->rows('domains', 'id = 1'));
        $this->assertSame(0, $this->rows('records', 'domain_id = 1'));
        $this->assertSame(0, $this->rows('zones', 'domain_id = 1'));
        $this->assertSame(0, $this->rows('zones_groups', 'domain_id = 1'));
        $this->assertSame(1, $this->rows('domains'));
        $this->assertSame(1, $this->rows('records'));
        $this->assertSame(1, $this->rows('zones'));
    }

    public function testDeleteSlaveZoneRollsBackWhenAStatementFails(): void
    {
        $this->domain(1, 'slave.example.com', 'SLAVE', '');
        $this->record(1, 1, 'slave.example.com', 'NS', 'ns1');
        $this->zoneRow(1, 5);
        $this->db->exec("DROP TABLE zones_groups");

        $this->assertFalse($this->checker()->deleteSlaveZone(1));

        $this->assertSame(1, $this->rows('records', 'domain_id = 1'));
        $this->assertSame(1, $this->rows('domains', 'id = 1'));
    }

    public function testDeleteOrphanedRecordRemovesOnlyThatRecord(): void
    {
        $this->record(1, 99, 'lost.example.org', 'A');
        $this->record(2, 99, 'other.example.org', 'A');

        $this->assertTrue($this->checker()->deleteOrphanedRecord(1));

        $this->assertSame(0, $this->rows('records', 'id = 1'));
        $this->assertSame(1, $this->rows('records', 'id = 2'));
    }

    public function testFixDuplicateSoaKeepsTheLowestIdAndDeletesTheRest(): void
    {
        $this->domain(1, 'example.com');
        $this->record(5, 1, 'example.com', 'SOA', 'second');
        $this->record(3, 1, 'example.com', 'SOA', 'first');
        $this->record(8, 1, 'example.com', 'SOA', 'third');
        $this->record(9, 1, 'www.example.com', 'A', '192.0.2.1');

        $this->assertTrue($this->checker()->fixDuplicateSOA(1));

        $this->assertSame([3], array_map('intval', $this->db->query("SELECT id FROM records WHERE type = 'SOA'")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(1, $this->rows('records', 'id = 9'));
    }

    public function testFixDuplicateSoaIsANoOpWithAtMostOneSoa(): void
    {
        $this->domain(1, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA');

        $this->assertTrue($this->checker()->fixDuplicateSOA(1));
        $this->assertTrue($this->checker()->fixDuplicateSOA(2));
        $this->assertSame(1, $this->rows('records'));
    }

    public function testCreateDefaultSoaInsertsARecordNamedAfterTheZone(): void
    {
        $this->domain(1, 'example.com');

        $this->assertTrue($this->checker()->createDefaultSOA(1));

        $row = $this->db->query("SELECT domain_id, name, type, content, ttl, prio, disabled FROM records")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['domain_id']);
        $this->assertSame('example.com', $row['name']);
        $this->assertSame('SOA', $row['type']);
        $this->assertSame('ns1.example.com hostmaster.example.com ' . date('Ymd') . '01 28800 7200 604800 86400', $row['content']);
        $this->assertSame(86400, (int)$row['ttl']);
        $this->assertSame(0, (int)$row['prio']);
        $this->assertSame(0, (int)$row['disabled']);
    }

    public function testCreateDefaultSoaFailsForAnUnknownZone(): void
    {
        $this->assertFalse($this->checker()->createDefaultSOA(404));
        $this->assertSame(0, $this->rows('records'));
    }
}
