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

/**
 * Zone decisions posted with a user deletion: only zones the deleted user owns, and a delete
 * decision needs the caller's zone delete right.
 */
class DeleteUserZoneDecisionGateTest extends TestCase
{
    private const CALLER = 1;
    private const DELETED = 2;
    private const OTHER = 3;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, owner INTEGER NOT NULL, comment TEXT NULL, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, fullname TEXT NOT NULL DEFAULT '', "
            . "email TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, perm_templ INTEGER NOT NULL)"
        );
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'user_edit_others'), (2, 'zone_meta_edit_others'), (3, 'zone_delete_own'), (4, 'zone_delete_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'no delete'), (2, 'delete own')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1), (1, 2), (2, 1), (2, 2), (2, 3)");
        foreach ([self::CALLER => 1, self::DELETED => 1, self::OTHER => 1] as $id => $templ) {
            $this->db->exec("INSERT INTO users (id, username, fullname, perm_templ) VALUES ($id, 'u$id', 'u$id', $templ)");
        }
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 10, " . self::DELETED . "), (2, 20, " . self::CALLER . ")");

        $_SESSION['userid'] = self::CALLER;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testAZoneTheDeletedUserDoesNotOwnIsRefused(): void
    {
        $this->assertFalse($this->deleteUser([['zid' => 20, 'target' => 'new_owner', 'newowner' => self::OTHER]]));

        $this->assertTrue($this->userExists());
        $this->assertSame([[10, self::DELETED], [20, self::CALLER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testADeleteDecisionWithoutTheZoneDeleteRightIsRefused(): void
    {
        $this->assertFalse($this->deleteUser([['zid' => 10, 'target' => 'delete']]));

        $this->assertTrue($this->userExists());
        $this->assertSame([[10, self::DELETED], [20, self::CALLER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testOneRefusedDecisionStopsTheWholeRequest(): void
    {
        $this->assertFalse($this->deleteUser([
            ['zid' => 10, 'target' => 'new_owner', 'newowner' => self::OTHER],
            ['zid' => 20, 'target' => 'new_owner', 'newowner' => self::OTHER],
        ]));

        $this->assertTrue($this->userExists());
        $this->assertSame([[10, self::DELETED], [20, self::CALLER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testAnOwnedZoneStillGoesToTheNewOwner(): void
    {
        $this->assertTrue($this->deleteUser([['zid' => 10, 'target' => 'new_owner', 'newowner' => self::OTHER]]));

        $this->assertFalse($this->userExists());
        $this->assertSame([[10, self::OTHER], [20, self::CALLER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testALeaveRowOnAForeignZoneIsIgnored(): void
    {
        $this->assertTrue($this->deleteUser([['zid' => 20, 'target' => 'leave']]));

        $this->assertFalse($this->userExists());
    }

    #[RunInSeparateProcess]
    public function testAGroupOwnedZoneCanBeHandedOver(): void
    {
        $this->db->exec("INSERT INTO user_groups (id, name) VALUES (1, 'g')");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::DELETED . ", 1)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (30, 1)");

        $this->assertTrue($this->deleteUser([['zid' => 30, 'target' => 'new_owner', 'newowner' => self::OTHER]]));

        $this->assertFalse($this->userExists());
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

    /** @return list<array{int, int}> */
    private function zoneRows(): array
    {
        $rows = $this->db->query("SELECT domain_id, owner FROM zones ORDER BY domain_id")->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $r): array => [(int)$r[0], (int)$r[1]], $rows);
    }

    private function userExists(): bool
    {
        return (bool)$this->db->query("SELECT 1 FROM users WHERE id = " . self::DELETED)->fetchColumn();
    }
}
