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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\Consistency\ApiConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * API backend mode. b.example (row 12) carries domain_id 5 and c.example (row 5) carries
 * domain_id 3, so id 5 opens b.example and, once its row is gone, c.example by row id.
 * Keys, grants and extra owners on 5 were meant for b.example and must not reach c.example.
 */
#[CoversClass(SharedZoneIds::class)]
#[CoversClass(DbUserRepository::class)]
#[CoversClass(ApiConsistencyChecks::class)]
#[CoversClass(ApiZoneRepository::class)]
class SharedZoneIdRemovalPathsTest extends TestCase
{
    private const B_OWNER = 7;
    private const C_OWNER = 8;

    private PDO $db;

    protected function setUp(): void
    {
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
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, api_key_id INTEGER NOT NULL, zone_id INTEGER NOT NULL, UNIQUE (api_key_id, zone_id))",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE app_settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')",
                "INSERT INTO users VALUES (7, 'bowner', 1), (8, 'cowner', 1)",
                "INSERT INTO zones (id, domain_id, owner, zone_name, zone_type) VALUES
                    (12, 5, 7, 'b.example', 'MASTER'), (5, 3, 8, 'c.example', 'SLAVE'), (30, 30, 7, 'd.example', 'MASTER'),
                    (40, 5, 9, NULL, NULL)",
                "INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 1), (3, 2)",
                // Key 1 is restricted to b.example, key 2 to c.example, key 3 to d.example
                "INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (1, 5), (2, 3), (3, 30)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    /** @return array<int, int[]> */
    private function keyScopes(): array
    {
        $scopes = [];
        foreach ($this->db->query("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY api_key_id, zone_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(int)$row['api_key_id']][] = (int)$row['zone_id'];
        }

        return $scopes;
    }

    /** @return int[] */
    private function idsOf(string $sql): array
    {
        return array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function users(): DbUserRepository
    {
        return new DbUserRepository($this->db, new FakeConfiguration(['database' => ['type' => 'sqlite']]), true);
    }

    private function consistencyChecks(): ApiConsistencyChecks
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('getZoneNameById')->willReturn('b.example');
        $backend->method('deleteZone')->willReturn(true);

        return new ApiConsistencyChecks(
            $this->db,
            $backend,
            new ApiStatusService(new ArraySession()),
            new ZoneOwnerRepair($this->db),
            new DefaultSoaBuilder($this->createStub(ConfigurationInterface::class))
        );
    }

    public function testDeletingAUserWhoseZoneAnIdOpensDropsTheKeysGrantsAndExtraOwnersOnIt(): void
    {
        $this->assertTrue($this->users()->deleteUser(self::B_OWNER));

        // c.example now opens on id 5; nothing meant for b.example may reach it
        $this->assertSame([5], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    public function testDeletingAUserWhoseZoneIdOpensNothingElseKeepsTheKeysAndGrants(): void
    {
        $this->assertTrue($this->users()->deleteUser(self::C_OWNER));

        $this->assertSame([12, 30, 40], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
        $this->assertSame([3, 5], $this->idsOf("SELECT domain_id FROM zones_groups ORDER BY domain_id"));
    }

    public function testTheConsistencyCheckDeletingTheZoneAnIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $this->assertTrue($this->consistencyChecks()->deleteSlaveZone(5));

        $this->assertSame([5, 30], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    public function testUndoingAFailedCreationOfTheZoneAnIdOpensLeavesItsApiKeysMatchingNoZone(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $zones = new ApiZoneRepository($this->db, $backend, 'sqlite', new FakeConfiguration(), new ArraySession());

        $zones->deleteZoneShell(5);

        $this->assertSame([5, 30], $this->idsOf("SELECT id FROM zones ORDER BY id"));
        $this->assertSame([1 => [0], 2 => [3], 3 => [30]], $this->keyScopes());
    }

    public function testTheConsistencyCheckDeletingAZoneWhoseIdOpensNothingElseKeepsItsApiKeys(): void
    {
        $this->assertTrue($this->consistencyChecks()->deleteSlaveZone(3));

        $this->assertSame([1 => [5], 2 => [3], 3 => [30]], $this->keyScopes());
    }
}
