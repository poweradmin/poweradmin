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
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * The zone reads that join cryptokeys and domainmetadata must not multiply the
 * record count on signed zones, and getZone() must keep every key the internal
 * API and the metadata editor read while adding the getZoneById() core.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryGetZoneTest extends TestCase
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
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT)",
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "INSERT INTO domains VALUES (1, 'signed.example', 'MASTER', NULL, 'ops'), (2, 'plain.example', 'NATIVE', NULL, '')",
                "INSERT INTO records VALUES
                    (10, 1, 'signed.example', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
                    (11, 1, 'signed.example', 'NS', 'ns1.signed.example'),
                    (12, 1, 'www.signed.example', 'A', '192.0.2.1'),
                    (13, 1, 'ent.signed.example', NULL, NULL),
                    (20, 2, 'plain.example', 'SOA', 'ns1 hostmaster 1 1 1 1 1')",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zones VALUES (1, 1, 5, 'signed zone'), (2, 1, 6, NULL), (3, 2, NULL, NULL)",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 1, 'ksk'), (2, 1, 256, 1, 'zsk'), (3, 1, 256, 0, 'retired')",
                "INSERT INTO domainmetadata VALUES (1, 1, 'PRESIGNED', '1'), (2, 1, 'SOA-EDIT-API', 'DEFAULT')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new DbZoneRepository($this->db, $config);
    }

    public function testGetZoneReportsTheTrueRecordCountOnASignedZone(): void
    {
        $zone = $this->repository->getZone(1)?->toArray();

        // Empty non-terminals (NULL type) are PowerDNS bookkeeping, counted nowhere
        $this->assertNotNull($zone);
        $this->assertSame(3, (int)$zone['record_count']);
        $this->assertSame(3, (int)$zone['count_records']);
        $this->assertTrue($zone['secured']);
    }

    public function testGetZoneReturnsTheGetZoneByIdCorePlusTheWebExtras(): void
    {
        $zone = $this->repository->getZone(1)?->toArray();

        $this->assertNotNull($zone);
        $expectedKeys = [
            'id', 'name', 'type', 'master', 'account', 'owner', 'record_count',
            'count_records', 'username', 'fullname', 'secured', 'comment', 'utf8_name',
            'owners', 'full_names', 'users',
        ];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($zone));

        $this->assertSame('signed.example', $zone['name']);
        $this->assertSame('MASTER', $zone['type']);
        $this->assertSame('ops', $zone['account']);
        $this->assertSame(5, (int)$zone['owner']);
        $this->assertSame('alice', $zone['username']);
        $this->assertSame('signed zone', $zone['comment']);
        $this->assertSame('signed.example', $zone['utf8_name']);
        $this->assertSame(['alice', 'bob'], $zone['owners']);
        $this->assertSame(['Alice A', ''], $zone['full_names']);
        $this->assertSame(['alice', 'bob'], $zone['users']);
    }

    public function testGetZoneOnAnUnsignedUnownedZone(): void
    {
        $zone = $this->repository->getZone(2)?->toArray();

        $this->assertNotNull($zone);
        $this->assertSame(1, (int)$zone['record_count']);
        $this->assertFalse($zone['secured']);
        $this->assertSame(0, (int)$zone['owner']);
        $this->assertNull($zone['username']);
        $this->assertSame('', $zone['comment']);
        $this->assertSame([], $zone['owners']);
        $this->assertSame([], $zone['full_names']);
        $this->assertSame([], $zone['users']);
    }

    public function testGetZoneReturnsNullForAMissingZone(): void
    {
        $this->assertNull($this->repository->getZone(999));
    }

    /**
     * The exact array the internal API serialises and the metadata editor renders,
     * keys in order and values typed as the sqlite driver returns them.
     */
    public function testGetZoneSnapshot(): void
    {
        $zone = $this->repository->getZone(1);

        $this->assertNotNull($zone);
        $this->assertSame(self::signedZoneSnapshot(), $zone->toArray());
        $this->assertSame(1, $zone->id);
        $this->assertSame('signed.example', $zone->name);
        $this->assertSame(['alice', 'bob'], $zone->owners);
        $this->assertSame(['Alice A', null], $zone->fullNames);
        $this->assertTrue($zone->secured);
    }

    /**
     * @return array<string, mixed>
     */
    private static function signedZoneSnapshot(): array
    {
        return [
            'id' => 1,
            'name' => 'signed.example',
            'type' => 'MASTER',
            'master' => null,
            'account' => 'ops',
            'owner' => 5,
            'comment' => 'signed zone',
            'record_count' => 3,
            'secured' => true,
            'count_records' => 3,
            'username' => 'alice',
            'fullname' => 'Alice A',
            'utf8_name' => 'signed.example',
            'owners' => ['alice', 'bob'],
            'full_names' => ['Alice A', ''],
            'users' => ['alice', 'bob'],
        ];
    }

    public function testListZonesReportsTheTrueRecordCountOnASignedZone(): void
    {
        $zones = array_column($this->repository->listZones(), null, 'name');

        $this->assertSame(3, (int)$zones['signed.example']['count_records']);
        $this->assertTrue((bool)$zones['signed.example']['secured']);
        $this->assertSame(['alice', 'bob'], $zones['signed.example']['owners']);
        $this->assertSame(1, (int)$zones['plain.example']['count_records']);
        $this->assertFalse((bool)$zones['plain.example']['secured']);
    }

    public function testReverseZoneListReportsTheTrueRecordCountOnASignedZone(): void
    {
        $this->db->exec("UPDATE domains SET name = '2.0.192.in-addr.arpa' WHERE id = 1");

        $zones = $this->repository->getReverseZones('all', 5, 'all', 0, 100, 'name', 'ASC', false, false, false, false);

        $this->assertCount(1, $zones);
        $this->assertSame(3, (int)$zones['2.0.192.in-addr.arpa']['count_records']);
    }
}
