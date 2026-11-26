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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbZoneAccountOwnerRepository;

/**
 * A missed ownership lookup here does not merely skip the account sync: the caller
 * pushes an empty account and wipes whatever PowerDNS held for the zone.
 */
class DbZoneAccountOwnerRepositoryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("INSERT INTO user_groups (id, name) VALUES (5, 'ops')");
        $this->db->exec("INSERT INTO users (id, username) VALUES (1, 'alice')");
    }

    private function repository(bool $isApiBackend = true): DbZoneAccountOwnerRepository
    {
        return new DbZoneAccountOwnerRepository($this->db, $isApiBackend);
    }

    public function testStrandedZoneResolvesByItsRowId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (55, 0, 1, 'example.com')");

        $this->assertSame('alice', $this->repository()->oldestOwnerUsername(55));
    }

    public function testNullDomainIdZoneAlsoResolves(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (56, NULL, 1, 'example.com')");

        $this->assertSame('alice', $this->repository()->oldestOwnerUsername(56));
    }

    public function testMigratedZoneStillResolvesByDomainId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (7, 201, 1, 'example.com')");

        $this->assertSame('alice', $this->repository()->oldestOwnerUsername(201));
    }

    public function testSqlModeResolvesTheOwnerByDomainIdOnly(): void
    {
        // Row 55 (alice) has no domain_id; row 9 (bob) carries domain_id 55. In SQL mode
        // id 55 is bob's zone, and alice's stranded row must not answer for it.
        $this->db->exec("INSERT INTO users (id, username) VALUES (2, 'bob')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (55, 0, 1, 'stranded.example.com'), (9, 55, 2, 'example.com')");

        $this->assertSame('bob', $this->repository(false)->oldestOwnerUsername(55));
    }

    public function testOldestOwnerRowWins(): void
    {
        $this->db->exec("INSERT INTO users (id, username) VALUES (2, 'bob')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (3, 60, 2, 'example.com'), (4, 60, 1, 'example.com')");

        $this->assertSame('bob', $this->repository(false)->oldestOwnerUsername(60));
    }

    public function testAnExcludedUserIsPassedOver(): void
    {
        $this->db->exec("INSERT INTO users (id, username) VALUES (2, 'bob')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (3, 60, 2, 'example.com'), (4, 60, 1, 'example.com')");

        $this->assertSame('alice', $this->repository(false)->oldestOwnerUsername(60, 2));
        $this->db->exec("DELETE FROM zones WHERE owner = 1");
        $this->assertNull($this->repository(false)->oldestOwnerUsername(60, 2));
    }

    public function testAZoneWithNoOwnerResolvesToNull(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (60, 60, NULL, 'orphan.example.com')");

        $this->assertNull($this->repository()->oldestOwnerUsername(60));
    }

    public function testAnUnknownZoneResolvesToNull(): void
    {
        $this->assertNull($this->repository()->oldestOwnerUsername(999));
    }

    public function testAZoneOwnedOnlyByAGroupHasAnOwner(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 301, 0, 'grouped.example'), (2, 302, 0, 'orphan.example'), (3, 303, 1, 'direct.example')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (301, 5)");

        $this->assertTrue($this->repository(false)->hasOwner(301));
        $this->assertFalse($this->repository(false)->hasOwner(302));
        $this->assertTrue($this->repository(false)->hasOwner(303));
        $this->assertNull($this->repository(false)->oldestOwnerUsername(301));
    }

    public function testAGrantOfADeletedGroupIsNoOwner(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 304, 0, 'stale.example')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (304, 77)");

        $this->assertFalse($this->repository(false)->hasOwner(304));
    }
}
