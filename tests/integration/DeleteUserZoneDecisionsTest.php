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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * The per-zone decisions of the delete-user form may only name zones the deleted user owns,
 * and a delete decision needs the caller's zone delete right, not just the content edit right.
 */
class DeleteUserZoneDecisionsTest extends TestCase
{
    private const CALLER = 5;
    private const DELETED = 2;
    private const HEIR = 3;
    private const OWN_ZONE = 50;
    private const FOREIGN_ZONE = 60;

    private PDO $db;

    protected function setUp(): void
    {
        CanonicalZoneSql::setRowIdFallback(false);
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT NULL, owner INTEGER NOT NULL, comment TEXT NULL, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, fullname TEXT NOT NULL DEFAULT '', "
            . "email TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, perm_templ INTEGER NOT NULL)"
        );
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE username_recovery_requests (id INTEGER PRIMARY KEY, email TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_edit_others'), (2, 'zone_content_edit_others'), (3, 'zone_delete_others'), (4, 'zone_meta_edit_others')");
        // Template 1 holds the delete right, template 2 only the content edit right
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'deleter'), (2, 'editor'), (3, 'plain')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (1, 2), (1, 3), (1, 4), (2, 1), (2, 2), (2, 4)");
        foreach ([self::CALLER => 'caller', self::DELETED => 'leaver', self::HEIR => 'heir'] as $id => $name) {
            $this->db->exec("INSERT INTO users (id, username, fullname, perm_templ) VALUES ($id, '$name', '$name', 3)");
        }
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::OWN_ZONE . ", " . self::DELETED . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, " . self::FOREIGN_ZONE . ", " . self::CALLER . ")");

        $_SESSION['userid'] = self::CALLER;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
        CanonicalZoneSql::setRowIdFallback(true);
    }

    #[RunInSeparateProcess]
    public function testANonArrayDecisionRowIsRefused(): void
    {
        $this->setCallerTemplate(1);

        $ok = $this->deleteUser([(string)self::OWN_ZONE => 'delete']);

        $this->assertFalse($ok);
        $this->assertTrue($this->userExists(self::DELETED));
    }

    #[RunInSeparateProcess]
    public function testAZoneTheDeletedUserDoesNotOwnIsRefused(): void
    {
        $this->setCallerTemplate(1);

        $ok = $this->deleteUser([['zid' => self::FOREIGN_ZONE, 'target' => 'delete']]);

        $this->assertFalse($ok);
        $this->assertTrue($this->userExists(self::DELETED));
        $this->assertSame(1, $this->zoneRowCount(self::FOREIGN_ZONE));
    }

    #[RunInSeparateProcess]
    public function testAHandOverOfAZoneTheDeletedUserDoesNotOwnIsRefused(): void
    {
        $this->setCallerTemplate(1);

        $ok = $this->deleteUser([['zid' => self::FOREIGN_ZONE, 'target' => 'new_owner', 'newowner' => self::HEIR]]);

        $this->assertFalse($ok);
        $this->assertTrue($this->userExists(self::DELETED));
        $this->assertSame(1, $this->zoneRowCount(self::FOREIGN_ZONE));
    }

    #[RunInSeparateProcess]
    public function testADeleteDecisionWithoutTheZoneDeleteRightIsRefusedBeforeAnyWrite(): void
    {
        $this->setCallerTemplate(2);

        $ok = $this->deleteUser([
            ['zid' => self::OWN_ZONE, 'target' => 'new_owner', 'newowner' => self::HEIR],
            ['zid' => self::OWN_ZONE, 'target' => 'delete'],
        ]);

        $this->assertFalse($ok);
        $this->assertTrue($this->userExists(self::DELETED));
        $this->assertSame(1, $this->zoneRowCount(self::OWN_ZONE));
        $this->assertSame(self::DELETED, (int)$this->db->query("SELECT owner FROM zones WHERE domain_id = " . self::OWN_ZONE)->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testAHandOverOfTheDeletedUsersZoneStillWorksWithoutTheDeleteRight(): void
    {
        $this->setCallerTemplate(2);

        $ok = $this->deleteUser([['zid' => self::OWN_ZONE, 'target' => 'new_owner', 'newowner' => self::HEIR]]);

        $this->assertTrue($ok);
        $this->assertFalse($this->userExists(self::DELETED));
        $this->assertSame(self::HEIR, (int)$this->db->query("SELECT owner FROM zones WHERE domain_id = " . self::OWN_ZONE)->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testAZoneOfTheDeletedUserIsDeletedWithTheDeleteRight(): void
    {
        $this->setCallerTemplate(1);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, master TEXT, last_check INTEGER, type TEXT NOT NULL, notified_serial INTEGER, account TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)");
        $this->db->exec("CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)");
        $this->db->exec("CREATE TABLE record_comment_links (id INTEGER PRIMARY KEY, record_id INTEGER, comment_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::OWN_ZONE . ", 'a.example', 'NATIVE')");

        $ok = $this->deleteUser([['zid' => self::OWN_ZONE, 'target' => 'delete']]);

        $this->assertTrue($ok, 'A delete decision on the leaver\'s own zone with the delete right must go through.');
        $this->assertFalse($this->userExists(self::DELETED));
        $this->assertSame(0, $this->zoneRowCount(self::OWN_ZONE));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM domains WHERE id = " . self::OWN_ZONE)->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testALeaveRowForAZoneTheDeletedUserDoesNotOwnIsIgnored(): void
    {
        $this->setCallerTemplate(2);

        // The no-JS form posts a "leave" row for every listed zone
        $ok = $this->deleteUser([['zid' => self::FOREIGN_ZONE, 'target' => 'leave']]);

        $this->assertTrue($ok);
        $this->assertFalse($this->userExists(self::DELETED));
        $this->assertSame(1, $this->zoneRowCount(self::FOREIGN_ZONE));
    }

    #[RunInSeparateProcess]
    public function testAZoneHeldThroughAGroupCanBeHandedOver(): void
    {
        $this->setCallerTemplate(2);
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::DELETED . ", 9)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (" . self::FOREIGN_ZONE . ", 9)");

        $ok = $this->deleteUser([['zid' => self::FOREIGN_ZONE, 'target' => 'new_owner', 'newowner' => self::HEIR]]);

        $this->assertTrue($ok);
        $this->assertFalse($this->userExists(self::DELETED));
    }

    private function setCallerTemplate(int $templateId): void
    {
        $this->db->exec("UPDATE users SET perm_templ = $templateId WHERE id = " . self::CALLER);
    }

    /** @param list<array<string, mixed>> $decisions */
    private function deleteUser(array $decisions): bool
    {
        $config = $this->createMock(ConfigurationManager::class);
        $values = [
            'dns' => ['backend' => 'sql'],
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ];
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => $values[$group][$key] ?? $default
        );

        return (new UserManager($this->db, $config))->deleteUser(self::DELETED, $decisions);
    }

    private function zoneRowCount(int $domainId): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM zones WHERE domain_id = $domainId")->fetchColumn();
    }

    private function userExists(int $id): bool
    {
        return (bool)$this->db->query("SELECT 1 FROM users WHERE id = $id")->fetchColumn();
    }
}
