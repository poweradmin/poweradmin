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
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * The last-super-admin rule counts super admin granted through a group template as
 * well as through the user's own template, on the real SQL of DbUserRepository.
 */
class GroupGrantedSuperAdminIntegrationTest extends TestCase
{
    private const ADMIN_TEMPL = 1;
    private const PLAIN_TEMPL = 2;
    private const ADMIN_GROUP_TEMPL = 3;

    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER, active INTEGER)');
        $this->db->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)');
        $this->db->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)');
        $this->db->exec('CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)');

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_is_ueberuser'), (2, 'zone_content_view_own')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (' . self::ADMIN_TEMPL . ', 1), (' . self::PLAIN_TEMPL . ', 2), (' . self::ADMIN_GROUP_TEMPL . ', 1)');
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (1, 'Administrators', " . self::ADMIN_GROUP_TEMPL . ')');

        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(), false);
    }

    private function addUser(int $id, int $templ, bool $active = true, bool $inAdminGroup = false): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ, active) VALUES ($id, 'u$id', $templ, " . ($active ? 1 : 0) . ')');
        if ($inAdminGroup) {
            $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (1, $id)");
        }
    }

    public function testASuperAdminThroughAGroupCountsAlongsideADirectOne(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, inAdminGroup: true);
        $this->addUser(3, self::PLAIN_TEMPL);

        $this->assertSame(2, $this->repository->countUberusers());
        $this->assertTrue($this->repository->isUberuser(2));
        $this->assertFalse($this->repository->isUberuser(3));
        // Another super admin remains, so the direct one may go
        $this->assertFalse($this->repository->isLastUberuser(1));
    }

    public function testTheOnlyGroupGrantedSuperAdminIsTheLastOne(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, inAdminGroup: true);
        $this->addUser(2, self::PLAIN_TEMPL);

        $this->assertTrue($this->repository->isLastUberuser(1));
        $this->assertFalse($this->repository->isLastUberuser(2));
    }

    public function testInactiveGroupMembersAreNotCounted(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, active: false, inAdminGroup: true);

        $this->assertSame(1, $this->repository->countUberusers());
        $this->assertTrue($this->repository->isLastUberuser(1));
    }

    public function testTheLockedCheckInsideATransactionCountsGroupGrants(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, inAdminGroup: true);

        $this->db->beginTransaction();
        try {
            $this->assertFalse($this->repository->isLastUberuser(1));
            $this->assertFalse($this->repository->isLastUberuser(2));
        } finally {
            $this->db->rollBack();
        }
    }

    public function testAGroupGrantIsReportedSeparatelyFromTheOwnTemplate(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL, inAdminGroup: true);
        $this->addUser(2, self::ADMIN_TEMPL);

        $this->assertTrue($this->repository->holdsUberuserThroughGroup(1));
        $this->assertFalse($this->repository->holdsUberuserThroughGroup(2));
    }
}
