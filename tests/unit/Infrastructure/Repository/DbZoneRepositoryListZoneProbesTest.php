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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * listZones() and getReverseZones() derive count_records and secured from
 * correlated probes. A zone with several owners, several active keys and
 * PRESIGNED metadata must still report its true record count (ENT rows
 * excluded) and secured=1, and the count must stay sortable.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryListZoneProbesTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT)",
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                // Zone 1: two owners, two active keys, PRESIGNED, one ENT row.
                // Zone 2: one owner, unsigned, one ENT row. Zone 3: unowned, unsigned, no ENT.
                "INSERT INTO domains VALUES
                    (1, '2.0.192.in-addr.arpa', 'MASTER', NULL, ''),
                    (2, '3.0.192.in-addr.arpa', 'MASTER', NULL, ''),
                    (3, '4.0.192.in-addr.arpa', 'NATIVE', NULL, '')",
                "INSERT INTO records (id, domain_id, name, type, content) VALUES
                    (10, 1, '2.0.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
                    (11, 1, '2.0.192.in-addr.arpa', 'NS', 'ns1.example'),
                    (12, 1, '1.2.0.192.in-addr.arpa', 'PTR', 'a.example'),
                    (13, 1, '2.2.0.192.in-addr.arpa', 'PTR', 'b.example'),
                    (14, 1, 'ent.2.0.192.in-addr.arpa', NULL, NULL),
                    (20, 2, '3.0.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
                    (21, 2, 'ent.3.0.192.in-addr.arpa', NULL, NULL),
                    (30, 3, '4.0.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
                    (31, 3, '4.0.192.in-addr.arpa', 'NS', 'ns1.example'),
                    (32, 3, '1.4.0.192.in-addr.arpa', 'PTR', 'c.example')",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zones VALUES (1, 1, 5, 'signed zone'), (2, 1, 6, 'signed zone'), (3, 2, 5, NULL)",
                "INSERT INTO user_groups VALUES (1, 'ops'), (2, 'dns')",
                "INSERT INTO zones_groups VALUES (1, 1), (1, 2)",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 1, 'ksk'), (2, 1, 256, 1, 'zsk'), (3, 1, 256, 0, 'retired'), (4, 2, 256, 0, 'inactive')",
                "INSERT INTO domainmetadata VALUES (1, 1, 'PRESIGNED', '1'), (2, 1, 'SOA-EDIT-API', 'DEFAULT'), (3, 2, 'SOA-EDIT-API', 'DEFAULT')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new DbZoneRepository($this->db, $config);
    }

    public function testListZonesCountsRecordsOnceAcrossOwnersAndKeysAndSkipsEntRows(): void
    {
        $zones = array_column($this->repository->listZones(), null, 'name');

        $this->assertSame(4, (int)$zones['2.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(1, (int)$zones['2.0.192.in-addr.arpa']['secured']);
        $this->assertSame(['alice', 'bob'], $zones['2.0.192.in-addr.arpa']['owners']);
        $this->assertSame(['Alice A', ''], $zones['2.0.192.in-addr.arpa']['full_names']);
        $this->assertSame('signed zone', $zones['2.0.192.in-addr.arpa']['comment']);

        $this->assertSame(1, (int)$zones['3.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(0, (int)$zones['3.0.192.in-addr.arpa']['secured']);
        $this->assertSame(['alice'], $zones['3.0.192.in-addr.arpa']['owners']);

        $this->assertSame(3, (int)$zones['4.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(0, (int)$zones['4.0.192.in-addr.arpa']['secured']);
        $this->assertSame([], $zones['4.0.192.in-addr.arpa']['owners']);
    }

    public function testListZonesKeepsItsOutputKeys(): void
    {
        $zone = $this->repository->listZones()[0];

        $this->assertSame(
            ['id', 'name', 'utf8_name', 'type', 'count_records', 'comment', 'secured', 'owners', 'full_names', 'users'],
            array_keys($zone)
        );
    }

    public function testListZonesOwnFilterCoversGroupOwnership(): void
    {
        $this->db->exec("INSERT INTO users VALUES (7, 'carol', 'Carol C')");
        $this->db->exec("INSERT INTO user_group_members VALUES (7, 2)");

        $names = array_column($this->repository->listZones(7, false), 'name');

        $this->assertSame(['2.0.192.in-addr.arpa'], $names);
    }

    public function testReverseZonesCountRecordsOnceAcrossOwnersAndKeysAndSkipEntRows(): void
    {
        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'name', 'ASC');

        $this->assertSame(['2.0.192.in-addr.arpa', '3.0.192.in-addr.arpa', '4.0.192.in-addr.arpa'], array_keys($zones));
        $this->assertSame(4, (int)$zones['2.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(1, (int)$zones['2.0.192.in-addr.arpa']['secured']);
        $this->assertSame(['alice', 'bob'], $zones['2.0.192.in-addr.arpa']['owners']);
        $this->assertSame(1, (int)$zones['3.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(0, (int)$zones['3.0.192.in-addr.arpa']['secured']);
        $this->assertSame(3, (int)$zones['4.0.192.in-addr.arpa']['count_records']);
    }

    public function testReverseZonesKeepTheirOutputKeysWithHealth(): void
    {
        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'name', 'ASC');
        $zone = $zones['2.0.192.in-addr.arpa'];

        $this->assertArrayHasKey('id', $zone);
        $this->assertArrayHasKey('utf8_name', $zone);
        $this->assertArrayHasKey('type', $zone);
        $this->assertArrayHasKey('count_records', $zone);
        $this->assertArrayHasKey('comment', $zone);
        $this->assertArrayHasKey('secured', $zone);
        $this->assertArrayHasKey('owners', $zone);
        $this->assertArrayHasKey('full_names', $zone);
        $this->assertArrayHasKey('users', $zone);
        $this->assertArrayHasKey('is_disabled', $zone);
        $this->assertArrayHasKey('is_missing_soa', $zone);
        $this->assertFalse($zone['is_disabled']);
        $this->assertFalse($zone['is_missing_soa']);
    }

    public function testReverseZonesHealthFlagsStillWorkWithoutTheRecordsJoin(): void
    {
        $this->db->exec("UPDATE records SET disabled = 1 WHERE id = 10");
        $this->db->exec("DELETE FROM records WHERE id = 30");

        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'name', 'ASC');

        $this->assertTrue($zones['2.0.192.in-addr.arpa']['is_disabled']);
        $this->assertFalse($zones['2.0.192.in-addr.arpa']['is_missing_soa']);
        $this->assertTrue($zones['4.0.192.in-addr.arpa']['is_missing_soa']);
    }

    public function testReverseZonesSortByRecordCount(): void
    {
        $desc = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'count_records', 'DESC');
        $this->assertSame(['2.0.192.in-addr.arpa', '4.0.192.in-addr.arpa', '3.0.192.in-addr.arpa'], array_keys($desc));

        $asc = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'count_records', 'ASC');
        $this->assertSame(['3.0.192.in-addr.arpa', '4.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'], array_keys($asc));
    }

    public function testReverseZonesSortByGroupKeepsOneRowPerOwner(): void
    {
        // DESC so the grouped zone leads regardless of where the driver places NULL group names
        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'group', 'DESC');

        $this->assertCount(3, $zones);
        $this->assertSame('2.0.192.in-addr.arpa', array_key_first($zones));
        $this->assertSame(['alice', 'bob'], $zones['2.0.192.in-addr.arpa']['owners']);
        $this->assertSame(4, (int)$zones['2.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(1, (int)$zones['2.0.192.in-addr.arpa']['secured']);
    }

    public function testReverseZonesWithoutRecordCountFallBackToZero(): void
    {
        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'count_records', 'DESC', false, false, false, true, false);

        // count_records is dropped from the sort keys, so the list falls back to name
        $this->assertSame(['4.0.192.in-addr.arpa', '3.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'], array_keys($zones));
        $this->assertSame(0, $zones['2.0.192.in-addr.arpa']['count_records']);
        $this->assertSame(1, (int)$zones['2.0.192.in-addr.arpa']['secured']);
    }

    public function testReverseZonesOwnFilterAndCount(): void
    {
        $own = $this->repository->getReverseZones('own', 6, 'ipv4', 0, 100, 'name', 'ASC');
        $this->assertSame(['2.0.192.in-addr.arpa'], array_keys($own));

        $this->assertSame(1, $this->repository->getReverseZones('own', 6, 'ipv4', 0, 100, 'name', 'ASC', true));
        $this->assertSame(3, $this->repository->getReverseZones('all', 6, 'all', 0, 100, 'name', 'ASC', true));
    }
}
