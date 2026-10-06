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

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * The users reads and writes that external provisioning (LDAP, OIDC, SAML) relies on.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryProvisioningTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password TEXT NOT NULL, fullname TEXT NOT NULL,
                    email TEXT NOT NULL, description TEXT NOT NULL, perm_templ INTEGER NOT NULL, perm_templ_source TEXT NOT NULL DEFAULT 'admin',
                    active INTEGER, use_ldap INTEGER NOT NULL, auth_method TEXT NOT NULL DEFAULT 'sql')",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT, template_type TEXT NOT NULL DEFAULT 'user')",
                "INSERT INTO perm_templ (id, name) VALUES (1, 'Administrator'), (5, 'Guest')",
                "INSERT INTO users (id, username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
                    VALUES (1, 'active', 'h', 'Active', 'shared@example.org', '', 5, 'admin', 1, 0, 'sql'),
                           (2, 'inactive', 'h', 'Inactive', 'shared@example.org', '', 5, 'admin', 0, 0, 'sql')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(), false);
    }

    public function testCreateProvisionedUserWritesEveryProvisioningColumn(): void
    {
        $id = $this->repository->createProvisionedUser([
            'username' => 'jane',
            'fullname' => 'Jane Doe',
            'email' => 'jane@example.org',
            'description' => 'Created via OIDC from okta',
            'perm_templ' => 5,
            'perm_templ_source' => 'oidc',
            'use_ldap' => 0,
            'auth_method' => 'oidc',
        ]);

        $row = $this->db->query("SELECT * FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('jane', $row['username']);
        $this->assertSame('', $row['password']);
        $this->assertSame('Jane Doe', $row['fullname']);
        $this->assertSame('Created via OIDC from okta', $row['description']);
        $this->assertSame(1, (int)$row['active']);
        $this->assertSame(5, (int)$row['perm_templ']);
        $this->assertSame('oidc', $row['perm_templ_source']);
        $this->assertSame(0, (int)$row['use_ldap']);
        $this->assertSame('oidc', $row['auth_method']);
    }

    public function testUpdateUserSwitchesAccountsToAndFromTheWebServer(): void
    {
        $this->db->exec("UPDATE users SET use_ldap = 1, auth_method = 'ldap' WHERE id = 1");

        $this->repository->updateUser(1, new UpdateUserCommand(useRemoteUser: true));
        $this->assertSame(['remote_user', 0], $this->authColumns(1), 'the LDAP flag is cleared with it');

        $this->repository->updateUser(1, new UpdateUserCommand(useLdap: false));
        $this->assertSame(['remote_user', 0], $this->authColumns(1), 'switching LDAP off keeps the web server');

        $this->repository->updateUser(1, new UpdateUserCommand(useRemoteUser: false));
        $this->assertSame(['sql', 0], $this->authColumns(1));
    }

    /** @return array{0: string, 1: int} */
    private function authColumns(int $id): array
    {
        $row = $this->db->query("SELECT auth_method, use_ldap FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

        return [$row['auth_method'], (int)$row['use_ldap']];
    }

    public function testFindInactiveUserIdByEmailSkipsActiveAccounts(): void
    {
        $this->assertSame(2, $this->repository->findInactiveUserIdByEmail('shared@example.org'));
        $this->assertNull($this->repository->findInactiveUserIdByEmail('nobody@example.org'));

        // PostgreSQL allows users.active to be NULL, which every login check treats as disabled
        $this->db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, perm_templ_source, active, use_ldap, auth_method)
            VALUES (3, 'nulled', 'h', 'Nulled', 'nulled@example.org', '', 5, 'admin', NULL, 0, 'sql')");
        $this->assertSame(3, $this->repository->findInactiveUserIdByEmail('nulled@example.org'));
    }

    public function testIsActiveUserIsFalseForDisabledAndMissingAccounts(): void
    {
        $this->assertTrue($this->repository->isActiveUser(1));
        $this->assertFalse($this->repository->isActiveUser(2));
        $this->assertFalse($this->repository->isActiveUser(99));
    }

    public function testUpdateProvisionedUserChangesOnlyTheGivenColumns(): void
    {
        $this->repository->updateProvisionedUser(1, ['fullname' => 'Renamed', 'perm_templ' => 1, 'perm_templ_source' => 'saml']);

        $this->assertSame(
            ['fullname' => 'Renamed', 'email' => 'shared@example.org', 'auth_method' => 'sql', 'perm_templ' => 1, 'perm_templ_source' => 'saml'],
            $this->repository->getProvisioningProfile(1)
        );
    }

    public function testUpdateProvisionedUserWithNoFieldsIsANoOp(): void
    {
        $before = $this->repository->getProvisioningProfile(1);
        $this->repository->updateProvisionedUser(1, []);

        $this->assertSame($before, $this->repository->getProvisioningProfile(1));
    }

    public function testUpdateProvisionedUserRejectsColumnsOutsideItsOwnership(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->updateProvisionedUser(1, ['password' => 'x']);
    }

    public function testProvisioningProfileIsEmptyForAMissingUser(): void
    {
        $this->assertSame([], $this->repository->getProvisioningProfile(99));
    }

    public function testFindActiveUserIdByEmailSkipsInactiveAccounts(): void
    {
        $this->assertSame(1, $this->repository->findActiveUserIdByEmail('shared@example.org'));
        $this->assertNull($this->repository->findActiveUserIdByEmail('nobody@example.org'));
    }

    public function testFindPermissionTemplateIdByNameIsExact(): void
    {
        $this->assertSame(5, $this->repository->findPermissionTemplateIdByName('Guest'));
        $this->assertNull($this->repository->findPermissionTemplateIdByName('Guests'));
    }
}
