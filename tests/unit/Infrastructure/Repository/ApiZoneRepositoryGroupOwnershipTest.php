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
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * `zones_groups.domain_id` stores the canonical zone id, COALESCE(zones.domain_id,
 * zones.id), the same value ApiDnsBackendProvider hands to callers. Matching it
 * against the bare `zones.id` hid every group-owned zone in API mode.
 *
 * The two id spaces overlap, so the predicate must resolve to exactly one value per
 * zone - see CanonicalZoneSql. A tolerant `IN (id, domain_id)` match would pass the
 * visibility test below while leaking the colliding zone in the test after it.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryGroupOwnershipTest extends TestCase
{
    private PDO $db;
    private DnsBackendProvider $backend;

    protected function setUp(): void
    {
        // Hold the sync throttle closed; a sync against a stubbed provider would
        // see zero API zones and delete every local row as orphaned.
        $_SESSION['zone_sync_last'] = time();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Administrator'), (2, 'member', 'Group Member')");

        $this->backend = $this->createMock(DnsBackendProvider::class);
        $this->backend->method('getZoneStats')->willReturn([]);
        $this->backend->method('countZoneRecords')->willReturn(0);
        $this->backend->method('getZoneSoaHealth')->willReturn(['is_disabled' => false, 'is_missing_soa' => false]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['zone_sync_last']);
    }

    private function repository(): ApiZoneRepository
    {
        return new ApiZoneRepository($this->db, $this->backend, 'sqlite', $this->createMock(ConfigurationManager::class));
    }

    /** @return string[] */
    private function visibleTo(int $userId): array
    {
        $zones = $this->repository()->getReverseZones('own', $userId, 'all', 0, 25, 'name', 'ASC');
        return array_map(static fn(array $zone): string => $zone['name'], array_values($zones));
    }

    #[Test]
    public function groupOwnedZoneIsVisibleWhenDomainIdDiffersFromId(): void
    {
        // Zone id 7, canonical id 4011 - the shape produced by an install migrated
        // from SQL mode, where domain_id holds the PowerDNS id.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id)
            VALUES (7, 4011, '1.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4011, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 5)");

        $this->assertSame(['1.168.192.in-addr.arpa'], $this->visibleTo(2));
    }

    #[Test]
    public function groupOwnedZoneIsVisibleWhenDomainIdIsNull(): void
    {
        // A zone this application created has no domain_id, so the canonical id is
        // the primary key and zones_groups stores that instead.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id)
            VALUES (9, NULL, '2.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (9, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 5)");

        $this->assertSame(['2.168.192.in-addr.arpa'], $this->visibleTo(2));
    }

    #[Test]
    public function aCollidingZoneIsNotLeakedToTheGroup(): void
    {
        // The group owns zone 7, whose canonical id is 4011. Zone 4011 is a
        // different zone that merely has that number as its primary key. A
        // predicate matching either id space would hand the group both.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (7, 4011, '1.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0),
            (4011, 9002, '3.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4011, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 5)");

        $this->assertSame(['1.168.192.in-addr.arpa'], $this->visibleTo(2));
    }

    #[Test]
    public function aUserOutsideTheGroupSeesNothing(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id)
            VALUES (7, 4011, '1.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4011, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 5)");

        $this->assertSame([], $this->visibleTo(3));
    }

    #[Test]
    public function theCountAgreesWithTheListing(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (7, 4011, '1.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0),
            (4011, 9002, '3.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4011, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (2, 5)");

        // A count that disagrees with the listing produces empty pages.
        $count = $this->repository()->getReverseZones('own', 2, 'all', 0, 25, 'name', 'ASC', true);

        $this->assertSame(1, $count);
        $this->assertCount(1, $this->visibleTo(2));
    }
}
