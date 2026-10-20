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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\PermissionCatalogue;
use Poweradmin\Infrastructure\Database\SeedRepository;

class SeedRepositoryTest extends TestCase
{
    private PDO $db;
    private SeedRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64) NOT NULL, descr VARCHAR(1024) NOT NULL)');
        $this->db->exec("CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, template_type VARCHAR(10) NOT NULL DEFAULT 'user')");
        $this->db->exec('CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL)');
        $this->db->exec('CREATE TABLE user_groups (id integer PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT, perm_templ integer NOT NULL, created_by integer)');
        $this->db->exec(
            'CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64), password VARCHAR(128), fullname VARCHAR(255), ' .
            'email VARCHAR(255), description VARCHAR(1024), perm_templ integer, active integer, use_ldap integer, auth_method VARCHAR(20))'
        );
        $this->repository = new SeedRepository($this->db, 'sqlite');
    }

    #[Test]
    public function seedPermissionsWritesTheCatalogueRowsWithTheirIds(): void
    {
        $this->repository->seedPermissions();

        $rows = $this->db->query('SELECT id, name, descr FROM perm_items ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $rows = array_map(fn(array $r) => [(int)$r[0], $r[1], $r[2]], $rows);
        $this->assertSame(PermissionCatalogue::permissions(), $rows);
    }

    #[Test]
    public function seedDefaultTemplatesWritesTemplatesItemsAndGroupsAsTheSchemaFilesDo(): void
    {
        $this->repository->seedPermissions();
        $templateIds = $this->repository->seedDefaultTemplates();

        $expectedIds = [];
        foreach (PermissionCatalogue::templates() as $template) {
            $expectedIds[$template['name']] = $template['id'];
        }
        $this->assertSame($expectedIds, $templateIds);

        $templates = $this->db->query('SELECT id, name, descr, template_type FROM perm_templ ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $templates = array_map(fn(array $r) => [(int)$r[0], $r[1], $r[2], $r[3]], $templates);
        $this->assertSame(
            array_map(fn(array $t) => [$t['id'], $t['name'], $t['descr'], $t['template_type']], PermissionCatalogue::templates()),
            $templates
        );

        $items = $this->db->query('SELECT templ_id, perm_id FROM perm_templ_items ORDER BY templ_id, perm_id')->fetchAll(PDO::FETCH_NUM);
        $items = array_map(fn(array $r) => [(int)$r[0], (int)$r[1]], $items);
        $expectedItems = array_map(fn(array $r) => [$r[1], $r[2]], PermissionCatalogue::templateItems());
        sort($expectedItems);
        $this->assertSame($expectedItems, $items);

        $groups = $this->db->query('SELECT id, name, description, perm_templ, created_by FROM user_groups ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $groups = array_map(fn(array $r) => [(int)$r[0], $r[1], $r[2], (int)$r[3], $r[4]], $groups);
        $this->assertSame(
            array_map(fn(array $g) => [$g['id'], $g['name'], $g['description'], PermissionCatalogue::templateId($g['template']), null], PermissionCatalogue::groups()),
            $groups
        );
    }

    #[Test]
    public function createAdminUserStoresTheHashAgainstTheGivenTemplate(): void
    {
        $this->repository->createAdminUser('hashed-secret', 7);

        $row = $this->db->query('SELECT username, password, fullname, email, perm_templ, active, use_ldap, auth_method FROM users')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([
            'username' => 'admin',
            'password' => 'hashed-secret',
            'fullname' => 'Administrator',
            'email' => 'admin@example.net',
            'perm_templ' => 7,
            'active' => 1,
            'use_ldap' => 0,
            'auth_method' => 'sql',
        ], $row);
    }
}
