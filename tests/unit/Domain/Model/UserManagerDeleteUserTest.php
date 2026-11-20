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
                "ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "CREATE TABLE username_recovery_requests (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)",
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE saml_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE api_keys (id INTEGER PRIMARY KEY, created_by INTEGER)",
                "CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER NOT NULL, zone_id INTEGER NOT NULL)",
                "CREATE TABLE user_agreements (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL)",
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

    #[Test]
    public function testDeletingAUserRemovesTheResetAndRecoveryRequestsForTheirEmail(): void
    {
        $this->db->exec("UPDATE users SET email = 'plain@example.com' WHERE id = " . self::PLAIN_USER);
        $this->db->exec("INSERT INTO password_reset_tokens (email) VALUES ('plain@example.com')");
        $this->db->exec("INSERT INTO username_recovery_requests (email) VALUES ('plain@example.com')");

        $this->assertTrue($this->manager()->deleteUser(self::PLAIN_USER, []));

        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM password_reset_tokens")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM username_recovery_requests")->fetchColumn());
    }

    #[Test]
    public function testDeletingAUserClearsTheirLinkedRowsEvenWithoutTemplateEditRight(): void
    {
        $this->db->exec("INSERT INTO oidc_user_links (user_id) VALUES (" . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO saml_user_links (user_id) VALUES (" . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO user_mfa (user_id) VALUES (" . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO user_agreements (user_id) VALUES (" . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::PLAIN_USER . ", 1)");
        $this->db->exec("INSERT INTO api_keys (id, created_by) VALUES (10, " . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (10, 5)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (20, 'mine', " . self::PLAIN_USER . ")");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id) VALUES (20)");
        $this->db->exec("INSERT INTO records_zone_templ (zone_templ_id) VALUES (20)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (30, 30, " . self::ADMIN_USER_ID . ", 20)");
        // A caller who may delete users but holds no zone_templ_edit
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (54, 'user_edit_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (5, 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (5, 54)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (5, 'editor', 5)");
        $_SESSION['userid'] = 5;

        $this->assertTrue($this->manager()->deleteUser(self::PLAIN_USER, []));

        foreach (['oidc_user_links', 'saml_user_links', 'user_mfa', 'user_agreements', 'user_group_members', 'api_key_zones', 'zone_templ', 'zone_templ_records', 'records_zone_templ'] as $table) {
            $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
        }
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT zone_templ_id FROM zones WHERE id = 30")->fetchColumn());
    }

    #[Test]
    public function testADecisionOnAZoneTheUserDoesNotOwnRefusesTheWholeRequest(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (6, 'other', " . self::PLAIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 10, " . self::PLAIN_USER . "), (2, 11, " . self::ADMIN_USER_ID . ")");

        $this->assertFalse($this->manager()->deleteUser(self::PLAIN_USER, [
            ['zid' => 10, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID],
            ['zid' => 11, 'target' => 'new_owner', 'newowner' => 6],
        ]));
        $this->assertSame(1, $this->userRows(self::PLAIN_USER));
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
    }

    #[Test]
    public function testADeleteDecisionNeedsTheCallersZoneDeleteRight(): void
    {
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (54, 'user_edit_others'), (55, 'zone_delete_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (5, 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (5, 54)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (5, 'editor', 5)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 10, " . self::PLAIN_USER . ")");
        $_SESSION['userid'] = 5;

        $this->assertFalse($this->manager()->deleteUser(self::PLAIN_USER, [['zid' => 10, 'target' => 'delete']]));
        $this->assertSame(1, $this->userRows(self::PLAIN_USER));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
    }

    #[Test]
    public function testANonArrayDecisionRowIsRefused(): void
    {
        $this->assertFalse($this->manager()->deleteUser(self::PLAIN_USER, ['11' => 'delete']));
        $this->assertSame(1, $this->userRows(self::PLAIN_USER));
    }

    #[Test]
    public function testALeaveRowOnAForeignZoneIsIgnored(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, 11, " . self::ADMIN_USER_ID . ")");

        $this->assertTrue($this->manager()->deleteUser(self::PLAIN_USER, [['zid' => 11, 'target' => 'leave']]));
        $this->assertSame(0, $this->userRows(self::PLAIN_USER));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones WHERE domain_id = 11")->fetchColumn());
    }

    #[Test]
    public function testAZoneHeldThroughAGroupCanBeHandedOver(): void
    {
        $this->db->exec("INSERT INTO user_groups (id, name) VALUES (1, 'team')");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::PLAIN_USER . ", 1)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (12, 1)");

        $check = new \ReflectionMethod(UserManager::class, 'zoneDecisionsAllowed');

        $this->assertTrue($check->invoke($this->manager(), self::PLAIN_USER, [
            ['zid' => 12, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID],
        ]));
    }

    #[Test]
    public function testOwnedZonesWithTheRightPassTheDecisionCheck(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 10, " . self::PLAIN_USER . ")");

        $check = new \ReflectionMethod(UserManager::class, 'zoneDecisionsAllowed');
        $manager = $this->manager();

        $this->assertTrue($check->invoke($manager, self::PLAIN_USER, [
            ['zid' => 10, 'target' => 'delete'],
            ['zid' => 10, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID],
        ]));
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
