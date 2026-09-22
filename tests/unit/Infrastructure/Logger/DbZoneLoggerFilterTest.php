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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use TestHelpers\FakeConfiguration;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The zone-log listing is restricted to the zones the viewer may see: a null
 * zone list means no restriction (admin), an empty one means no access, and a
 * populated one limits the rows. The name filter resolves the zone name
 * through whichever table the backend owns.
 */
#[CoversClass(DbZoneLogger::class)]
class DbZoneLoggerFilterTest extends SqliteIntegrationTestCase
{
    private const ALPHA = 10;
    private const BETA = 20;
    private const GAMMA = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT NOT NULL, created_at TEXT, zone_id INTEGER)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");

        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (20, 'mia', 1), (30, 'zed', 1)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (" . self::ALPHA . ", 'alpha.example.com', 'MASTER'),
            (" . self::BETA . ", 'beta.example.com', 'MASTER'),
            (" . self::GAMMA . ", 'gamma.example.com', 'MASTER')");
        $this->db->exec("INSERT INTO log_zones (event, created_at, zone_id) VALUES
            ('user:admin operation:add_record zone:alpha.example.com ', '2026-01-10 09:00:00', " . self::ALPHA . "),
            ('user:mia operation:edit_record zone:beta.example.com ', '2026-02-11 09:00:00', " . self::BETA . "),
            ('user:zed operation:delete_record zone:gamma.example.com ', '2026-03-12 09:00:00', " . self::GAMMA . ")");
    }

    private function logger(?ConfigurationInterface $config = null, ?BackendCapabilitiesInterface $backend = null): DbZoneLogger
    {
        return new DbZoneLogger($this->db, $config ?? $this->loggingConfiguration(), $backend);
    }

    private function loggingConfiguration(): ConfigurationInterface
    {
        return $this->sqliteConfiguration(['logging' => ['database_enabled' => true]]);
    }

    /**
     * @param int[]|null $zoneIds
     * @return list<int>
     */
    private function loggedZoneIds(array $filters, ?array $zoneIds): array
    {
        $logs = $this->logger()->getFilteredLogs($filters, 50, 0, $zoneIds);
        $stmt = $this->db->query("SELECT id, zone_id FROM log_zones");
        $zoneById = [];
        foreach ($stmt->fetchAll() as $row) {
            $zoneById[(int)$row['id']] = (int)$row['zone_id'];
        }

        return array_map(fn(array $log): int => $zoneById[(int)$log['id']], $logs);
    }

    #[Test]
    public function anEmptyZoneListMeansNoAccessAndNeverQueries(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $logger = new DbZoneLogger($db, $this->loggingConfiguration());

        $this->assertSame(0, $logger->countFilteredLogs([], []));
        $this->assertSame([], $logger->getFilteredLogs([], 50, 0, []));
        $this->assertSame([], $logger->getDistinctUsersForZones([]));
    }

    #[Test]
    public function aNullZoneListLeavesTheListingUnrestricted(): void
    {
        $this->assertSame(3, $this->logger()->countFilteredLogs([], null));
        $this->assertSame(
            [self::GAMMA, self::BETA, self::ALPHA],
            $this->loggedZoneIds([], null)
        );
    }

    #[Test]
    public function aZoneListRestrictsTheListingToThoseZones(): void
    {
        $this->assertSame(2, $this->logger()->countFilteredLogs([], [self::ALPHA, self::BETA]));
        $this->assertSame(
            [self::BETA, self::ALPHA],
            $this->loggedZoneIds([], [self::ALPHA, self::BETA])
        );
    }

    #[Test]
    public function theZoneFilterCombinesWithTheNameAndDateFilters(): void
    {
        $filters = ['name' => 'beta.example.com', 'date_from' => '2026-01-01'];

        $this->assertSame(1, $this->logger()->countFilteredLogs($filters, [self::BETA]));
        $this->assertSame(0, $this->logger()->countFilteredLogs($filters, [self::ALPHA]));
        $this->assertSame(
            0,
            $this->logger()->countFilteredLogs(['name' => 'beta.example.com', 'date_from' => '2026-03-01'], null)
        );
    }

    #[Test]
    public function theOperationAndUserFiltersMatchTheStoredEvent(): void
    {
        $this->assertSame(1, $this->logger()->countFilteredLogs(['operation' => 'edit_record'], null));
        $this->assertSame(1, $this->logger()->countFilteredLogs(['user' => 'zed'], null));
        $this->assertSame(0, $this->logger()->countFilteredLogs(['user' => 'nobody'], null));
    }

    #[Test]
    public function theListingPagesAndRendersTheEventDetails(): void
    {
        $logs = $this->logger()->getFilteredLogs([], 1, 1, null);

        $this->assertCount(1, $logs);
        $this->assertSame(
            'user: mia<br>operation: edit_record<br>zone: beta.example.com<br>',
            $logs[0]['details']
        );
    }

    #[Test]
    public function distinctUsersAreTakenFromTheEventsOfTheGivenZones(): void
    {
        $this->assertSame(['mia', 'zed'], $this->logger()->getDistinctUsersForZones([self::BETA, self::GAMMA]));
        $this->assertSame(['mia'], $this->logger()->getDistinctUsersForZones([self::BETA]));
    }

    #[Test]
    public function theDistinctUserQueryUsesTheDriversConcatenation(): void
    {
        foreach (['mysql' => "CONCAT('%user:', u.username, ' %')", 'pgsql' => "CONCAT('%user:', u.username, ' %')", 'sqlite' => "'%user:' || u.username || ' %'"] as $driver => $pattern) {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn([]);
            $prepared = null;
            $db = $this->createMock(PDO::class);
            $db->method('prepare')->willReturnCallback(function (string $sql) use (&$prepared, $statement) {
                $prepared = $sql;
                return $statement;
            });

            $logger = new DbZoneLogger($db, $this->sqliteConfiguration([
                'logging' => ['database_enabled' => true],
                'database' => ['type' => $driver],
            ]));

            $this->assertSame([], $logger->getDistinctUsersForZones([self::BETA]));
            $this->assertStringContainsString($pattern, (string)$prepared, $driver);
        }
    }

    #[Test]
    public function theNameFilterResolvesTheZoneNameThroughZonesOnTheApiBackend(): void
    {
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name TEXT)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (" . self::BETA . ", 0, 1, 'beta.example.com')");

        $backend = $this->createMock(BackendCapabilitiesInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);

        $this->assertSame(
            1,
            $this->logger(null, $backend)->countFilteredLogs(['name' => 'beta.example.com'], null)
        );
        $this->assertSame(
            0,
            $this->logger(null, $backend)->countFilteredLogs(['name' => 'alpha.example.com'], null)
        );
    }

    /**
     * A separate PowerDNS database prefixes the domains table with a schema
     * name that SQLite has no equivalent for, so this branch is pinned by the
     * SQL the logger prepares.
     */
    #[Test]
    public function theNameFilterJoinsThePrefixedDomainsTableOnTheSqlBackend(): void
    {
        $capturedSql = '';
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(['number_of_logs' => 0]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($statement, &$capturedSql): PDOStatement {
            $capturedSql = $sql;
            return $statement;
        });

        $config = new FakeConfiguration([
            'database' => ['type' => 'mysql', 'pdns_db_name' => 'pdns'],
            'logging' => ['database_enabled' => true],
        ]);

        (new DbZoneLogger($db, $config))->countFilteredLogs(['name' => 'example.com'], null);

        $this->assertStringContainsString('INNER JOIN pdns.domains ON pdns.domains.id = log_zones.zone_id', $capturedSql);
        $this->assertStringContainsString('pdns.domains.name LIKE :search_by', $capturedSql);
    }
}
