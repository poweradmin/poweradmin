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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Group ownership in the zone list queries (Issue #1042): a zone owned only
 * through zones_groups is visible to the group's members and hidden from
 * everyone else, for the letter filter, the reverse zone list and its counts.
 *
 * Fixture: alice directly owns one forward and one reverse zone, the ops group
 * owns one of each (no direct owner), and one of each belongs to nobody.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryGroupOwnershipTest extends SqliteIntegrationTestCase
{
    private const ALICE = 10;
    private const MEMBER = 20;
    private const STRANGER = 30;
    private const ZED = 40;

    private const OPS_GROUP = 5;
    private const DEV_GROUP = 6;

    private const ALICE_FORWARD = 1;
    private const GROUP_FORWARD = 2;
    private const ORPHAN_FORWARD = 3;
    private const ALICE_REVERSE = 4;
    private const GROUP_REVERSE = 5;
    private const ZED_REVERSE = 6;

    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("ALTER TABLE zones ADD COLUMN comment TEXT");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, active INTEGER)");
        $this->db->exec("CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)");

        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES
            (" . self::ALICE . ", 'alice', 1), (" . self::MEMBER . ", 'mia', 1),
            (" . self::STRANGER . ", 'sam', 1), (" . self::ZED . ", 'zed', 1)");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES
            (" . self::OPS_GROUP . ", 'ops', 1), (" . self::DEV_GROUP . ", 'dev', 1)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::MEMBER . ", " . self::OPS_GROUP . ")");

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (" . self::ALICE_FORWARD . ", 'alpha.example.com', 'MASTER'),
            (" . self::GROUP_FORWARD . ", 'beta.example.com', 'MASTER'),
            (" . self::ORPHAN_FORWARD . ", 'gamma.example.com', 'MASTER'),
            (" . self::ALICE_REVERSE . ", '1.168.192.in-addr.arpa', 'MASTER'),
            (" . self::GROUP_REVERSE . ", '2.168.192.in-addr.arpa', 'MASTER'),
            (" . self::ZED_REVERSE . ", '8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES
            (" . self::ALICE_FORWARD . ", " . self::ALICE . "),
            (" . self::GROUP_FORWARD . ", 0),
            (" . self::ORPHAN_FORWARD . ", 0),
            (" . self::ALICE_REVERSE . ", " . self::ALICE . "),
            (" . self::GROUP_REVERSE . ", 0),
            (" . self::ZED_REVERSE . ", " . self::ZED . ")");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES
            (" . self::GROUP_FORWARD . ", " . self::OPS_GROUP . "),
            (" . self::GROUP_REVERSE . ", " . self::OPS_GROUP . ")");

        $this->repository = new DbZoneRepository($this->db, $this->config);
    }

    #[Test]
    public function startingLettersIncludeGroupOwnedZonesForMembersOnly(): void
    {
        $this->assertSame(['b'], $this->repository->getDistinctStartingLetters(self::MEMBER, false));
        $this->assertSame(['a'], $this->repository->getDistinctStartingLetters(self::ALICE, false));
        $this->assertSame([], $this->repository->getDistinctStartingLetters(self::STRANGER, false));
    }

    #[Test]
    public function startingLettersIgnoreOwnershipWhenViewingOthers(): void
    {
        $this->assertSame(['a', 'b', 'g'], $this->repository->getDistinctStartingLetters(self::STRANGER, true));
    }

    #[Test]
    public function ownReverseZonesIncludeGroupOwnedZonesForMembersOnly(): void
    {
        $this->assertSame(['2.168.192.in-addr.arpa'], array_keys($this->repository->getReverseZones('own', self::MEMBER)));
        $this->assertSame(['1.168.192.in-addr.arpa'], array_keys($this->repository->getReverseZones('own', self::ALICE)));
        $this->assertSame([], $this->repository->getReverseZones('own', self::STRANGER));
    }

    #[Test]
    public function allReverseZonesIgnoreOwnership(): void
    {
        $zones = $this->repository->getReverseZones('all', self::STRANGER);

        $this->assertSame(
            ['1.168.192.in-addr.arpa', '2.168.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'],
            array_keys($zones)
        );
        $this->assertSame(['alice'], $zones['1.168.192.in-addr.arpa']['owners']);
        $this->assertSame([], $zones['2.168.192.in-addr.arpa']['owners']);
    }

    #[Test]
    public function ownReverseZoneCountIncludesGroupOwnedZonesForMembersOnly(): void
    {
        $this->assertSame(1, $this->repository->getReverseZones('own', self::MEMBER, 'all', 0, 25, 'name', 'ASC', true));
        $this->assertSame(0, $this->repository->getReverseZones('own', self::STRANGER, 'all', 0, 25, 'name', 'ASC', true));
        $this->assertSame(3, $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'name', 'ASC', true));
    }

    #[Test]
    public function reverseZoneCountsIncludeGroupOwnedZonesForMembersOnly(): void
    {
        $this->assertSame(
            ['count_all' => 1, 'count_ipv4' => 1, 'count_ipv6' => 0],
            $this->repository->getReverseZoneCounts('own', self::MEMBER)
        );
        $this->assertSame(
            ['count_all' => 0, 'count_ipv4' => 0, 'count_ipv6' => 0],
            $this->repository->getReverseZoneCounts('own', self::STRANGER)
        );
        $this->assertSame(
            ['count_all' => 3, 'count_ipv4' => 2, 'count_ipv6' => 1],
            $this->repository->getReverseZoneCounts('all', self::STRANGER)
        );
    }

    #[Test]
    public function sortByGroupOrdersByGroupNameWithoutInflatingRecordCount(): void
    {
        // The group-owned zone joins two groups, so a records join would double its count
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES
            (" . self::GROUP_REVERSE . ", " . self::DEV_GROUP . "),
            (" . self::ZED_REVERSE . ", " . self::OPS_GROUP . ")");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content) VALUES
            (" . self::GROUP_REVERSE . ", '2.168.192.in-addr.arpa', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
            (" . self::GROUP_REVERSE . ", '2.168.192.in-addr.arpa', 'NS', 'ns1.example.com'),
            (" . self::GROUP_REVERSE . ", '7.2.168.192.in-addr.arpa', 'PTR', 'host.example.com'),
            (" . self::GROUP_REVERSE . ", '2.168.192.in-addr.arpa', NULL, '')");

        $ascending = $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'group', 'ASC');
        $descending = $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'group', 'DESC');

        $this->assertCount(3, $ascending);
        $this->assertSame(3, $ascending['2.168.192.in-addr.arpa']['count_records']);
        $this->assertSame(
            ['2.168.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'],
            $this->groupedZoneNames($ascending)
        );
        $this->assertSame(
            ['8.b.d.0.1.0.0.2.ip6.arpa', '2.168.192.in-addr.arpa'],
            $this->groupedZoneNames($descending)
        );
    }

    #[Test]
    public function sortByOwnerOrdersByUsername(): void
    {
        $ascending = $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'owner', 'ASC');
        $descending = $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'owner', 'DESC');

        $this->assertCount(3, $ascending);
        $this->assertSame(
            ['1.168.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'],
            $this->directlyOwnedZoneNames($ascending)
        );
        $this->assertSame(
            ['8.b.d.0.1.0.0.2.ip6.arpa', '1.168.192.in-addr.arpa'],
            $this->directlyOwnedZoneNames($descending)
        );
    }

    #[Test]
    public function hiddenRecordCountSortFallsBackToNameOrder(): void
    {
        // Turning the Records column off drops count_records from the allowed
        // sort keys, but a session set while it was visible still asks for it
        $zones = $this->repository->getReverseZones('all', self::STRANGER, 'all', 0, 25, 'count_records', 'ASC', false, false, false, true, false);

        $this->assertSame(
            ['1.168.192.in-addr.arpa', '2.168.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'],
            array_keys($zones)
        );
        $this->assertSame(0, $zones['1.168.192.in-addr.arpa']['count_records']);
    }

    /**
     * Zone names in list order, skipping ungrouped zones whose NULL sort key lands
     * at a database-specific end of the list.
     *
     * @return list<string>
     */
    private function groupedZoneNames(array $zones): array
    {
        return array_values(array_diff(array_keys($zones), ['1.168.192.in-addr.arpa']));
    }

    /**
     * @return list<string>
     */
    private function directlyOwnedZoneNames(array $zones): array
    {
        return array_values(array_keys(array_filter($zones, fn(ZoneSummary $zone) => $zone->owners !== [])));
    }
}
