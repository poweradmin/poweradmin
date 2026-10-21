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
 * Deleting a user with "transfer zones to a new owner". In API backend mode the deleted user's
 * zones row is the one carrying zone_name, so it has to change owner; dropping it and adding an
 * unnamed row for the new owner leaves the zone looking missing to the next sync.
 */
class DeleteUserHandOverZoneTest extends TestCase
{
    private const ADMIN = 1;
    private const DELETED = 2;
    private const NEW_OWNER = 3;
    private const ZONE = 7;

    private PDO $db;

    protected function setUp(): void
    {
        CanonicalZoneSql::setRowIdFallback(true);
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
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");

        // An ueberuser admin so the permission checks pass
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'Administrator')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53)");
        foreach ([self::ADMIN => 'admin', self::DELETED => 'leaver', self::NEW_OWNER => 'heir'] as $id => $name) {
            $this->db->exec("INSERT INTO users (id, username, fullname, perm_templ) VALUES ($id, '$name', '$name', 1)");
        }

        $_SESSION['userid'] = self::ADMIN;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
        CanonicalZoneSql::setRowIdFallback(true);
    }

    #[RunInSeparateProcess]
    public function testApiModeKeepsTheNamedRowWithTheNewOwner(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (" . self::ZONE . ", NULL, 'a.example.', " . self::DELETED . ")");

        $this->assertTrue($this->deleteUser('api', self::NEW_OWNER));

        $this->assertSame([[self::ZONE, 'a.example.', self::NEW_OWNER]], $this->zoneRows());
        $this->assertFalse($this->userExists(self::DELETED));
    }

    #[RunInSeparateProcess]
    public function testApiModeKeepsTheNamedRowOfAZoneWithADomainId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (3, 50, 'b.example.', " . self::DELETED . ")");

        $this->assertTrue($this->deleteUser('api', self::NEW_OWNER, 50));

        $this->assertSame([[3, 'b.example.', self::NEW_OWNER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testApiModeMergesAnUnnamedRowTheNewOwnerAlreadyHolds(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (" . self::ZONE . ", NULL, 'a.example.', " . self::DELETED . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (20, " . self::ZONE . ", NULL, " . self::NEW_OWNER . ")");

        $this->assertTrue($this->deleteUser('api', self::NEW_OWNER));

        $this->assertSame([[self::ZONE, 'a.example.', self::NEW_OWNER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testApiModeDoesNotDuplicateTheOwnerWhenTheNewOwnerHoldsTheNamedRow(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (" . self::ZONE . ", NULL, 'a.example.', " . self::NEW_OWNER . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (21, " . self::ZONE . ", NULL, " . self::DELETED . ")");

        $this->assertTrue($this->deleteUser('api', self::NEW_OWNER));

        $this->assertSame([[self::ZONE, 'a.example.', self::NEW_OWNER]], $this->zoneRows());
    }

    #[RunInSeparateProcess]
    public function testSqlModeAddsTheNewOwnerAndDropsTheDeletedUser(): void
    {
        CanonicalZoneSql::setRowIdFallback(false);
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (1, 50, NULL, " . self::DELETED . ")");

        $this->assertTrue($this->deleteUser('sql', self::NEW_OWNER, 50));

        $rows = $this->db->query("SELECT domain_id, owner FROM zones")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([[50, self::NEW_OWNER]], array_map(static fn(array $r): array => [(int)$r[0], (int)$r[1]], $rows));
    }

    #[RunInSeparateProcess]
    public function testSqlModeKeepsTheNewOwnerRowWhenTheyAlreadyOwnTheZone(): void
    {
        CanonicalZoneSql::setRowIdFallback(false);
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (1, 50, NULL, " . self::DELETED . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (2, 50, NULL, " . self::NEW_OWNER . ")");

        $this->deleteUser('sql', self::NEW_OWNER, 50);

        $rows = $this->db->query("SELECT domain_id, owner FROM zones")->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([[50, self::NEW_OWNER]], array_map(static fn(array $r): array => [(int)$r[0], (int)$r[1]], $rows));
    }

    #[RunInSeparateProcess]
    public function testTheDeletedUserCannotBeTheNewOwner(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (" . self::ZONE . ", NULL, 'a.example.', " . self::DELETED . ")");

        $this->assertFalse($this->deleteUser('api', self::DELETED));

        $this->assertSame([[self::ZONE, 'a.example.', self::DELETED]], $this->zoneRows());
        $this->assertTrue($this->userExists(self::DELETED), 'The user must not be deleted when the request is refused.');
    }

    #[RunInSeparateProcess]
    public function testTheDeletedUserIsLeftOutOfTheNewOwnerList(): void
    {
        $ids = array_map(static fn(array $u): int => (int)$u['id'], UserManager::showUsers($this->db, self::DELETED));

        $this->assertSame([self::ADMIN, self::NEW_OWNER], array_values(array_filter($ids, static fn(int $id): bool => $id !== self::DELETED)));
        $this->assertNotContains(self::DELETED, $ids);
    }

    private function deleteUser(string $backend, int $newOwner, int $zoneId = self::ZONE): bool
    {
        $config = $this->createMock(ConfigurationManager::class);
        $values = [
            'dns' => ['backend' => $backend],
            'pdns_api' => ['url' => 'http://127.0.0.1:1', 'key' => 'unused'],
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ];
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => $values[$group][$key] ?? $default
        );

        $manager = new UserManager($this->db, $config);

        return $manager->deleteUser(self::DELETED, [['zid' => $zoneId, 'target' => 'new_owner', 'newowner' => $newOwner]]);
    }

    /** @return list<array{int, ?string, int}> */
    private function zoneRows(): array
    {
        $rows = $this->db->query("SELECT id, zone_name, owner FROM zones ORDER BY id")->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $r): array => [(int)$r[0], $r[1], (int)$r[2]], $rows);
    }

    private function userExists(int $id): bool
    {
        return (bool)$this->db->query("SELECT 1 FROM users WHERE id = $id")->fetchColumn();
    }
}
