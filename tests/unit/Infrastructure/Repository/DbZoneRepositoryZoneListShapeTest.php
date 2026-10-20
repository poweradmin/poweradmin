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
use Poweradmin\Domain\Enum\ZoneSoaHealth;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * The rows the reverse zone list and the internal zone list receive from the SQL
 * backend. ZoneSummary::toArray() reproduces the column-keyed rows with two
 * deliberate differences from the raw driver rows: secured is a bool rather than
 * the probe's 0/1, and the optional serial and template keys follow the shared
 * order (serial before template).
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryZoneListShapeTest extends TestCase
{
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "INSERT INTO domains VALUES
                    (1, 'signed.example', 'MASTER', NULL, 'ops'),
                    (2, 'plain.example', 'NATIVE', NULL, ''),
                    (3, '2.0.192.in-addr.arpa', 'NATIVE', NULL, '')",
                "INSERT INTO records VALUES
                    (10, 1, 'signed.example', 'SOA', 'ns1.signed.example hostmaster.signed.example 2024010101 1 1 1 1', 0),
                    (11, 1, 'signed.example', 'NS', 'ns1.signed.example', 0),
                    (12, 1, 'www.signed.example', 'A', '192.0.2.1', 0),
                    (13, 1, 'ent.signed.example', NULL, NULL, 0),
                    (20, 2, 'plain.example', 'SOA', 'ns1 hostmaster 5 1 1 1 1', 0),
                    (30, 3, '2.0.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 7 1 1 1 1', 0),
                    (31, 3, '1.2.0.192.in-addr.arpa', 'PTR', 'www.signed.example', 0)",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zone_templ VALUES (1, 'Basic')",
                "INSERT INTO zones VALUES (1, 1, 5, 'signed zone', 1), (2, 1, 6, NULL, 0), (3, 2, NULL, NULL, 0), (4, 3, 5, 'reverse', 1)",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 1, 'ksk'), (2, 1, 256, 1, 'zsk')",
                "INSERT INTO domainmetadata VALUES (1, 1, 'PRESIGNED', '1')",
            ] as $sql
        ) {
            $db->exec($sql);
        }

        $this->repository = new DbZoneRepository($db, new FakeConfiguration(['database' => ['type' => 'sqlite']]));
    }

    public function testReverseListWithSerialAndTemplate(): void
    {
        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 25, 'name', 'ASC', false, true, true);

        $this->assertSame([
            '2.0.192.in-addr.arpa' => [
                'id' => 3,
                'name' => '2.0.192.in-addr.arpa',
                'utf8_name' => '2.0.192.in-addr.arpa',
                'type' => 'NATIVE',
                'count_records' => 2,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'ok',
                'comment' => 'reverse',
                'secured' => false,
                'owners' => ['alice'],
                'full_names' => ['Alice A'],
                'users' => ['alice'],
                'serial' => '7',
                'template' => 'Basic',
            ],
        ], $this->toRows($zones));

        $zone = $zones['2.0.192.in-addr.arpa'];
        $this->assertSame(3, $zone->id);
        $this->assertSame(2, $zone->recordCount);
        $this->assertSame(ZoneSoaHealth::OK, $zone->soaHealth);
        $this->assertSame('7', $zone->serial);
        $this->assertSame('Basic', $zone->template);
        $this->assertNull($zone->signedSerial);
    }

    public function testReverseListWithoutOptionalColumns(): void
    {
        $rows = $this->toRows($this->repository->getReverseZones('all', 5, 'all', 0, 25, 'name', 'ASC', false, false, false, false, false));

        $this->assertSame([
            'id' => 3,
            'name' => '2.0.192.in-addr.arpa',
            'utf8_name' => '2.0.192.in-addr.arpa',
            'type' => 'NATIVE',
            'count_records' => 0,
            'is_disabled' => false,
            'is_missing_soa' => false,
            'soa_health' => 'ok',
            'comment' => 'reverse',
            'secured' => false,
            'owners' => ['alice'],
            'full_names' => ['Alice A'],
            'users' => ['alice'],
        ], $rows['2.0.192.in-addr.arpa']);
    }

    public function testReverseListCountOnlyReturnsAnInteger(): void
    {
        $this->assertSame(1, $this->repository->getReverseZones('all', 5, 'all', 0, 25, 'name', 'ASC', true));
    }

    public function testInternalListZones(): void
    {
        $this->assertSame(self::internalRows(), $this->toRows($this->repository->listZones(5, true)));
    }

    /**
     * @param array<ZoneSummary> $zones
     * @return array<array<string, mixed>>
     */
    private function toRows(array $zones): array
    {
        return array_map(fn(ZoneSummary $zone): array => $zone->toArray(), $zones);
    }

    /**
     * The internal API serialises the list as returned, so the JSON is the snapshot's.
     */
    public function testInternalListZonesSerialisesAsTheSnapshotRows(): void
    {
        $this->assertSame(json_encode(self::internalRows()), json_encode($this->repository->listZones(5, true)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function internalRows(): array
    {
        return [
            [
                'id' => 3,
                'name' => '2.0.192.in-addr.arpa',
                'utf8_name' => '2.0.192.in-addr.arpa',
                'type' => 'NATIVE',
                'count_records' => 2,
                'comment' => 'reverse',
                'secured' => false,
                'owners' => ['alice'],
                'full_names' => ['Alice A'],
                'users' => ['alice'],
            ],
            [
                'id' => 2,
                'name' => 'plain.example',
                'utf8_name' => 'plain.example',
                'type' => 'NATIVE',
                'count_records' => 1,
                'comment' => '',
                'secured' => false,
                'owners' => [],
                'full_names' => [],
                'users' => [],
            ],
            [
                'id' => 1,
                'name' => 'signed.example',
                'utf8_name' => 'signed.example',
                'type' => 'MASTER',
                'count_records' => 3,
                'comment' => 'signed zone',
                'secured' => true,
                'owners' => ['alice', 'bob'],
                'full_names' => ['Alice A', ''],
                'users' => ['alice', 'bob'],
            ],
        ];
    }
}
