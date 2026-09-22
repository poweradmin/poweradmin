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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Infrastructure\Repository\RecordSearch;
use Poweradmin\Infrastructure\Repository\ZoneSearch;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A user holding the `search` permission but no view permission resolves to
 * permission_view 'none'; the search must then return nothing. Previously
 * 'none' fell through unfiltered and exposed every zone and record.
 *
 * Fixture: alice owns test-alice.example, the ops group owns test-ops.example
 * with mia as its only member, and test-other.example belongs to nobody.
 */
#[CoversClass(ZoneSearch::class)]
#[CoversClass(RecordSearch::class)]
class SearchViewPermissionTest extends SqliteIntegrationTestCase
{
    private const ALICE = 10;
    private const MIA = 20;

    private const OPS_GROUP = 5;

    private const ALICE_ZONE = 1;
    private const GROUP_ZONE = 2;
    private const ORPHAN_ZONE = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("ALTER TABLE zones ADD COLUMN comment TEXT");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT,
            content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, auth INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT,
            modified_at INTEGER, account TEXT, comment TEXT)");

        $this->db->exec("INSERT INTO users (id, username, perm_templ, fullname) VALUES
            (" . self::ALICE . ", 'alice', 1, 'Alice'), (" . self::MIA . ", 'mia', 1, 'Mia')");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (" . self::OPS_GROUP . ", 'ops', 1)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::MIA . ", " . self::OPS_GROUP . ")");

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (" . self::ALICE_ZONE . ", 'test-alice.example', 'MASTER'),
            (" . self::GROUP_ZONE . ", 'test-ops.example', 'MASTER'),
            (" . self::ORPHAN_ZONE . ", 'test-other.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES
            (" . self::ALICE_ZONE . ", " . self::ALICE . "),
            (" . self::GROUP_ZONE . ", 0),
            (" . self::ORPHAN_ZONE . ", 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (" . self::GROUP_ZONE . ", " . self::OPS_GROUP . ")");

        $this->db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
            (" . self::ALICE_ZONE . ", 'www.test-alice.example', 'A', '192.0.2.1', 3600, 0),
            (" . self::GROUP_ZONE . ", 'www.test-ops.example', 'A', '192.0.2.2', 3600, 0),
            (" . self::ORPHAN_ZONE . ", 'www.test-other.example', 'A', '192.0.2.3', 3600, 0)");
    }

    /** @return list<string> */
    private function zoneNames(string $permissionView, ?int $userId): array
    {
        $search = new ZoneSearch($this->db, $this->config, 'sqlite');
        $zones = $search->fetchZones(
            ['comments' => false],
            '%test-%',
            false,
            '',
            $permissionView,
            $userId,
            'name',
            'ASC',
            10,
            false,
            1
        );

        return array_map(fn(array $zone): string => $zone['name'], $zones);
    }

    private function zoneCount(string $permissionView, ?int $userId): int
    {
        $search = new ZoneSearch($this->db, $this->config, 'sqlite');

        return $search->getFoundZones(['comments' => false], '%test-%', false, '', $permissionView, $userId);
    }

    /** @return list<string> */
    private function recordNames(string $permissionView, ?int $userId): array
    {
        $search = new RecordSearch($this->db, $this->config, 'sqlite');
        $records = $search->fetchRecords(
            ['comments' => false],
            '%test-%',
            false,
            '',
            $permissionView,
            $userId,
            false,
            'name',
            'ASC',
            10,
            false,
            1
        );

        return array_map(fn(array $record): string => $record['name'], $records);
    }

    private function recordCount(string $permissionView, ?int $userId): int
    {
        $search = new RecordSearch($this->db, $this->config, 'sqlite');

        return $search->getFoundRecords(['comments' => false], '%test-%', false, '', $permissionView, $userId, false);
    }

    #[Test]
    public function aViewlessUserFindsNoZonesOrRecords(): void
    {
        $this->assertSame([], $this->zoneNames('none', self::ALICE));
        $this->assertSame(0, $this->zoneCount('none', self::ALICE));
        $this->assertSame([], $this->recordNames('none', self::ALICE));
        $this->assertSame(0, $this->recordCount('none', self::ALICE));
    }

    #[Test]
    public function anUnknownViewLevelIsTreatedAsNoAccess(): void
    {
        $this->assertSame([], $this->zoneNames('whatever', self::ALICE));
        $this->assertSame([], $this->recordNames('whatever', self::ALICE));
    }

    #[Test]
    public function theAllViewFindsEveryZoneAndRecord(): void
    {
        $this->assertSame(
            ['test-alice.example', 'test-ops.example', 'test-other.example'],
            $this->zoneNames('all', self::ALICE)
        );
        $this->assertSame(3, $this->zoneCount('all', self::ALICE));
        $this->assertSame(
            ['www.test-alice.example', 'www.test-ops.example', 'www.test-other.example'],
            $this->recordNames('all', self::ALICE)
        );
        $this->assertSame(3, $this->recordCount('all', self::ALICE));
    }

    #[Test]
    public function theOwnViewFindsOnlyDirectlyOwnedZonesAndRecords(): void
    {
        $this->assertSame(['test-alice.example'], $this->zoneNames('own', self::ALICE));
        $this->assertSame(1, $this->zoneCount('own', self::ALICE));
        $this->assertSame(['www.test-alice.example'], $this->recordNames('own', self::ALICE));
        $this->assertSame(1, $this->recordCount('own', self::ALICE));
    }

    #[Test]
    public function theOwnViewAlsoFindsGroupOwnedZonesAndRecords(): void
    {
        $this->assertSame(['test-ops.example'], $this->zoneNames('own', self::MIA));
        $this->assertSame(1, $this->zoneCount('own', self::MIA));
        $this->assertSame(['www.test-ops.example'], $this->recordNames('own', self::MIA));
        $this->assertSame(1, $this->recordCount('own', self::MIA));
    }

    #[Test]
    #[DataProvider('ownerlessViewers')]
    public function theOwnViewNeverShowsUnownedZones(int $userId): void
    {
        $this->assertNotContains('test-other.example', $this->zoneNames('own', $userId));
        $this->assertNotContains('www.test-other.example', $this->recordNames('own', $userId));
    }

    /** @return array<string, array{int}> */
    public static function ownerlessViewers(): array
    {
        return ['alice' => [self::ALICE], 'mia' => [self::MIA]];
    }
}
