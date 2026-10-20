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
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
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

    public function testAdoptionCountsDownTheUsersRemainingZones(): void
    {
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->expects($this->once())->method('userRemaining')->with(4)->willReturn(2);
        $lookup = new AccountOwnerLookup($this->db, $limits);

        $this->assertSame(4, $lookup->adopterFor('alice'));
        $this->assertSame(4, $lookup->adopterFor('alice'));
        $this->assertNull($lookup->adopterFor('alice'));
        $this->assertNull($lookup->adopterFor('alice'));
        $this->assertSame(2, $lookup->heldBack());
        $this->assertNull($lookup->adopterFor('nobody'));
        $this->assertSame(2, $lookup->heldBack());
    }

    public function testAnUnlimitedUserAlwaysAdopts(): void
    {
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->method('userRemaining')->willReturn(null);
        $lookup = new AccountOwnerLookup($this->db, $limits);

        foreach (range(1, 5) as $ignored) {
            $this->assertSame(4, $lookup->adopterFor('alice'));
        }
        $this->assertSame(0, $lookup->heldBack());
    }

    public function testWithoutLimitsAdoptionMatchesTheLookup(): void
    {
        $lookup = new AccountOwnerLookup($this->db);

        $this->assertSame(4, $lookup->adopterFor('alice'));
        $this->assertNull($lookup->adopterFor('bob'));
        $this->assertSame(0, $lookup->heldBack());
    }

    public function testTheConfiguredLookupRespectsTheDefaultLimitButNotForSuperusers(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-sqlite-db-structure.sql'));
        // Template 1 is the seeded Administrator (user_is_ueberuser), template 2 a plain one
        $db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES
            (1, 'root', '', '', '', '', 1, 1, 0), (7, 'carol', '', '', '', '', 2, 1, 0)");
        $db->exec('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (100, 7, 0)');

        $lookup = AccountOwnerLookup::forConfig($db, new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['adopt_zone_owner_from_account' => true, 'default_max_zones_per_user' => 2],
        ]));

        $this->assertNotNull($lookup);
        $this->assertSame(7, $lookup->adopterFor('carol'));
        $this->assertNull($lookup->adopterFor('carol'));
        foreach (range(1, 3) as $ignored) {
            $this->assertSame(1, $lookup->adopterFor('root'));
        }
        $this->assertSame(1, $lookup->heldBack());
    }

    public function testOnlyLimitedAdoptersAreLockedAndTheCountsStartAfresh(): void
    {
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->method('userLimit')->willReturnCallback(fn(int $id): ?int => $id === 4 ? 1 : null);
        $limits->expects($this->exactly(2))->method('userRemaining')->with(4)->willReturn(1);
        $limits->expects($this->once())->method('lockUsers')->with([4]);
        $lookup = new AccountOwnerLookup($this->db, $limits);

        $this->assertSame([4], $lookup->limitedAdopters(['alice', 'Admin', 'alice', 'nobody', '']));
        $lookup->lockAdopters([4]);
        $this->assertSame(4, $lookup->adopterFor('alice'));
        $this->assertNull($lookup->adopterFor('alice'));

        $this->assertSame(1, $lookup->heldBack());

        // A new run counts again rather than trusting the last one
        $lookup->limitedAdopters(['alice']);
        $this->assertSame(0, $lookup->heldBack());
        $this->assertSame(4, $lookup->adopterFor('alice'));
    }

    public function testWithoutLimitsNothingIsLocked(): void
    {
        $lookup = new AccountOwnerLookup($this->db);

        $this->assertSame([], $lookup->limitedAdopters(['alice']));
        $lookup->lockAdopters([]);
        $this->assertSame(4, $lookup->adopterFor('alice'));
    }

    public function testAssignWithinLimitWritesThroughTheLockedGrant(): void
    {
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->expects($this->once())->method('addUserOwner')->with(4)
            ->willReturnCallback(fn(int $userId, callable $write): mixed => $write());
        $lookup = new AccountOwnerLookup($this->db, $limits);

        $this->assertTrue($lookup->assignWithinLimit(4, fn(): bool => true));
        $this->assertSame(0, $lookup->heldBack());
    }

    public function testAssignWithinLimitHoldsBackAUserAtTheLimit(): void
    {
        $limits = $this->createMock(ZoneOwnershipLimit::class);
        $limits->method('addUserOwner')->willReturn(new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, 'alice', 1, 1));
        $lookup = new AccountOwnerLookup($this->db, $limits);
        $written = false;

        $this->assertNull($lookup->assignWithinLimit(4, function () use (&$written): bool {
            $written = true;
            return true;
        }));
        $this->assertFalse($written);
        $this->assertSame(1, $lookup->heldBack());
        $this->assertFalse((new AccountOwnerLookup($this->db))->assignWithinLimit(4, fn(): bool => false));
    }
}
