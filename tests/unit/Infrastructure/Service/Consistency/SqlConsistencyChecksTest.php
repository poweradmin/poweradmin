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

use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Infrastructure\Service\Consistency\SqlConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;
use TestHelpers\FakeConfiguration;

/**
 * Pins every check and fix of the SQL backend strategy against an in-memory
 * SQLite copy of the domains, records, zones and zones_groups tables.
 */
#[CoversClass(SqlConsistencyChecks::class)]
class SqlConsistencyChecksTest extends TestCase
{
    private PDO $db;

    /** @var array<string, mixed> */
    private array $dnsSettings = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NULL, zone_templ_id INTEGER DEFAULT 0, zone_name TEXT NULL, zone_type TEXT NULL, zone_master TEXT NULL)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER, UNIQUE (api_key_id, zone_id))");
        $this->db->exec("CREATE TABLE api_keys (id INTEGER PRIMARY KEY, name TEXT)");
    }

    private function checker(): SqlConsistencyChecks
    {
        return new SqlConsistencyChecks($this->db, $this->tableNames(), new ZoneOwnerRepair($this->db), $this->soaBuilder());
    }

    private function tableNames(): TableNameService
    {
        return new TableNameService(new FakeConfiguration(['database' => ['pdns_db_name' => '']]));
    }

    private function soaBuilder(): DefaultSoaBuilder
    {
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $this->dnsSettings[$key] ?? $default);

        return new DefaultSoaBuilder($config);
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
        $this->zoneRow(1, 5, 'example.com');
        $this->record(1, 1, 'example.com', 'SOA');

        $results = $this->checker()->runAllChecks();

        $this->assertSame([
            'zones_have_owners',
            'zones_have_canonical_ids',
            'zones_have_names',
            'shared_zone_ids',
            'group_grants_on_row_ids',
            'api_key_scopes_name_zones',
            'zone_names_differing_in_case',
            'record_names_in_lowercase',
            'slave_zones_have_masters',
            'records_belong_to_zones',
            'duplicate_soa_records',
            'zones_without_soa',
        ], array_keys($results));
        foreach ($results as $result) {
            $this->assertSame('success', $result['status']);
        }
    }

    public function testApiKeyRestrictionsOnDeletedZonesAreReportedAndPointedAtNoZone(): void
    {
        $this->domain(1, 'live.example');
        $this->db->exec("INSERT INTO api_keys (id, name) VALUES (1, 'acme'), (2, 'ddns'), (3, 'old')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 1), (1, 7), (2, 7), (3, 0), (3, 8)");
        $checker = $this->checker();

        $report = $checker->checkApiKeyScopesNameZones();
        $this->assertSame('warning', $report['status']);
        $this->assertSame([['id' => 7, 'keys' => 'acme, ddns'], ['id' => 8, 'keys' => 'old']], $report['data']);

        $this->assertSame('success', $checker->fixOne('api_key_scopes_on_missing_zones', 7, 0)['status']);
        $this->assertSame(['fixed' => 1, 'failed' => 0], $checker->fixAllApiKeyScopesOnMissingZones());
        $this->assertSame('success', $checker->checkApiKeyScopesNameZones()['status']);
        $this->assertSame(
            [[1, 0], [1, 1], [2, 0], [3, 0]],
            array_map(
                static fn(array $r): array => [(int)$r[0], (int)$r[1]],
                $this->db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_NUM)
            )
        );
    }

    public function testTheApiKeyRestrictionRepairLeavesAZoneThatExistsAgainAlone(): void
    {
        $this->domain(7, 'recreated.example');
        $this->db->exec("INSERT INTO api_keys (id, name) VALUES (1, 'acme')");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 7)");

        $this->assertSame('error', $this->checker()->fixOne('api_key_scopes_on_missing_zones', 7, 0)['status']);
        $this->assertSame([7], array_map('intval', $this->db->query("SELECT zone_id FROM api_key_zones")->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testZonesWithoutANamedRowAreReportedAndNamedByTheFix(): void
    {
        $this->domain(1, 'a.example', 'SLAVE', '192.0.2.1');
        $this->domain(2, 'b.example');
        $this->zoneRow(1, 5);
        $this->zoneRow(1, 6);
        $this->zoneRow(2, 5, 'b.example');
        $checker = $this->checker();

        $report = $checker->checkZonesHaveNames();
        $this->assertSame('warning', $report['status']);
        $this->assertSame([['id' => 1, 'name' => 'a.example']], $report['data']);

        $this->assertSame('success', $checker->fixOne('zones_without_names', 1, 0)['status']);
        $this->assertSame('success', $checker->checkZonesHaveNames()['status']);
        $this->assertSame(
            ['a.example', null],
            $this->db->query("SELECT zone_name FROM zones WHERE domain_id = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testAGroupOwnedZoneWithoutRowsAndAStaleNameAreReportedAndFixed(): void
    {
        $this->domain(1, 'group.example');
        $this->domain(2, 'renamed.example');
        $this->domain(3, 'unused.example');
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (1, 9)");
        $this->zoneRow(2, 5, 'old.example');
        $checker = $this->checker();

        $this->assertSame(
            [['id' => 1, 'name' => 'group.example'], ['id' => 2, 'name' => 'renamed.example']],
            $checker->checkZonesHaveNames()['data']
        );

        $this->assertSame(['fixed' => 2, 'failed' => 0], $checker->fixAllZonesWithoutName());
        $this->assertSame('success', $checker->checkZonesHaveNames()['status']);
        $this->assertSame(
            [[1, null, 'group.example'], [2, 5, 'renamed.example']],
            array_map(
                static fn(array $r): array => [(int)$r[0], $r[1] === null ? null : (int)$r[1], $r[2]],
                $this->db->query("SELECT domain_id, owner, zone_name FROM zones ORDER BY domain_id")->fetchAll(PDO::FETCH_NUM)
            )
        );
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

    public function testCreateDefaultSoaInsertsARecordFromTheConfiguredSoaSettings(): void
    {
        $this->dnsSettings = [
            'ns1' => 'ns.example.net',
            'hostmaster' => 'admin.example.net',
            'soa_refresh' => 1000,
            'soa_retry' => 2000,
            'soa_expire' => 3000,
            'soa_minimum' => 4000,
            'ttl' => 3600,
        ];
        $this->domain(1, 'example.com');

        $this->assertTrue($this->checker()->createDefaultSOA(1));

        $row = $this->db->query("SELECT domain_id, name, type, content, ttl, prio, disabled FROM records")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['domain_id']);
        $this->assertSame('example.com', $row['name']);
        $this->assertSame('SOA', $row['type']);
        $this->assertSame('ns.example.net admin.example.net ' . date('Ymd') . '00 1000 2000 3000 4000', $row['content']);
        $this->assertSame(3600, (int)$row['ttl']);
        $this->assertSame(0, (int)$row['prio']);
        $this->assertSame(0, (int)$row['disabled']);
    }

    public function testCreateDefaultSoaFailsForAnUnknownZone(): void
    {
        $this->assertFalse($this->checker()->createDefaultSOA(404));
        $this->assertSame(0, $this->rows('records'));
    }

    public function testTheOwnerRepairPrefersTheUserTheZoneAccountNames(): void
    {
        $this->db->exec("ALTER TABLE domains ADD COLUMN account TEXT NULL");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
        $this->db->exec("INSERT INTO users (id, username) VALUES (4, 'alice')");
        $this->db->exec("INSERT INTO domains (id, name, type, account) VALUES (1, 'auto.example', 'SLAVE', 'alice'), (2, 'other.example', 'SLAVE', 'bob')");
        $checker = new SqlConsistencyChecks($this->db, $this->tableNames(), new ZoneOwnerRepair($this->db), $this->soaBuilder(), new AccountOwnerLookup($this->db));

        $this->assertTrue($checker->fixZoneWithoutOwner(1, 9));
        $this->assertTrue($checker->fixZoneWithoutOwner(2, 9));

        $owners = $this->db->query('SELECT domain_id, owner FROM zones ORDER BY domain_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([1 => 4, 2 => 9], array_map('intval', $owners));
    }

    public function testTheOwnerRepairFallsBackToTheActingUserWhenTheAccountUserIsAtTheLimit(): void
    {
        $this->db->exec("ALTER TABLE domains ADD COLUMN account TEXT NULL");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
        $this->db->exec("INSERT INTO users (id, username) VALUES (4, 'alice')");
        $this->db->exec("INSERT INTO domains (id, name, type, account) VALUES (1, 'auto.example', 'SLAVE', 'alice')");
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->method('userRemaining')->with(4)->willReturn(0);
        $checker = new SqlConsistencyChecks($this->db, $this->tableNames(), new ZoneOwnerRepair($this->db), $this->soaBuilder(), new AccountOwnerLookup($this->db, $limits));

        $this->assertTrue($checker->fixZoneWithoutOwner(1, 9));

        $owners = $this->db->query('SELECT domain_id, owner FROM zones ORDER BY domain_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([1 => 9], array_map('intval', $owners));
    }

    public function testZoneNamesDifferingOnlyInCaseAreReportedOncePerName(): void
    {
        $this->domain(1, 'Example.com');
        $this->domain(2, 'example.com');
        $this->domain(3, 'other.example');
        $this->domain(4, 'EXAMPLE.COM');

        $result = $this->checker()->checkZoneNamesDifferingInCase();

        $this->assertSame('warning', $result['status']);
        $this->assertSame([['name' => 'example.com', 'zones' => 'EXAMPLE.COM, Example.com, example.com', 'ids' => '4, 1, 2']], $result['data']);
    }

    public function testZoneNamesInOneCaseOnlyPassTheCaseCheck(): void
    {
        $this->domain(1, 'example.com');
        $this->domain(2, 'example.org');

        $this->assertSame('success', $this->checker()->checkZoneNamesDifferingInCase()['status']);
    }

    public function testRecordNamesWithUppercaseLettersAreReported(): void
    {
        $this->domain(1, 'example.com');
        $this->record(10, 1, 'example.com', 'SOA');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'mail.example.com', 'A', '192.0.2.2');

        $result = $this->checker()->checkRecordNamesInLowercase();

        $this->assertSame('warning', $result['status']);
        $this->assertSame([['id' => 11, 'domain_id' => 1, 'zone_name' => 'example.com', 'name' => 'WWW.example.com', 'type' => 'A']], $result['data']);
    }

    public function testLowercaseRecordNamesPassTheCaseCheck(): void
    {
        $this->domain(1, 'example.com');
        $this->record(10, 1, 'example.com', 'SOA');
        $this->record(11, 1, 'www.example.com', 'A', '192.0.2.1');

        $result = $this->checker()->checkRecordNamesInLowercase();

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['data']);
    }

    public function testTheRepairLowercasesTheRecordAndTheCommentsOnItsRrset(): void
    {
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)");
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (1, 1, 'WWW.example.com', 'A', 'web'), (2, 1, 'WWW.example.com', 'TXT', 'other type'), (3, 2, 'WWW.example.com', 'A', 'other zone')");
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'Mail.Example.com', 'A', '192.0.2.2');

        $outcome = $this->checker()->fixOne('records_with_uppercase_names', 11, 1);

        $this->assertSame(['status' => 'success', 'message' => 'Record name lowercased'], $outcome);
        $this->assertSame('www.example.com', $this->db->query('SELECT name FROM records WHERE id = 11')->fetchColumn());
        $this->assertSame(
            [1 => 'www.example.com', 2 => 'WWW.example.com', 3 => 'WWW.example.com'],
            $this->db->query('SELECT id, name FROM comments ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        $this->assertSame(['status' => 'success', 'message' => 'Lowercased the name of 1 records'], $this->checker()->fixAll('records_with_uppercase_names', 1));
        $this->assertSame('success', $this->checker()->checkRecordNamesInLowercase()['status']);
        $this->assertSame(['status' => 'error', 'message' => 'Failed to lowercase the record name'], $this->checker()->fixOne('records_with_uppercase_names', 11, 1));
    }

    private function commentTables(): void
    {
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)");
        $this->db->exec("CREATE TABLE record_comment_links (record_id VARCHAR(4096) NOT NULL, comment_id INTEGER NOT NULL, PRIMARY KEY (record_id), UNIQUE (comment_id))");
        $this->db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
    }

    public function testAMixedCaseCopyOfARecordAlreadyStoredLowercaseIsDeletedNotRenamed(): void
    {
        $this->commentTables();
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (1, 1, 'WWW.example.com', 'A', 'copy'), (2, 1, 'www.example.com', 'A', 'kept')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('11', 1), ('13', 2)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (1, 11, 3)");
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'Www.example.com', 'A', '192.0.2.1');
        $this->record(13, 1, 'www.example.com', 'A', '192.0.2.1');

        $outcome = $this->checker()->fixAll('records_with_uppercase_names', 1);

        $this->assertSame(['status' => 'success', 'message' => 'Lowercased the name of 2 records'], $outcome);
        $this->assertSame([[13, 'www.example.com']], $this->db->query('SELECT id, name FROM records')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([['13']], $this->db->query('SELECT record_id FROM record_comment_links')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([[2, 'www.example.com', 'kept']], $this->db->query('SELECT id, name, comment FROM comments')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame(0, $this->rows('records_zone_templ'));
    }

    public function testTwoMixedCaseVariantsOfOneRecordEndAsOneLowercaseRecord(): void
    {
        $this->commentTables();
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'Www.example.com', 'A', '192.0.2.1');

        $this->checker()->fixAll('records_with_uppercase_names', 1);

        $this->assertSame([[11, 'www.example.com']], $this->db->query('SELECT id, name FROM records')->fetchAll(PDO::FETCH_NUM));
    }

    public function testARenameThatWouldPutACnameNextToOtherDataIsRefused(): void
    {
        $this->commentTables();
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'Www.example.com', 'CNAME', 'host.example.net');
        $this->record(12, 1, 'www.example.com', 'A', '192.0.2.1');
        $this->record(13, 1, 'Mail.example.com', 'A', '192.0.2.2');
        $this->record(14, 1, 'mail.example.com', 'CNAME', 'host.example.net');
        $this->record(15, 1, 'Ftp.example.com', 'CNAME', 'one.example.net');
        $this->record(16, 1, 'ftp.example.com', 'CNAME', 'two.example.net');

        $this->assertFalse($this->checker()->fixRecordNameCase(11));
        $this->assertFalse($this->checker()->fixRecordNameCase(13));
        $this->assertFalse($this->checker()->fixRecordNameCase(15));
        $this->assertSame(3, $this->rows('records', "name IN ('Www.example.com', 'Mail.example.com', 'Ftp.example.com')"));
    }

    public function testACopyThatDiffersOnlyInPriorityOrDisabledStateIsRefused(): void
    {
        $this->commentTables();
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'Example.com', 'MX', 'mx.example.net');
        $this->db->exec("UPDATE records SET prio = 10 WHERE id = 11");
        $this->record(12, 1, 'example.com', 'MX', 'mx.example.net');
        $this->db->exec("UPDATE records SET prio = 20 WHERE id = 12");
        $this->record(13, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(14, 1, 'www.example.com', 'A', '192.0.2.1');
        $this->db->exec("UPDATE records SET disabled = 1 WHERE id = 14");

        $this->assertFalse($this->checker()->fixRecordNameCase(11));
        $this->assertFalse($this->checker()->fixRecordNameCase(13));
        $this->assertSame(4, $this->rows('records'));
    }

    public function testEveryCommentOnTheRrsetFollowsARenamedRecord(): void
    {
        $this->commentTables();
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (1, 1, 'WWW.example.com', 'A', 'mine'), (2, 1, 'www.example.com', 'A', 'other')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('11', 1)");
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'www.example.com', 'A', '192.0.2.2');

        $this->assertTrue($this->checker()->fixRecordNameCase(11));

        $this->assertSame([[1, 'www.example.com'], [2, 'www.example.com']], $this->db->query('SELECT id, name FROM comments ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([['11', 1]], $this->db->query('SELECT record_id, comment_id FROM record_comment_links')->fetchAll(PDO::FETCH_NUM));
    }

    public function testADeletedCopyHandsItsCommentToATwinThatHasNone(): void
    {
        $this->commentTables();
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (1, 1, 'WWW.example.com', 'A', 'web')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('11', 1)");
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(13, 1, 'www.example.com', 'A', '192.0.2.1');

        $this->assertTrue($this->checker()->fixRecordNameCase(11));

        $this->assertSame([[13]], $this->db->query('SELECT id FROM records')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([['13', 1]], $this->db->query('SELECT record_id, comment_id FROM record_comment_links')->fetchAll(PDO::FETCH_NUM));
        $this->assertSame([[1, 'www.example.com']], $this->db->query('SELECT id, name FROM comments')->fetchAll(PDO::FETCH_NUM));
    }

    public function testEmptyNonTerminalsPairOnlyWithEachOther(): void
    {
        $this->commentTables();
        $this->domain(1, 'example.com');
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES
            (11, 1, 'Sub.example.com', NULL, NULL, NULL, NULL), (12, 1, 'sub.example.com', 'CNAME', 'host.example.net', 3600, 0),
            (13, 1, 'Ent.example.com', NULL, NULL, NULL, NULL), (14, 1, 'ent.example.com', NULL, NULL, NULL, NULL)");

        $this->assertTrue($this->checker()->fixRecordNameCase(11));
        $this->assertTrue($this->checker()->fixRecordNameCase(13));

        $this->assertSame([[11, 'sub.example.com'], [12, 'sub.example.com'], [14, 'ent.example.com']], $this->db->query('SELECT id, name FROM records ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }

    public function testPostgresqlBooleanStringsCompareByValue(): void
    {
        $this->commentTables();
        $this->domain(1, 'example.com');
        $this->record(11, 1, 'WWW.example.com', 'A', '192.0.2.1');
        $this->record(12, 1, 'www.example.com', 'A', '192.0.2.1');
        // As PostgreSQL returns a boolean column: 'f' is false, not a non-empty string
        $this->db->exec("UPDATE records SET disabled = 'f' WHERE id = 11");

        $this->assertTrue($this->checker()->fixRecordNameCase(11));
        $this->assertSame([[12]], $this->db->query('SELECT id FROM records')->fetchAll(PDO::FETCH_NUM));
    }
}
