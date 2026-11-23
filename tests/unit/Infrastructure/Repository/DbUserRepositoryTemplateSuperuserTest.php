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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * A template carrying a permission matters to superusers when one holds it directly
 * or through a group.
 */
class DbUserRepositoryTemplateSuperuserTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT, descr TEXT)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY AUTOINCREMENT, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, user_id INTEGER)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser'), (69, 'user_enforce_mfa'), (5, 'zone_content_view_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'Administrator'), (2, 'Plain'), (3, 'Admins group'), (4, 'Users group')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53), (2, 5), (2, 69), (3, 69), (4, 69)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (1, 'root', 1), (2, 'bob', 2), (3, 'gary', 2)");
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (1, 'admins', 3), (2, 'users', 4)");
    }

    private function repository(): DbUserRepository
    {
        return new DbUserRepository($this->db, $this->createMock(ConfigurationManager::class));
    }

    public function testAnOrdinaryTemplateHeldOnlyByOrdinaryUsersIsNotSuperuserHeld(): void
    {
        $this->assertFalse($this->repository()->templateGrantsToSuperuser(2, 'user_enforce_mfa'));
    }

    public function testATemplateAssignedDirectlyToASuperuserCounts(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2 WHERE id = 1");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (2, 53)");

        $this->assertTrue($this->repository()->templateGrantsToSuperuser(2, 'user_enforce_mfa'));
    }

    public function testAGroupTemplateOfAGroupWithASuperuserMemberCounts(): void
    {
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (1, 1), (2, 2)");

        $this->assertTrue($this->repository()->templateGrantsToSuperuser(3, 'user_enforce_mfa'));
        $this->assertFalse($this->repository()->templateGrantsToSuperuser(4, 'user_enforce_mfa'));
    }

    public function testATemplateWithoutThePermissionNeverCounts(): void
    {
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (1, 1)");

        $this->assertFalse($this->repository()->templateGrantsToSuperuser(1, 'user_enforce_mfa'));
    }
}
