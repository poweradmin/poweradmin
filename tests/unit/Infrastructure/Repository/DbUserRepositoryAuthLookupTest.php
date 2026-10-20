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
 * Pins the active/use_ldap filters the authenticators depend on: dropping one of
 * them would let a disabled or directory-managed account log in locally.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryAuthLookupTest extends TestCase
{
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, password TEXT,
            email TEXT, active INTEGER, use_ldap INTEGER, auth_method TEXT)");
        $db->exec("INSERT INTO users (id, username, fullname, password, email, active, use_ldap, auth_method) VALUES
            (1, 'alice', 'Alice', 'hash-alice', 'alice@example.test', 1, 0, 'sql'),
            (2, 'bob', 'Bob', 'hash-bob', 'bob@example.test', 1, 1, 'ldap'),
            (3, 'carol', 'Carol', 'hash-carol', 'carol@example.test', 0, 1, 'ldap'),
            (4, 'dave', 'Dave', 'hash-dave', 'dave@example.test', 0, 0, NULL)");

        $this->repository = new DbUserRepository($db, new FakeConfiguration(), false);
    }

    public function testSqlLoginRowExcludesLdapAccounts(): void
    {
        $row = $this->repository->findSqlLoginUser('alice');

        $this->assertSame(['id' => '1', 'fullname' => 'Alice', 'password' => 'hash-alice', 'active' => '1', 'email' => 'alice@example.test'], array_map('strval', $row));
        $this->assertNull($this->repository->findSqlLoginUser('bob'));
    }

    public function testSqlLoginRowStillReturnsInactiveAccounts(): void
    {
        // The authenticator reports the disabled account itself, so the row must come back.
        $this->assertNotNull($this->repository->findSqlLoginUser('dave'));
    }

    public function testLdapLoginRowRequiresActiveAndLdapEnabled(): void
    {
        $row = $this->repository->findActiveLdapUser('bob');

        $this->assertSame(['id' => '2', 'fullname' => 'Bob', 'email' => 'bob@example.test'], array_map('strval', $row));
        $this->assertNull($this->repository->findActiveLdapUser('carol'));
        $this->assertNull($this->repository->findActiveLdapUser('alice'));
    }

    public function testActiveLdapUserCheckMatchesTheLoginFilter(): void
    {
        $this->assertTrue($this->repository->hasActiveLdapUser('bob'));
        $this->assertFalse($this->repository->hasActiveLdapUser('carol'));
        $this->assertFalse($this->repository->hasActiveLdapUser('alice'));
        $this->assertFalse($this->repository->hasActiveLdapUser('nosuchuser'));
    }

    public function testBasicAuthRowRequiresAnActiveAccount(): void
    {
        $row = $this->repository->findBasicAuthUser('alice');

        $this->assertSame(['id' => '1', 'password' => 'hash-alice', 'use_ldap' => '0'], array_map('strval', $row));
        $this->assertNull($this->repository->findBasicAuthUser('carol'));
    }

    public function testAuthMethodRowSeparatesAMissingAccountFromAMissingValue(): void
    {
        $this->assertSame('ldap', $this->repository->findAuthMethodRow('bob')['auth_method']);
        $this->assertNull($this->repository->findAuthMethodRow('dave')['auth_method']);
        $this->assertNull($this->repository->findAuthMethodRow('nosuchuser'));
    }
}
