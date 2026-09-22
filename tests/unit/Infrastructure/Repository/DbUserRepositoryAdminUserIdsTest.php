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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * getAdminUserIds() must agree with hasAdminPermission() for every user, so a
 * listing can prime the admin flags with one query.
 */
class DbUserRepositoryAdminUserIdsTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER, active INTEGER)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT, template_type TEXT DEFAULT 'user')",
                "CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)",
                "CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)",
                "INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser'), (41, 'zone_master_add')",
                "INSERT INTO perm_templ (id, name) VALUES (1, 'Administrator'), (2, 'Editor'), (3, 'Group admins')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53), (2, 41), (3, 53)",
                "INSERT INTO users (id, username, perm_templ, active) VALUES (1, 'root', 1, 1), (2, 'editor', 2, 1), (3, 'viagroup', 2, 1), (4, 'plain', 2, 1)",
                "INSERT INTO user_groups (id, name, perm_templ) VALUES (10, 'ops', 3)",
                "INSERT INTO user_group_members (group_id, user_id) VALUES (10, 3)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(), false);
    }

    public function testListsDirectAndGroupGrantedAdmins(): void
    {
        $ids = $this->repository->getAdminUserIds();
        sort($ids);

        $this->assertSame([1, 3], $ids);
        foreach ([1, 2, 3, 4] as $userId) {
            $this->assertSame(in_array($userId, $ids, true), $this->repository->hasAdminPermission($userId), "user $userId");
        }
    }

    public function testPrimedFlagsAnswerIsAdminWithoutFurtherQueries(): void
    {
        $permissions = new PermissionService($this->repository);
        $permissions->primeAdminFlags([1, 2, 3, 4]);
        $this->db->exec("DELETE FROM perm_templ_items");

        $this->assertTrue($permissions->isAdmin(1));
        $this->assertFalse($permissions->isAdmin(2));
        $this->assertTrue($permissions->isAdmin(3));
        $this->assertFalse($permissions->isAdmin(4));
    }
}
