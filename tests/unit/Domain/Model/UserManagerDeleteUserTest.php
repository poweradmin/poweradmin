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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The web delete path refuses to remove the last active super admin, like the
 * API does.
 */
#[CoversClass(UserManager::class)]
class UserManagerDeleteUserTest extends SqliteIntegrationTestCase
{
    private const SECOND_ADMIN = 3;
    private const PLAIN_USER = 4;
    private const PLAIN_PERM_TEMPL_ID = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                "ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "INSERT INTO perm_templ (id, name) VALUES (" . self::PLAIN_PERM_TEMPL_ID . ", 'Plain')",
                "INSERT INTO users (id, username, perm_templ) VALUES (" . self::PLAIN_USER . ", 'plain', " . self::PLAIN_PERM_TEMPL_ID . ")",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['messages']);
        parent::tearDown();
    }

    #[Test]
    public function testTheLastSuperAdminCannotBeDeleted(): void
    {
        $this->assertFalse($this->manager()->deleteUser(self::ADMIN_USER_ID, []));
        $this->assertSame(1, $this->userRows(self::ADMIN_USER_ID));
    }

    #[Test]
    public function testASuperAdminCanBeDeletedWhileAnotherRemains(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::SECOND_ADMIN . ", 'second', " . self::ADMIN_PERM_TEMPL_ID . ")");

        $this->assertTrue($this->manager()->deleteUser(self::SECOND_ADMIN, []));
        $this->assertSame(0, $this->userRows(self::SECOND_ADMIN));
        $this->assertSame(1, $this->userRows(self::ADMIN_USER_ID));
    }

    #[Test]
    public function testAnInactiveSecondSuperAdminDoesNotCount(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ, active) VALUES (" . self::SECOND_ADMIN . ", 'second', " . self::ADMIN_PERM_TEMPL_ID . ", 0)");

        $this->assertFalse($this->manager()->deleteUser(self::ADMIN_USER_ID, []));
        $this->assertSame(1, $this->userRows(self::ADMIN_USER_ID));
    }

    #[Test]
    public function testAnOrdinaryUserIsDeleted(): void
    {
        $this->assertTrue($this->manager()->deleteUser(self::PLAIN_USER, []));
        $this->assertSame(0, $this->userRows(self::PLAIN_USER));
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed =>
                $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        return new UserManager($this->db, $config);
    }

    private function userRows(int $userId): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM users WHERE id = ' . $userId)->fetchColumn();
    }
}
