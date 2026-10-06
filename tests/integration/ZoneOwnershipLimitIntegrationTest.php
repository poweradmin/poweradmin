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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use TestHelpers\FakeConfiguration;

/**
 * Zone limits against the shipped SQLite schema: the max_zones columns, and the direct
 * ownership count in SQL and API backend mode.
 */
class ZoneOwnershipLimitIntegrationTest extends TestCase
{
    private const ALICE = 10;
    private const BOB = 11;
    private const GROUP = 20;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(file_get_contents(__DIR__ . '/../../sql/poweradmin-sqlite-db-structure.sql'));
        foreach ([self::ALICE => 'alice', self::BOB => 'bob'] as $id => $name) {
            $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
                VALUES ($id, '$name', '', '', '', '', 2, 1, 0)");
        }
        $this->db->exec("INSERT INTO user_groups (id, name, description, perm_templ) VALUES (" . self::GROUP . ", 'ops', '', 1)");
    }

    private function limits(bool $isApiBackend = false, array $dns = []): ZoneOwnershipLimit
    {
        $config = new FakeConfiguration(['database' => ['type' => 'sqlite', 'pdns_db_name' => ''], 'dns' => $dns]);
        $users = new DbUserRepository($this->db, $config, $isApiBackend);

        return new ZoneOwnershipLimit(
            $users,
            new DbUserGroupRepository($this->db),
            new DbZoneGroupRepository($this->db, $config, $isApiBackend),
            new PermissionService($users),
            $config
        );
    }

    public function testNewColumnsStartNullAndRoundTrip(): void
    {
        $users = new DbUserRepository($this->db, new FakeConfiguration([]), false);
        $groups = new DbUserGroupRepository($this->db);

        $this->assertNull($users->findZoneLimit(self::ALICE));
        $this->assertNull($groups->findById(self::GROUP)?->getMaxZones());

        $users->setZoneLimit(self::ALICE, 0);
        $groups->setZoneLimit(self::GROUP, 7);
        $this->assertSame(0, $users->findZoneLimit(self::ALICE));
        $this->assertSame(7, $groups->findById(self::GROUP)?->getMaxZones());

        $users->setZoneLimit(self::ALICE, null);
        $this->assertNull($users->findZoneLimit(self::ALICE));
    }

    public function testSqlModeCountsDistinctDirectlyOwnedZones(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (1, " . self::ALICE . "), (1, " . self::ALICE . "), (2, " . self::ALICE . "), (2, " . self::BOB . "), (3, " . self::BOB . ")");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4, " . self::GROUP . "), (5, " . self::GROUP . ")");

        $limits = $this->limits(dns: ['default_max_zones_per_user' => 2]);

        $this->assertSame(2, $limits->userZoneCount(self::ALICE));
        $this->assertSame(2, $limits->groupZoneCount(self::GROUP));
        $this->assertNotNull($limits->userBreach(self::ALICE));
        // Bob owns zones 2 and 3 and gains only zone 1, since he co-owns zone 2 already
        $this->assertNull($this->limits(dns: ['default_max_zones_per_user' => 3])->transferBreach(self::ALICE, self::BOB));
        $this->assertNotNull($limits->transferBreach(self::ALICE, self::BOB));
    }

    public function testGroupGrantsDoNotCountForMembers(): void
    {
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (" . self::GROUP . ", " . self::ALICE . ")");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (4, " . self::GROUP . ")");

        $this->assertSame(0, $this->limits()->userZoneCount(self::ALICE));
    }

    public function testApiModeCountsTheCanonicalZoneOnce(): void
    {
        // Two rows for one zone: a stranded domain_id and the zone_name fallback resolve to one id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (100, 100, " . self::ALICE . ", 'example.com'), (101, 100, " . self::ALICE . ", NULL)");

        $this->assertSame(1, $this->limits(isApiBackend: true)->userZoneCount(self::ALICE));
    }

    public function testApiModeGroupCountSkipsGrantsOnASharedId(): void
    {
        // A migrated zone (domain_id 300) and a zone created here (row id 300) share id 300
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (200, 300, " . self::ALICE . ", 'migrated.test'), (300, NULL, " . self::BOB . ", 'created.test')");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (300, " . self::GROUP . "), (400, " . self::GROUP . ")");

        $this->assertSame(1, $this->limits(isApiBackend: true)->groupZoneCount(self::GROUP));
        $this->assertSame(2, $this->limits()->groupZoneCount(self::GROUP));
    }

    public function testBatchedCountsMatchThePerUserCounts(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (100, 100, " . self::ALICE . ", 'a.test'), (101, 100, " . self::ALICE . ", NULL), (102, 102, " . self::BOB . ", 'b.test'),
            (200, 300, " . self::ALICE . ", 'migrated.test'), (300, NULL, " . self::BOB . ", 'created.test')");
        $users = new DbUserRepository($this->db, new FakeConfiguration([]), false);
        $users->setZoneLimit(self::BOB, 4);

        foreach ([false, true] as $isApiBackend) {
            $limits = $this->limits($isApiBackend, ['default_max_zones_per_user' => 9]);
            $usage = $limits->userUsage([self::ALICE, self::BOB, 999]);
            foreach ([self::ALICE, self::BOB, 999] as $userId) {
                $this->assertSame(
                    ['owned' => $limits->userZoneCount($userId), 'limit' => $limits->userLimit($userId)],
                    $usage[$userId],
                    ($isApiBackend ? 'api' : 'sql') . " user $userId"
                );
            }
        }
        $this->assertSame([], $this->limits()->userUsage([]));
    }
}
