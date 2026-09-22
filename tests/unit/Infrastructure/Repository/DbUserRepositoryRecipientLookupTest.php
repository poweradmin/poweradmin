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
 * Pins the recipient and recovery lookups: an address that reaches a disabled
 * account, or a blank one that reaches nobody, is the failure mode here.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryRecipientLookupTest extends TestCase
{
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT,
            auth_method TEXT, active INTEGER)");
        $db->exec("INSERT INTO users (id, username, fullname, email, auth_method, active) VALUES
            (1, 'alice', 'Alice', 'shared@example.test', 'sql', 1),
            (2, 'bob', 'Bob', '', 'sql', 1),
            (3, 'carol', 'Carol', 'carol@example.test', 'ldap', 0),
            (4, 'dave', 'Dave', NULL, 'sql', 1),
            (5, 'aaron', 'Aaron', 'shared@example.test', 'sql', 1),
            (6, 'erin', 'Erin', 'shared@example.test', 'sql', 0)");

        $this->repository = new DbUserRepository($db, new FakeConfiguration(), false);
    }

    public function testNotifiableUsersSkipBlankAndMissingAddressesAndInactiveAccounts(): void
    {
        $rows = $this->repository->listNotifiableUsers();

        $this->assertSame(['1', '5'], array_map('strval', array_column($rows, 'id')));
        $this->assertSame(['id', 'username', 'fullname', 'email'], array_keys($rows[0]));
    }

    public function testRecipientLookupReturnsTheAddressableColumnsOnly(): void
    {
        $row = $this->repository->findNotificationRecipient(3);

        $this->assertSame(['id' => '3', 'username' => 'carol', 'fullname' => 'Carol', 'email' => 'carol@example.test'], array_map('strval', $row));
        $this->assertNull($this->repository->findNotificationRecipient(99));
    }

    public function testActiveUsersByEmailReturnsEveryMatchOrderedByUsername(): void
    {
        $rows = $this->repository->findActiveUsersByEmail('shared@example.test');

        $this->assertSame(['aaron', 'alice'], array_column($rows, 'username'));
        $this->assertSame([], $this->repository->findActiveUsersByEmail('carol@example.test'));
    }
}
