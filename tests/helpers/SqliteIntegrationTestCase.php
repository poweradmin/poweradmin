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

namespace TestHelpers;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;

/**
 * Base test case for integration tests that need a throwaway in-memory SQLite
 * database with Poweradmin's permission system wired up enough to satisfy
 * Permission::getEditPermission() / UserManager::verifyPermission().
 *
 * What you get out of the box:
 *   - $this->db         : in-memory PDO with PRAGMA foreign_keys ON
 *   - $this->config     : FakeConfiguration with database.type=sqlite, no PDNS prefix
 *   - The perm tables and an admin user (id = ADMIN_USER_ID) holding
 *     user_is_ueberuser, so getEditPermission() resolves to "all".
 *
 * Tests are responsible for creating any domain-specific tables they need
 * (zones, domains, etc.) inside their own setUp().
 *
 * Note: UserManager::verifyPermission() caches results in a function-level
 * static, which leaks across tests sharing a process. Add #[RunInSeparateProcess]
 * to test methods that change permissions or want a clean cache.
 */
abstract class SqliteIntegrationTestCase extends TestCase
{
    public const ADMIN_USER_ID = 1;
    public const ADMIN_PERM_TEMPL_ID = 1;

    protected PDO $db;
    protected FakeConfiguration $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->bootstrapPermissionTables();
        $this->seedAdminWithUeberuser();

        $this->config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ]);

        $_SESSION['userid'] = static::ADMIN_USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    /**
     * Stub provider that lets tests pick whether the API or SQL code path runs.
     */
    protected function dnsBackendStub(bool $isApi): DnsBackendProvider&MockObject
    {
        $stub = $this->createMock(DnsBackendProvider::class);
        $stub->method('isApiBackend')->willReturn($isApi);
        return $stub;
    }

    /**
     * Tables that UserManager::verifyPermission walks via its UNION query:
     * users -> perm_templ -> perm_templ_items -> perm_items, plus the
     * user_groups / user_group_members branch.
     */
    private function bootstrapPermissionTables(): void
    {
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, perm_templ INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
    }

    private function seedAdminWithUeberuser(): void
    {
        // perm_items ids and the ueberuser shortcut match Poweradmin's seed
        // data so verifyPermission's `user_is_ueberuser` check resolves true.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES
            (47, 'zone_content_edit_others'),
            (53, 'user_is_ueberuser')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . static::ADMIN_PERM_TEMPL_ID . ", 'Administrator')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES
            (" . static::ADMIN_PERM_TEMPL_ID . ", 47),
            (" . static::ADMIN_PERM_TEMPL_ID . ", 53)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . static::ADMIN_USER_ID . ", 'admin', " . static::ADMIN_PERM_TEMPL_ID . ")");
    }
}
