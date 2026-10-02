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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;
use TestHelpers\FakeConfiguration;

class AccountOwnerLookupTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // NOCASE stands in for the case-insensitive MySQL collation
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT COLLATE NOCASE)');
        $this->db->exec("INSERT INTO users (id, username) VALUES (4, 'alice'), (5, 'Admin')");
    }

    public function testOnlyTheExactUsernameMatches(): void
    {
        $lookup = new AccountOwnerLookup($this->db);

        $this->assertSame(4, $lookup->userIdFor('alice'));
        $this->assertSame(4, $lookup->userIdFor(' alice '));
        $this->assertNull($lookup->userIdFor('ALICE'));
        $this->assertNull($lookup->userIdFor('admin'));
        $this->assertSame(5, $lookup->userIdFor('Admin'));
        $this->assertNull($lookup->userIdFor(''));
        $this->assertNull($lookup->userIdFor('nobody'));
    }

    public function testAnAccountIsLookedUpOnce(): void
    {
        $lookup = new AccountOwnerLookup($this->db);
        $this->assertSame(4, $lookup->userIdFor('alice'));
        $this->db->exec('DELETE FROM users');

        $this->assertSame(4, $lookup->userIdFor('alice'));
    }

    public function testTheSettingDecidesWhetherThereIsALookup(): void
    {
        $this->assertNull(AccountOwnerLookup::forConfig($this->db, new FakeConfiguration()));
        $this->assertInstanceOf(AccountOwnerLookup::class, AccountOwnerLookup::forConfig($this->db, new FakeConfiguration(['dns' => ['adopt_zone_owner_from_account' => true]])));
    }
}
