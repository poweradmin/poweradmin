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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;

class ApiPermissionServiceTemplateSubsetTest extends TestCase
{
    private PDO $db;
    private ApiPermissionService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");

        // Template 10: perms 1,2 (the caller's). Template 20: perm 3. Template 30: perms 1,2,3.
        foreach ([[10, 1], [10, 2], [20, 3], [30, 1], [30, 2], [30, 3]] as [$templ, $perm]) {
            $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES ($templ, $perm)");
        }
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (7, 'delegate', 10)");

        $this->service = new ApiPermissionService($this->db);
    }

    public function testTemplateWithinTheUsersOwnTemplateDoesNotExceed(): void
    {
        $this->assertFalse($this->service->templateExceedsUserPermissions(7, 10));
    }

    public function testTemplateWithAnExtraPermissionExceeds(): void
    {
        $this->assertTrue($this->service->templateExceedsUserPermissions(7, 30));
    }

    public function testGroupTemplatePermissionsCountAsHeld(): void
    {
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (5, 'ops', 20)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (7, 5)");

        $this->assertFalse($this->service->templateExceedsUserPermissions(7, 30));
    }
}
