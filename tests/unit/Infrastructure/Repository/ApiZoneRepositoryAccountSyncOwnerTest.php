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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use ReflectionMethod;

/**
 * The username pushed to the PowerDNS account field is the oldest owner of one zone.
 * Selecting the group by canonical id alone let an unrelated row win: the two id
 * spaces overlap (see CanonicalZoneSql), so a native zone's primary key can equal a
 * migrated zone's domain_id, and the lower id wins the ORDER BY.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryAccountSyncOwnerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES
            (1, 'migrated_owner', 'Migrated'), (2, 'native_owner', 'Native'), (3, 'co_owner', 'Co Owner')");

        // Mixed install. The migrated zone is identified by domain_id 42 while its own
        // primary key is 50; the native zone's primary key is that same 42.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (50, 42, 'migrated.example.com', 'MASTER', '', '', 1, 0),
            (42, NULL, 'native.example.com', 'MASTER', '', '', 2, 0)");
    }

    private function oldestOwner(int $canonicalRowId, int $canonicalId): ?string
    {
        $repository = new ApiZoneRepository(
            $this->db,
            $this->createMock(DnsBackendProvider::class),
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );

        $method = new ReflectionMethod($repository, 'getOldestOwnerUsername');
        $method->setAccessible(true);

        return $method->invoke($repository, $canonicalRowId, $canonicalId);
    }

    #[Test]
    public function theMigratedZoneReportsItsOwnOwnerNotTheCollidingNativeZone(): void
    {
        // Without the primary-key anchor the native row (id 42) sorts first and
        // native_owner is pushed as the migrated zone's account.
        $this->assertSame('migrated_owner', $this->oldestOwner(50, 42));
    }

    #[Test]
    public function theNativeZoneReportsItsOwnOwner(): void
    {
        $this->assertSame('native_owner', $this->oldestOwner(42, 42));
    }

    #[Test]
    public function anExtraOwnerOlderThanTheCanonicalRowStillWins(): void
    {
        // Extra owners are keyed by canonical id with a NULL zone_name; the oldest of
        // the group is the one that gets synced.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id)
            VALUES (7, 42, NULL, NULL, NULL, NULL, 3, 0)");

        $this->assertSame('co_owner', $this->oldestOwner(50, 42));
    }

    #[Test]
    public function aZoneWithNoOwnerRowYieldsNull(): void
    {
        $this->db->exec("DELETE FROM zones");

        $this->assertNull($this->oldestOwner(50, 42));
    }
}
