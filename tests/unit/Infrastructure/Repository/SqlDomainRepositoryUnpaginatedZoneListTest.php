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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The unpaginated branch of SqlDomainRepository::getZones (letter filter "all", or a
 * row limit at or above the default maximum), which small installs always take.
 *
 * Fixture: multi.example has three records, two active cryptokeys and two owner rows,
 * the first carrying the zone comment and the second a NULL one.
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryUnpaginatedZoneListTest extends TestCase
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
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "INSERT INTO domains VALUES
                    (1, 'multi.example', 'MASTER', NULL, ''),
                    (2, 'plain.example', 'NATIVE', NULL, '')",
                "INSERT INTO records VALUES
                    (10, 1, 'multi.example', 'SOA', 'ns1 hostmaster 1 1 1 1 1', 0),
                    (11, 1, 'multi.example', 'NS', 'ns1.multi.example', 0),
                    (12, 1, 'www.multi.example', 'A', '192.0.2.1', 0),
                    (20, 2, 'plain.example', 'SOA', 'ns1 hostmaster 1 1 1 1 1', 0),
                    (21, 2, 'plain.example', 'NS', 'ns1.plain.example', 0),
                    (22, 2, 'www.plain.example', 'A', '192.0.2.2', 0),
                    (23, 2, 'mail.plain.example', 'A', '192.0.2.3', 0)",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', 'Bob B')",
                "INSERT INTO zones VALUES (1, 1, 5, 'shared comment', 0), (2, 1, 6, NULL, 0), (3, 2, 5, NULL, 0)",
                "INSERT INTO cryptokeys VALUES (1, 1, 257, 1, 'ksk'), (2, 1, 256, 1, 'zsk')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    private function repository(bool $dnssec): SqlDomainRepository
    {
        $values = [
            'database' => ['type' => 'sqlite'],
            'dnssec' => ['enabled' => $dnssec],
            'interface' => ['show_zone_comments' => true],
        ];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, ?string $key = null, mixed $default = null): mixed
                => $key === null ? ($values[$group] ?? $default) : ($values[$group][$key] ?? $default)
        );

        return new SqlDomainRepository($this->db, $config);
    }

    #[Test]
    public function cryptokeyJoinDoesNotMultiplyTheRecordCount(): void
    {
        $zones = $this->repository(true)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', false, false, false);

        $this->assertSame(3, $zones['multi.example']['count_records']);
        $this->assertTrue((bool)$zones['multi.example']['secured']);
    }

    #[Test]
    public function recordCountMatchesWithDnssecOff(): void
    {
        $zones = $this->repository(false)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', false, false, false);

        $this->assertSame(3, $zones['multi.example']['count_records']);
    }

    #[Test]
    public function sortingByRecordCountUsesTheRealCounts(): void
    {
        $zones = $this->repository(true)->getZones('all', 0, 'all', 0, 9999, 'count_records', 'ASC', false, false, false);

        // multi.example has three records against plain.example's four, but its two
        // cryptokeys used to push it past them
        $this->assertSame(['multi.example', 'plain.example'], array_keys($zones));
        $this->assertSame(4, $zones['plain.example']['count_records']);
    }

    #[Test]
    public function aSecondOwnerRowWithoutACommentDoesNotBlankTheComment(): void
    {
        $zones = $this->repository(true)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', false, false, false);

        $this->assertSame(['alice', 'bob'], $zones['multi.example']['owners']);
        $this->assertSame('shared comment', $zones['multi.example']['comment']);
    }
}
