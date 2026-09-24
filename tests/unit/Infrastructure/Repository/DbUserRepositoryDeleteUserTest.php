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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * Deleting a user removes every row keyed on the user, so the web UI and the
 * API leave the same state behind.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryDeleteUserTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)",
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE saml_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_preferences (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_keys (id INTEGER PRIMARY KEY, name TEXT, created_by INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "INSERT INTO users VALUES (1, 'gone', 2), (2, 'stays', 2)",
                "INSERT INTO oidc_user_links (user_id) VALUES (1), (2)",
                "INSERT INTO user_preferences (user_id) VALUES (1), (2)",
                "INSERT INTO user_mfa (user_id) VALUES (1), (2)",
                "INSERT INTO login_attempts (user_id) VALUES (1), (2)",
                "INSERT INTO user_group_members (user_id, group_id) VALUES (1, 5), (2, 5)",
                "INSERT INTO api_keys (name, created_by) VALUES ('gone-key', 1), ('stays-key', 2)",
                "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 1, 100), (11, 2, 200), (12, 2, 100)",
                "INSERT INTO zone_templ VALUES (100, 'private-gone', 1), (200, 'private-stays', 2), (300, 'global', 0)",
                "INSERT INTO zone_templ_records (zone_templ_id) VALUES (100), (200), (300)",
                "INSERT INTO records_zone_templ VALUES (10, 1, 100), (11, 2, 200)",
                "INSERT INTO records_zone_templ_api VALUES (10, 1, 100), (11, 2, 200)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $config = new FakeConfiguration(['database' => ['type' => 'sqlite']]);
        $this->repository = new DbUserRepository($this->db, $config, false);
    }

    public function testDeleteUserRemovesEveryRowKeyedOnTheUser(): void
    {
        $this->assertTrue($this->repository->deleteUser(1));

        foreach (
            [
                'users WHERE id = 1',
                'oidc_user_links WHERE user_id = 1',
                'user_preferences WHERE user_id = 1',
                'user_mfa WHERE user_id = 1',
                'login_attempts WHERE user_id = 1',
                'user_group_members WHERE user_id = 1',
                'api_keys WHERE created_by = 1',
                'zones WHERE owner = 1',
                'zone_templ WHERE owner = 1',
                'zone_templ_records WHERE zone_templ_id = 100',
                'records_zone_templ WHERE zone_templ_id = 100',
                'records_zone_templ_api WHERE zone_templ_id = 100',
                'zones WHERE zone_templ_id = 100',
            ] as $fromWhere
        ) {
            $this->assertSame(0, $this->rows($fromWhere), $fromWhere);
        }
        // The other user's zone that used the deleted template is unlinked, not deleted.
        $this->assertSame(1, $this->rows('zones WHERE domain_id = 12 AND zone_templ_id = 0'));
    }

    public function testDeleteUserLeavesOtherUsersAndGlobalTemplatesAlone(): void
    {
        $this->repository->deleteUser(1);

        foreach (
            [
                'users WHERE id = 2',
                'oidc_user_links WHERE user_id = 2',
                'user_group_members WHERE user_id = 2',
                'api_keys WHERE created_by = 2',
                'zones WHERE domain_id = 11 AND zone_templ_id = 200',
                'zone_templ WHERE owner = 2',
                'zone_templ WHERE id = 300',
                'zone_templ_records WHERE zone_templ_id = 200',
                'zone_templ_records WHERE zone_templ_id = 300',
                'records_zone_templ WHERE zone_templ_id = 200',
            ] as $fromWhere
        ) {
            $this->assertSame(1, $this->rows($fromWhere), $fromWhere);
        }
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
