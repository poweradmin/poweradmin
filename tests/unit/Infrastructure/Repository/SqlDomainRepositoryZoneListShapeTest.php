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
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use TestHelpers\FakeConfiguration;

/**
 * The rows the forward zone list receives from the SQL backend. ZoneSummary::toArray()
 * reproduces the column-keyed rows with two deliberate differences from the raw
 * driver rows: secured is always present as a bool (false when DNSSEC is off, where
 * the key used to be absent) and it precedes the owner lists as on every other list.
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryZoneListShapeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
                    (30, 3, '2.0.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 7 1 1 1 1', 0)",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zone_templ VALUES (1, 'Basic')",
                "INSERT INTO zones VALUES (1, 1, 5, 'signed zone', 1), (2, 1, 6, NULL, 0), (3, 2, NULL, NULL, 0), (4, 3, 5, 'reverse', 0)",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 1, 'ksk')",
                "INSERT INTO domainmetadata VALUES (1, 1, 'PRESIGNED', '1')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    private function repository(bool $dnssec): SqlDomainRepository
    {
        return new SqlDomainRepository($this->db, new FakeConfiguration([
            'database' => ['type' => 'sqlite'],
            'dnssec' => ['enabled' => $dnssec],
            'interface' => ['show_zone_comments' => true],
        ]));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(bool $dnssec, bool $serial, bool $template, bool $health = true, bool $recordCount = true): array
    {
        return array_map(
            fn(ZoneSummary $zone): array => $zone->toArray(),
            $this->repository($dnssec)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', true, $serial, $template, $health, $recordCount)
        );
    }

    public function testForwardListWithEveryColumnEnabled(): void
    {
        $this->assertSame([
            'plain.example' => [
                'id' => 2,
                'name' => 'plain.example',
                'utf8_name' => 'plain.example',
                'type' => 'NATIVE',
                'count_records' => 1,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'ok',
                'comment' => '',
                'secured' => false,
                'owners' => [],
                'full_names' => [],
                'users' => [],
                'serial' => '5',
                'template' => '',
            ],
            'signed.example' => [
                'id' => 1,
                'name' => 'signed.example',
                'utf8_name' => 'signed.example',
                'type' => 'MASTER',
                'count_records' => 3,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'ok',
                // Two owner rows, the second with a NULL comment
                'comment' => 'signed zone',
                'secured' => true,
                'owners' => ['alice', 'bob'],
                'full_names' => ['Alice A', ''],
                'users' => ['alice', 'bob'],
                'serial' => '2024010101',
                'template' => 'Basic',
            ],
        ], $this->rows(true, true, true));
    }

    public function testForwardListWithDnssecOffAndNoOptionalColumns(): void
    {
        $rows = $this->rows(false, false, false, false, false);

        $this->assertSame(['plain.example', 'signed.example'], array_keys($rows));
        $this->assertSame([
            'id' => 1,
            'name' => 'signed.example',
            'utf8_name' => 'signed.example',
            'type' => 'MASTER',
            'count_records' => 0,
            'is_disabled' => false,
            'is_missing_soa' => false,
            'soa_health' => 'ok',
            'comment' => 'signed zone',
            'secured' => false,
            'owners' => ['alice', 'bob'],
            'full_names' => ['Alice A', ''],
            'users' => ['alice', 'bob'],
        ], $rows['signed.example']);
    }

    public function testForwardListIsEmptyForANonViewingPermission(): void
    {
        $this->assertSame([], $this->repository(true)->getZones('none'));
    }
}
