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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Deleting a user also removes the password reset tokens and username recovery
 * requests stored for their email address.
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
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT NOT NULL DEFAULT '')",
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE saml_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_preferences (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_agreements (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_keys (id INTEGER PRIMARY KEY, created_by INTEGER NULL)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "CREATE TABLE username_recovery_requests (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "INSERT INTO users VALUES (1, 'gone', 'gone@example.com'), (2, 'stays', 'stays@example.com')",
                "INSERT INTO password_reset_tokens (email) VALUES ('gone@example.com'), ('stays@example.com')",
                "INSERT INTO username_recovery_requests (email) VALUES ('gone@example.com'), ('stays@example.com')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->repository = new DbUserRepository($this->db, $this->createMock(ConfigurationManager::class));
    }

    public function testDeleteUserRemovesResetAndRecoveryRequestsForTheirEmail(): void
    {
        $this->assertTrue($this->repository->deleteUser(1));

        $this->assertSame(0, $this->rows("password_reset_tokens WHERE email = 'gone@example.com'"));
        $this->assertSame(0, $this->rows("username_recovery_requests WHERE email = 'gone@example.com'"));
        $this->assertSame(1, $this->rows("password_reset_tokens WHERE email = 'stays@example.com'"));
        $this->assertSame(1, $this->rows("username_recovery_requests WHERE email = 'stays@example.com'"));
    }

    public function testResetAndRecoveryRequestsStayWhileAnotherAccountSharesTheEmail(): void
    {
        $this->db->exec("UPDATE users SET email = 'stays@example.com' WHERE id = 1");

        $this->assertTrue($this->repository->deleteUser(1));

        $this->assertSame(1, $this->rows("password_reset_tokens WHERE email = 'stays@example.com'"));
        $this->assertSame(1, $this->rows("username_recovery_requests WHERE email = 'stays@example.com'"));
    }

    public function testDeleteUserRemovesEveryRowThatPointsAtTheId(): void
    {
        foreach (
            [
                "INSERT INTO oidc_user_links (user_id) VALUES (1), (2)",
                "INSERT INTO saml_user_links (user_id) VALUES (1), (2)",
                "INSERT INTO user_preferences (user_id) VALUES (1), (2)",
                "INSERT INTO user_mfa (user_id) VALUES (1), (2)",
                "INSERT INTO login_attempts (user_id) VALUES (1), (2)",
                "INSERT INTO user_agreements (user_id) VALUES (1), (2)",
                "INSERT INTO user_group_members (user_id, group_id) VALUES (1, 9), (2, 9)",
                "INSERT INTO api_keys (created_by) VALUES (1), (2)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->assertTrue($this->repository->deleteUser(1));

        foreach (['oidc_user_links', 'saml_user_links', 'user_preferences', 'user_mfa', 'login_attempts', 'user_agreements', 'user_group_members'] as $table) {
            $this->assertSame(0, $this->rows("$table WHERE user_id = 1"), $table);
            $this->assertSame(1, $this->rows("$table WHERE user_id = 2"), $table);
        }
        $this->assertSame(0, $this->rows("api_keys WHERE created_by = 1"));
        $this->assertSame(1, $this->rows("api_keys WHERE created_by = 2"));
    }

    public function testDeleteUserRemovesTheirTemplatesAndUnlinksTheZones(): void
    {
        foreach (
            [
                "INSERT INTO zone_templ (id, name, owner) VALUES (5, 'mine', 1), (6, 'theirs', 2), (7, 'global', 0)",
                "INSERT INTO zone_templ_records (zone_templ_id) VALUES (5), (5), (6)",
                "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (10, 1, 5), (11, 2, 6)",
                "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (10, 2, 5), (11, 2, 6)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->assertTrue($this->repository->deleteUser(1));

        $this->assertSame([6, 7], array_map('intval', $this->db->query("SELECT id FROM zone_templ ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(1, $this->rows("zone_templ_records"));
        $this->assertSame(1, $this->rows("records_zone_templ WHERE zone_templ_id = 6"));
        $this->assertSame(0, $this->rows("records_zone_templ WHERE zone_templ_id = 5"));
        $this->assertSame([0, 6], array_map('intval', $this->db->query("SELECT zone_templ_id FROM zones ORDER BY domain_id")->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
