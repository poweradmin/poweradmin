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
use PDOException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * The last-owner rule decided and applied in one transaction, on real SQL.
 *
 * Two removals that each read the owner list, decide, then delete used to both
 * pass and leave the zone unowned. The guard now locks the zone's ownership rows
 * first, so the second removal re-reads the list the first one left behind.
 *
 * SQLite runs in memory and is always exercised. PostgreSQL runs in a throwaway
 * schema when the devcontainer is reachable, which is where the FOR UPDATE clause
 * is actually taken; MySQL is skipped because the devcontainer's `pdns` user may
 * not create a database and this test will not write into the shared one.
 */
class ZoneOwnershipGuardTransactionIntegrationTest extends TestCase
{
    private const ZONE_ID = 42;
    private const SCHEMA = 'pa_ownership_guard_test';

    private PDO $sqlite;
    private ?PDO $pgsql = null;

    protected function setUp(): void
    {
        $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $this->pgsql = $this->connectPgsql();
        } catch (PDOException) {
            $this->pgsql = null;
        }

        foreach ($this->connections() as $db) {
            $this->createFixture($db);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pgsql !== null) {
            $this->pgsql->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        }
    }

    public function testTheSecondUserOwnerRemovalIsRefused(): void
    {
        foreach ($this->connections() as $engine => $db) {
            $this->seedOwners($db, [5, 6]);
            $guard = $this->guard($db, $engine, 'both');

            $this->assertTrue($guard->removeUserOwner(self::ZONE_ID, 5), $engine);
            $second = $guard->removeUserOwner(self::ZONE_ID, 6);

            $this->assertInstanceOf(ZoneOwnershipRefusal::class, $second, $engine);
            $this->assertSame(ZoneOwnershipRefusal::LAST_OWNER, $second->code, $engine);
            $this->assertSame([6], $this->ownerIds($db), $engine);
            $this->assertFalse($db->inTransaction(), $engine);
        }
    }

    public function testTheSecondGroupRemovalIsRefused(): void
    {
        foreach ($this->connections() as $engine => $db) {
            $this->seedGroups($db, [3, 4]);
            $guard = $this->guard($db, $engine, 'both');

            $this->assertTrue($guard->removeGroup(self::ZONE_ID, 3), $engine);
            $second = $guard->removeGroup(self::ZONE_ID, 4);

            $this->assertInstanceOf(ZoneOwnershipRefusal::class, $second, $engine);
            $this->assertSame([4], $this->groupIds($db), $engine);
            $this->assertFalse($db->inTransaction(), $engine);
        }
    }

    /**
     * The half a single-process test can prove about concurrency: while one
     * removal holds the lock, a second connection cannot read the owner rows.
     */
    public function testASecondConnectionCannotReadTheOwnerRowsWhileARemovalHoldsThem(): void
    {
        if ($this->pgsql === null) {
            $this->markTestSkipped('PostgreSQL is not reachable');
        }

        $this->seedOwners($this->pgsql, [5, 6]);

        $other = $this->connectPgsql();
        $other->exec("SET search_path TO " . self::SCHEMA);
        $other->exec("SET lock_timeout = '300ms'");

        $config = new FakeConfiguration(['database' => ['type' => 'pgsql']]);
        $this->pgsql->beginTransaction();
        (new DbZoneRepository($this->pgsql, $config))->lockZoneOwners(self::ZONE_ID);

        $other->beginTransaction();
        try {
            (new DbZoneRepository($other, $config))->lockZoneOwners(self::ZONE_ID);
            $this->fail('The second connection read the locked owner rows');
        } catch (PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('lock timeout', $e->getMessage());
        } finally {
            $other->rollBack();
            $this->pgsql->rollBack();
        }
    }

    private function guard(PDO $db, string $engine, string $mode): ZoneOwnershipGuard
    {
        $config = new FakeConfiguration([
            'database' => ['type' => $engine],
            'dns' => ['zone_ownership_mode' => $mode],
        ]);

        return new ZoneOwnershipGuard(
            new DbZoneRepository($db, $config),
            new DbZoneGroupRepository($db, $config),
            new ZoneOwnershipModeService($config),
            new PdoTransaction($db)
        );
    }

    /**
     * @return array<string, PDO>
     */
    private function connections(): array
    {
        $conns = ['sqlite' => $this->sqlite];
        if ($this->pgsql !== null) {
            $conns['pgsql'] = $this->pgsql;
        }

        return $conns;
    }

    private function connectPgsql(): PDO
    {
        $db = new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=pdns',
            'pdns',
            'poweradmin',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $db->exec('CREATE SCHEMA IF NOT EXISTS ' . self::SCHEMA);
        $db->exec('SET search_path TO ' . self::SCHEMA);

        return $db;
    }

    private function createFixture(PDO $db): void
    {
        $serial = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SERIAL PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $db->exec('DROP TABLE IF EXISTS zones');
        $db->exec('DROP TABLE IF EXISTS zones_groups');
        $db->exec('DROP TABLE IF EXISTS users');
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(64), fullname VARCHAR(64))");
        $db->exec("CREATE TABLE zones (id $serial, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER DEFAULT 0)");
        $db->exec("CREATE TABLE zones_groups (id $serial, domain_id INTEGER, group_id INTEGER, created_at TIMESTAMP)");
    }

    /**
     * @param list<int> $userIds
     */
    private function seedOwners(PDO $db, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $db->exec("INSERT INTO users (id, username, fullname) VALUES ($userId, 'user$userId', 'User $userId')");
            $db->exec("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . self::ZONE_ID . ", $userId, 0)");
        }
    }

    /**
     * @param list<int> $groupIds
     */
    private function seedGroups(PDO $db, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            $db->exec("INSERT INTO zones_groups (domain_id, group_id, created_at) VALUES (" . self::ZONE_ID . ", $groupId, CURRENT_TIMESTAMP)");
        }
    }

    /**
     * @return list<int>
     */
    private function ownerIds(PDO $db): array
    {
        $rows = $db->query('SELECT owner FROM zones WHERE domain_id = ' . self::ZONE_ID . ' ORDER BY owner')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }

    /**
     * @return list<int>
     */
    private function groupIds(PDO $db): array
    {
        $rows = $db->query('SELECT group_id FROM zones_groups WHERE domain_id = ' . self::ZONE_ID . ' ORDER BY group_id')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }
}
