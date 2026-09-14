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

namespace Poweradmin\Tests\Integration;

use Poweradmin\Domain\Service\ZoneListPermissionService;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The zone lists and the search page decide per-row controls from one
 * ownership index built by two IN-list queries; direct and group ownership
 * both count and group membership is only read when a listed zone is group-owned.
 */
class ZoneListPermissionServiceIntegrationTest extends SqliteIntegrationTestCase
{
    private const ALICE = 10;
    private const BOB = 20;
    private const OPS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        // The repository maps every group column; the base table only carries the permission ones
        foreach (['description TEXT', 'created_by INTEGER', 'created_at TEXT', 'updated_at TEXT'] as $column) {
            $this->db->exec("ALTER TABLE user_groups ADD COLUMN $column");
        }

        // 1: alice's own zone; 2: bob's zone owned by the ops group too; 3: nobody's
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (1, " . self::ALICE . "), (2, " . self::BOB . "), (3, 0)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, " . self::OPS . ")");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (" . self::OPS . ", 'ops', 1)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::ALICE . ", " . self::OPS . ")");
    }

    public function testDirectAndGroupOwnershipBothCount(): void
    {
        $index = $this->makeService()->index(self::ALICE, [1, 2, 3, 3, 0]);

        $this->assertTrue($index->owns(1));
        $this->assertTrue($index->owns(2));
        $this->assertFalse($index->owns(3));
        $this->assertSame([self::OPS], $index->groupIds(2));
        $this->assertSame([], $index->groupIds(1));
    }

    public function testLevelsApplyPerZone(): void
    {
        $index = $this->makeService()->index(self::BOB, [1, 2, 3]);

        $this->assertTrue($index->allows('all', 3));
        $this->assertTrue($index->allows('own', 2));
        $this->assertFalse($index->allows('own', 1));
        $this->assertTrue($index->allows('own_as_client', 2));
        $this->assertFalse($index->allows('none', 2));
    }

    public function testOwnedZoneIdsCoverDirectAndGroupOwnership(): void
    {
        $owned = (new DbZoneRepository($this->db, $this->config))->getOwnedZoneIds(self::ALICE);
        sort($owned);

        $this->assertSame([1, 2], $owned);
    }

    public function testEmptyPageNeedsNoQueries(): void
    {
        $index = $this->makeService()->index(self::ALICE, []);

        $this->assertFalse($index->owns(1));
        $this->assertSame([], $index->groupIds(1));
    }

    private function makeService(): ZoneListPermissionService
    {
        return new ZoneListPermissionService(
            new DbZoneRepository($this->db, $this->config),
            new DbZoneGroupRepository($this->db, $this->config),
            new DbUserGroupRepository($this->db)
        );
    }
}
