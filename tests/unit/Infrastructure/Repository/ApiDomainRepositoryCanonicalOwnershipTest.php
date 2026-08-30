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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;

/**
 * The zone list is keyed by canonical id, so the ownership map has to be too. Keying it on
 * the raw domain_id collapsed every unresolved row onto 0, which both hid the owner and
 * let one zone's owners surface on another.
 */
class ApiDomainRepositoryCanonicalOwnershipTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER,
            owner INTEGER,
            comment TEXT,
            zone_templ_id INTEGER,
            zone_name TEXT,
            zone_type TEXT,
            zone_master TEXT,
            is_disabled INTEGER NOT NULL DEFAULT 0,
            is_missing_soa INTEGER NOT NULL DEFAULT 0,
            last_synced_at INTEGER
        )");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'alice', 'Alice'), (2, 'bob', 'Bob')");

        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();
    }

    private function backendReturning(array $zones): DnsBackendProvider
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn($zones);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([]);

        return $backend;
    }

    #[Test]
    public function eachStrandedZoneKeepsItsOwnOwner(): void
    {
        // Both rows are stranded at domain_id 0, so their canonical ids are 11 and 12.
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (11, 0, 1, '', 0, 'alice.example.com', 'NATIVE'),
            (12, 0, 2, '', 0, 'bob.example.com', 'NATIVE')");

        $repo = new ApiDomainRepository($this->db, $this->config, $this->backendReturning([
            ['id' => 11, 'name' => 'alice.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 12, 'name' => 'bob.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]));

        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC', false, true);

        $this->assertSame(['alice'], $result['alice.example.com']['owners']);
        $this->assertSame(['bob'], $result['bob.example.com']['owners']);
    }

    #[Test]
    public function ownerResolvesForNullAndMigratedRowsAlike(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (21, NULL, 1, '', 0, 'null.example.com', 'NATIVE'),
            (22, 300, 2, '', 0, 'migrated.example.com', 'NATIVE')");

        $repo = new ApiDomainRepository($this->db, $this->config, $this->backendReturning([
            ['id' => 21, 'name' => 'null.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 300, 'name' => 'migrated.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]));

        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC', false, true);

        $this->assertSame(['alice'], $result['null.example.com']['owners']);
        $this->assertSame(['bob'], $result['migrated.example.com']['owners']);
    }

    #[Test]
    public function multipleOwnersOnOneZoneStillAggregate(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (31, 0, 1, '', 0, 'shared.example.com', 'NATIVE')");
        // Extra ownership row pointing at the same canonical id.
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (32, 31, 2, '', 0, NULL, NULL)");

        $repo = new ApiDomainRepository($this->db, $this->config, $this->backendReturning([
            ['id' => 31, 'name' => 'shared.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]));

        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC', false, true);

        $owners = $result['shared.example.com']['owners'];
        sort($owners);
        $this->assertSame(['alice', 'bob'], $owners);
    }

    #[Test]
    public function aStrandedZoneStaysInTheOwnedList(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (41, 0, 1, '', 0, 'mine.example.com', 'NATIVE'),
            (42, 0, 2, '', 0, 'theirs.example.com', 'NATIVE')");

        $repo = new ApiDomainRepository($this->db, $this->config, $this->backendReturning([
            ['id' => 41, 'name' => 'mine.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 42, 'name' => 'theirs.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]));

        $result = $repo->getZones('own', 1, 'all', 0, 100, 'name', 'ASC', false, true);

        $this->assertArrayHasKey('mine.example.com', $result);
        $this->assertArrayNotHasKey('theirs.example.com', $result);
    }
}
