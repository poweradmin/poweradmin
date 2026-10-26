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
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * countUberusersAfter() applies each kind of pending change in SQL, on the real
 * queries of DbUserRepository.
 */
class UberuserCountAfterChangeIntegrationTest extends TestCase
{
    private const ADMIN_TEMPL = 1;
    private const PLAIN_TEMPL = 2;
    private const ADMIN_GROUP_TEMPL = 3;
    private const PLAIN_GROUP_TEMPL = 4;
    private const ADMIN_GROUP = 1;
    private const OTHER_ADMIN_GROUP = 2;

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
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES ('
            . self::ADMIN_TEMPL . ', 1), (' . self::PLAIN_TEMPL . ', 2), ('
            . self::ADMIN_GROUP_TEMPL . ', 1), (' . self::PLAIN_GROUP_TEMPL . ', 2)');
        $this->db->exec('INSERT INTO user_groups (id, name, perm_templ) VALUES ('
            . self::ADMIN_GROUP . ", 'Administrators', " . self::ADMIN_GROUP_TEMPL . '), ('
            . self::OTHER_ADMIN_GROUP . ", 'Operators', " . self::ADMIN_GROUP_TEMPL . ')');

        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(), false);
    }

    private function addUser(int $id, int $templ, int ...$groupIds): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ, active) VALUES ($id, 'u$id', $templ, 1)");
        foreach ($groupIds as $groupId) {
            $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES ($groupId, $id)");
        }
    }

    public function testNoChangeCountsTheCurrentSuperAdmins(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, self::ADMIN_GROUP);
        $this->addUser(3, self::PLAIN_TEMPL);

        $this->assertSame(2, $this->repository->countUberusersAfter(UberuserChange::none()));
    }

    public function testATemplateDroppingTheGrantCountsForOwnAndGroupTemplates(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, self::ADMIN_GROUP);

        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::templateDropsUberuser(self::ADMIN_TEMPL)));
        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::templateDropsUberuser(self::ADMIN_GROUP_TEMPL)));
        $this->assertSame(2, $this->repository->countUberusersAfter(UberuserChange::templateDropsUberuser(self::PLAIN_TEMPL)));
    }

    public function testDeletingTheOnlyGrantingGroupLeavesNobody(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, self::ADMIN_GROUP);
        $this->addUser(2, self::PLAIN_TEMPL, self::ADMIN_GROUP, self::OTHER_ADMIN_GROUP);

        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::groupDeleted(self::ADMIN_GROUP)));
        $this->assertSame(2, $this->repository->countUberusersAfter(UberuserChange::groupDeleted(self::OTHER_ADMIN_GROUP)));
    }

    public function testRemovingOneMembershipKeepsTheOtherMembersAndGroups(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, self::ADMIN_GROUP);
        $this->addUser(2, self::PLAIN_TEMPL, self::ADMIN_GROUP, self::OTHER_ADMIN_GROUP);

        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::memberRemoved(self::ADMIN_GROUP, 1)));
        // User 2 still holds it through the other group
        $this->assertSame(2, $this->repository->countUberusersAfter(UberuserChange::memberRemoved(self::ADMIN_GROUP, 2)));
    }

    public function testRemovingTheLastMembershipLeavesNobody(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, self::ADMIN_GROUP);

        $this->assertSame(0, $this->repository->countUberusersAfter(UberuserChange::memberRemoved(self::ADMIN_GROUP, 1)));
    }

    public function testAGroupTemplateChangeUsesTheNewTemplate(): void
    {
        $this->addUser(1, self::PLAIN_TEMPL, self::ADMIN_GROUP);

        $this->assertSame(0, $this->repository->countUberusersAfter(UberuserChange::groupTemplateChanged(self::ADMIN_GROUP, self::PLAIN_GROUP_TEMPL)));
        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::groupTemplateChanged(self::ADMIN_GROUP, self::ADMIN_TEMPL)));
        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::groupTemplateChanged(self::OTHER_ADMIN_GROUP, self::PLAIN_GROUP_TEMPL)));
    }

    public function testAGroupTemplateChangeCanGrantSuperAdmin(): void
    {
        $this->db->exec('UPDATE user_groups SET perm_templ = ' . self::PLAIN_GROUP_TEMPL . ' WHERE id = ' . self::ADMIN_GROUP);
        $this->addUser(1, self::PLAIN_TEMPL, self::ADMIN_GROUP);

        $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::groupTemplateChanged(self::ADMIN_GROUP, self::ADMIN_GROUP_TEMPL)));
    }

    public function testInactiveUsersAreNotCounted(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->db->exec('UPDATE users SET active = 0 WHERE id = 1');

        $this->assertSame(0, $this->repository->countUberusersAfter(UberuserChange::none()));
    }

    public function testTheCountInsideATransactionTakesTheLockedPath(): void
    {
        $this->addUser(1, self::ADMIN_TEMPL);
        $this->addUser(2, self::PLAIN_TEMPL, self::ADMIN_GROUP);

        $this->db->beginTransaction();
        try {
            $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::groupDeleted(self::ADMIN_GROUP)));
            $this->assertSame(1, $this->repository->countUberusersAfter(UberuserChange::templateDropsUberuser(self::ADMIN_TEMPL)));
        } finally {
            $this->db->rollBack();
        }
    }
}
