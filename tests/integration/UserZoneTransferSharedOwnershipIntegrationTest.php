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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * Transferring a deleted user's zones to a user who already co-owns one of them must leave
 * the receiver with one ownership row per zone, in SQL mode and in API mode (canonical row
 * plus extra rows with no zone_name). MySQL and PostgreSQL run when the devcontainer is up.
 */
class UserZoneTransferSharedOwnershipIntegrationTest extends TestCase
{
    private const FROM = 1;
    private const TO = 2;

    /** Per-process names, so parallel suite runs do not drop each other's scratch data. */
    private static function pgsqlSchema(): string
    {
        return 'poweradmin_it_transfer_' . getmypid();
    }

    private static function mysqlDb(): string
    {
        return 'poweradmin_it_transfer_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('DROP DATABASE IF EXISTS ' . self::mysqlDb());
                $db->exec('CREATE DATABASE ' . self::mysqlDb());
                $db->exec('USE ' . self::mysqlDb());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::pgsqlSchema() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::pgsqlSchema());
                $db->exec('SET search_path TO ' . self::pgsqlSchema());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $db->exec("CREATE TABLE zones (id INT PRIMARY KEY, domain_id INT NULL, owner INT NULL, zone_name VARCHAR(255) NULL)");

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::mysqlDb());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::pgsqlSchema() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /** @return array<int, list<int>> owners per canonical zone id, sorted */
    private function ownersByZone(PDO $db, bool $api): array
    {
        $zone = $api ? 'COALESCE(NULLIF(domain_id, 0), id)' : 'domain_id';
        $owners = [];
        foreach ($db->query("SELECT $zone AS zone_id, owner FROM zones ORDER BY owner")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owners[(int)$row['zone_id']][] = (int)$row['owner'];
        }
        ksort($owners);

        return $owners;
    }

    #[DataProvider('engines')]
    public function testSqlModeSharedZoneKeepsOneRowForTheReceiver(string $engine): void
    {
        $db = $this->connect($engine);
        // Zone 10 is shared, 11 belongs to the sender only, 12 to the receiver only
        $db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 10, 1), (2, 10, 2), (3, 11, 1), (4, 12, 2)");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), false);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame([10 => [2], 11 => [2], 12 => [2]], $this->ownersByZone($db, false));
        $this->assertSame([10, 11, 12], $repository->getDirectlyOwnedZoneIds(self::TO));
        $this->assertSame([], $repository->getDirectlyOwnedZoneIds(self::FROM));
    }

    #[DataProvider('engines')]
    public function testSqlModeKeepsTheZoneNameTheSenderRowCarried(string $engine): void
    {
        $db = $this->connect($engine);
        // The sender created zone 10 (named row), the receiver was added later; zone 12 is named on the receiver's row
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (1, 10, 1, 'a.example'), (2, 10, 2, NULL), (3, 12, 2, 'c.example'), (4, 12, 1, NULL)");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), false);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame([10 => [2], 12 => [2]], $this->ownersByZone($db, false));
        $this->assertSame(
            ['a.example', 'c.example'],
            $db->query("SELECT zone_name FROM zones WHERE zone_name IS NOT NULL ORDER BY zone_name")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    #[DataProvider('engines')]
    public function testApiModeSenderExtraRowOnReceiverCanonicalRowIsDropped(string $engine): void
    {
        $db = $this->connect($engine);
        // Zone 10: canonical row owned by the receiver, extra row for the sender. Zone 11: sender canonical only.
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (10, 10, 2, 'a.example'), (11, 11, 1, 'b.example')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (20, 10, 1, NULL)");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), true);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame([10 => [2], 11 => [2]], $this->ownersByZone($db, true));
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM zones WHERE zone_name IS NOT NULL")->fetchColumn());
    }

    #[DataProvider('engines')]
    public function testApiModeSenderExtraRowOnReceiverExtraRowIsDropped(string $engine): void
    {
        $db = $this->connect($engine);
        // Zone 10 is owned by a third user on its canonical row; sender and receiver both hold extra rows
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (10, 10, 3, 'a.example')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (20, 10, 1, NULL), (21, 10, 2, NULL), (22, 99, 1, NULL)");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), true);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame([10 => [2, 3], 99 => [2]], $this->ownersByZone($db, true));
    }

    #[DataProvider('engines')]
    public function testApiModeSenderCanonicalRowMovesOverTheReceiverExtraRow(string $engine): void
    {
        $db = $this->connect($engine);
        // The sender holds the canonical row of zone 10, the receiver an extra row for it; zone 11 is not shared
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (10, 10, 1, 'a.example'), (11, 11, 1, 'b.example')");
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (20, 10, 2, NULL)");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), true);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame([10 => [2], 11 => [2]], $this->ownersByZone($db, true));
        $this->assertSame(
            ['a.example', 'b.example'],
            $db->query("SELECT zone_name FROM zones WHERE owner = 2 AND zone_name IS NOT NULL ORDER BY zone_name")->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM zones WHERE zone_name IS NULL")->fetchColumn());
        $this->assertSame([10, 11], $repository->getDirectlyOwnedZoneIds(self::TO));
    }

    #[DataProvider('engines')]
    public function testCanonicalRowsAreNeverDeletedWhenIdsCollide(string $engine): void
    {
        $db = $this->connect($engine);
        // Two real zones whose canonical ids happen to match must both survive the transfer
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (5, 7, 1, 'a.example'), (7, 7, 2, 'b.example')");
        $repository = new DbUserRepository($db, new FakeConfiguration([]), true);

        $this->assertTrue($repository->transferUserZones(self::FROM, self::TO));

        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM zones WHERE zone_name IS NOT NULL")->fetchColumn());
    }
}
