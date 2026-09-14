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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The web delete path must refuse to remove the last super admin, like the API
 * does, and must leave no rows keyed on the deleted user behind.
 */
#[CoversClass(UserManager::class)]
class UserManagerDeleteUserTest extends SqliteIntegrationTestCase
{
    private const SECOND_ADMIN = 3;
    private const USER_ADMIN = 4;
    private const TARGET = 5;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_preferences (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->createZoneTables();
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        return new UserManager($this->db, $config);
    }

    public function testLastUeberuserCannotBeDeleted(): void
    {
        $this->assertFalse($this->manager()->deleteUser(self::ADMIN_USER_ID, []));

        $this->assertSame(1, $this->rows('users WHERE id = ' . self::ADMIN_USER_ID));
    }

    public function testAnUeberuserCanBeDeletedWhileAnotherRemains(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::SECOND_ADMIN . ", 'second', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::SECOND_ADMIN . ", 9)");
        $this->db->exec("INSERT INTO zone_templ VALUES (50, 'private', " . self::SECOND_ADMIN . ")");

        $this->assertTrue($this->manager()->deleteUser(self::SECOND_ADMIN, []));

        $this->assertSame(0, $this->rows('users WHERE id = ' . self::SECOND_ADMIN));
        $this->assertSame(0, $this->rows('user_group_members WHERE user_id = ' . self::SECOND_ADMIN));
        $this->assertSame(0, $this->rows('zone_templ WHERE owner = ' . self::SECOND_ADMIN));
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::ADMIN_USER_ID));
    }

    #[RunInSeparateProcess]
    public function testARefusedZoneDeletionKeepsTheUserAndTouchesNoZone(): void
    {
        $this->primeConfigurationManager();
        // The acting user may delete users and their own zones, but not zones owned by others.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (44, 'user_edit_others'), (50, 'zone_delete_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (20, 'User admin')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (20, 44), (20, 50)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ADMIN . ", 'useradmin', 20), (" . self::TARGET . ", 'target', 20)");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::USER_ADMIN . "), (11, " . self::TARGET . ")");
        $_SESSION['userid'] = self::USER_ADMIN;

        $decisions = [['zid' => 10, 'target' => 'delete'], ['zid' => 11, 'target' => 'delete']];
        $this->assertFalse($this->manager()->deleteUser(self::TARGET, $decisions));

        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(2, $this->rows('zones'));
    }

    #[RunInSeparateProcess]
    public function testAReassignmentWithoutTheMetaGrantKeepsTheUser(): void
    {
        $this->primeConfigurationManager();
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (44, 'user_edit_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (20, 'User admin')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (20, 44)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ADMIN . ", 'useradmin', 20), (" . self::TARGET . ", 'target', 20)");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . ")");
        $_SESSION['userid'] = self::USER_ADMIN;

        $decisions = [['zid' => 10, 'target' => 'new_owner', 'newowner' => self::USER_ADMIN]];
        $this->assertFalse($this->manager()->deleteUser(self::TARGET, $decisions));

        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(0, $this->rows('zones WHERE owner = ' . self::USER_ADMIN));
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
