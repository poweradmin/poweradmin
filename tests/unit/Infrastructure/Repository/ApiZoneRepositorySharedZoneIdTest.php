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
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * Zone 5 was created here (domain_id = its own id) and migrated zone 12 carries domain_id 5,
 * so both have canonical id 5. The id resolves to zone 5; an extra-owner row and a group
 * grant keyed by 5 cannot be attributed, so the zone lists and owner lists ignore them.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositorySharedZoneIdTest extends TestCase
{
    private const CREATED_OWNER = 1;
    private const MIGRATED_OWNER = 2;
    private const EXTRA_OWNER = 3;
    private const GROUP_MEMBER = 4;

    private PDO $db;

    protected function setUp(): void
    {
        // Hold the sync throttle closed so the stubbed provider never prunes local rows
        $_SESSION['zone_sync_last'] = time();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES
            (1, 'creator', 'Creator'), (2, 'migrator', 'Migrator'), (3, 'extra', 'Extra'), (4, 'member', 'Member')");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (5, 5, '5.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0),
            (12, 5, '12.168.192.in-addr.arpa', 'MASTER', '', '', 2, 0),
            (13, 5, NULL, NULL, NULL, '', 3, 0),
            (30, 30, '30.168.192.in-addr.arpa', 'MASTER', '', '', 2, 0),
            (31, 30, NULL, NULL, NULL, '', 3, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 7)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (4, 7)");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['zone_sync_last']);
    }

    private function repository(): ApiZoneRepository
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('deleteZone')->willReturn(true);
        $backend->method('getZoneById')->willReturn([]);
        $backend->method('getZones')->willReturn([
            ['id' => 5, 'name' => '5.168.192.in-addr.arpa.'],
            ['id' => 5, 'name' => '12.168.192.in-addr.arpa.'],
            ['id' => 30, 'name' => '30.168.192.in-addr.arpa.'],
        ]);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('getZoneStats')->willReturn([]);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneSoaHealth')->willReturn(['is_disabled' => false, 'is_missing_soa' => false]);

        return new ApiZoneRepository($this->db, $backend, 'sqlite', new FakeConfiguration());
    }

    /** @param ZoneSummary[] $zones @return string[] */
    private static function names(array $zones): array
    {
        $names = array_map(static fn(ZoneSummary $zone): string => $zone->name, array_values($zones));
        sort($names);

        return $names;
    }

    #[Test]
    public function ownZoneListsShowASharedIdOnlyToTheResolvedZonesDirectOwner(): void
    {
        $repository = $this->repository();

        $this->assertSame(['5.168.192.in-addr.arpa'], self::names($repository->getReverseZones('own', self::CREATED_OWNER, 'all', 0, 25, 'name', 'ASC')));
        $this->assertSame(['30.168.192.in-addr.arpa'], self::names($repository->getReverseZones('own', self::MIGRATED_OWNER, 'all', 0, 25, 'name', 'ASC')));
        $this->assertSame(['30.168.192.in-addr.arpa'], self::names($repository->getReverseZones('own', self::EXTRA_OWNER, 'all', 0, 25, 'name', 'ASC')));
        $this->assertSame([], self::names($repository->getReverseZones('own', self::GROUP_MEMBER, 'all', 0, 25, 'name', 'ASC')));

        $this->assertSame(['30.168.192.in-addr.arpa'], self::names($repository->listZones(self::EXTRA_OWNER)));
        $this->assertSame(['5.168.192.in-addr.arpa'], self::names($repository->listZones(self::CREATED_OWNER)));
        $this->assertSame(1, $repository->getReverseZoneCounts('own', self::EXTRA_OWNER)['count_all']);
    }

    #[Test]
    public function ownZoneCountsMatchTheList(): void
    {
        $repository = $this->repository();

        $this->assertSame(1, $repository->countZones('own', self::CREATED_OWNER, 'all', 'reverse'));
        $this->assertSame(1, $repository->countZones('own', self::EXTRA_OWNER, 'all', 'reverse'));
        $this->assertSame(0, $repository->countZones('own', self::GROUP_MEMBER, 'all', 'reverse'));
    }

    #[Test]
    public function aSharedIdNamesOnlyTheResolvedZonesDirectOwner(): void
    {
        $repository = $this->repository();

        $this->assertTrue($repository->isSharedZoneId(5));
        $this->assertTrue($repository->isSharedZoneId(12), 'The migrated zone addressed by its row id is still on a shared id');
        $this->assertFalse($repository->isSharedZoneId(30));
        $this->assertSame(['creator'], array_column($repository->getZoneOwners(5), 'username'));
        $this->assertSame(['extra', 'migrator'], self::sorted(array_column($repository->getZoneOwners(30), 'username')));

        $byName = [];
        foreach ($repository->listZones(null, true) as $zone) {
            $byName[$zone->name] = $zone->owners;
        }
        $this->assertSame(['creator'], $byName['5.168.192.in-addr.arpa']);
        $this->assertSame(['migrator'], $byName['12.168.192.in-addr.arpa']);
    }

    #[Test]
    public function theZoneDetailAndRowControlsCountOnlyTheResolvedOwner(): void
    {
        $repository = $this->repository();

        $this->assertSame(['creator'], $repository->getZone(5)?->owners);
        $index = $repository->getOwnerIdsByZoneIds([5, 30]);
        $this->assertSame([self::CREATED_OWNER], $index[5]);
        $this->assertSame([self::MIGRATED_OWNER, self::EXTRA_OWNER], $index[30]);
    }

    #[Test]
    public function deletingASharedZoneIdDropsTheGrantsInsteadOfHandingThemToTheOtherZone(): void
    {
        $this->assertTrue($this->repository()->deleteZone(5));

        $this->assertSame([12, 30, 31], array_map('intval', $this->db->query("SELECT id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones_groups")->fetchColumn());
    }

    #[Test]
    public function deletingTheOtherZoneByItsRowIdAlsoDropsTheSharedGrants(): void
    {
        $this->assertTrue($this->repository()->deleteZone(12));

        $this->assertSame([5, 30, 31], array_map('intval', $this->db->query("SELECT id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones_groups")->fetchColumn());
    }

    /** @param string[] $values @return string[] */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
