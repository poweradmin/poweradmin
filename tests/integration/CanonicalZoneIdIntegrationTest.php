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
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * CanonicalZoneSql::canonicalIdColumn() leans on NULLIF to fold a stranded domain_id of 0
 * into the fallback. NULLIF and COALESCE are SQL-92, but whether the fragment parses in
 * every clause and returns the same integer on each engine is the cross-engine class of
 * bug unit tests cannot catch.
 *
 * Run locally via `composer tests:integration` against the devcontainer. Engines that are
 * unreachable are skipped, not failed.
 */
class CanonicalZoneIdIntegrationTest extends TestCase
{
    private ?PDO $mysql = null;
    private ?PDO $pgsql = null;
    private PDO $sqlite;

    protected function setUp(): void
    {
        try {
            $this->mysql = new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=poweradmin',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->mysql = null;
        }

        try {
            $this->pgsql = new PDO(
                'pgsql:host=127.0.0.1;port=5432;dbname=poweradmin',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->pgsql = null;
        }

        $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach ($this->engines() as $db) {
            $this->createFixture($db);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->engines() as $db) {
            $db->exec('DROP TABLE IF EXISTS test_canonical_zones');
            $db->exec('DROP TABLE IF EXISTS test_canonical_groups');
        }
    }

    /**
     * @return array<string, PDO>
     */
    private function engines(): array
    {
        $engines = ['sqlite' => $this->sqlite];
        if ($this->mysql !== null) {
            $engines['mysql'] = $this->mysql;
        }
        if ($this->pgsql !== null) {
            $engines['pgsql'] = $this->pgsql;
        }

        return $engines;
    }

    private function createFixture(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS test_canonical_zones');
        $db->exec('DROP TABLE IF EXISTS test_canonical_groups');
        $db->exec('CREATE TABLE test_canonical_zones (id INTEGER NOT NULL, domain_id INTEGER NULL, zone_name VARCHAR(255) NULL)');
        $db->exec('CREATE TABLE test_canonical_groups (domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)');

        $stmt = $db->prepare('INSERT INTO test_canonical_zones (id, domain_id, zone_name) VALUES (:id, :did, :name)');
        foreach ([[1, null, 'null.example.com'], [2, 0, 'zero.example.com'], [3, 99, 'migrated.example.com'], [4, 4, 'self.example.com']] as [$id, $did, $name]) {
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':did', $did, $did === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->execute();
        }
        $db->exec('INSERT INTO test_canonical_groups (domain_id, group_id) VALUES (2, 7)');
    }

    public function testEveryEngineResolvesTheSameCanonicalIds(): void
    {
        $expression = CanonicalZoneSql::canonicalIdColumn();
        $expected = [1 => 1, 2 => 2, 3 => 99, 4 => 4];

        foreach ($this->engines() as $name => $db) {
            $rows = $db->query("SELECT id, $expression AS canonical_id FROM test_canonical_zones ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
            $actual = [];
            foreach ($rows as $row) {
                $actual[(int)$row['id']] = (int)$row['canonical_id'];
            }

            $this->assertSame($expected, $actual, "canonical ids differ on $name");
        }
    }

    public function testBareCoalesceDisagreesWithTheHelperOnEveryEngine(): void
    {
        foreach ($this->engines() as $name => $db) {
            $bare = (int)$db->query('SELECT COALESCE(domain_id, id) FROM test_canonical_zones WHERE id = 2')->fetchColumn();
            $helper = (int)$db->query(
                'SELECT ' . CanonicalZoneSql::canonicalIdColumn() . ' FROM test_canonical_zones WHERE id = 2'
            )->fetchColumn();

            $this->assertSame(0, $bare, "bare COALESCE unexpectedly resolved on $name");
            $this->assertSame(2, $helper, "helper failed to resolve on $name");
        }
    }

    public function testTheFragmentParsesInEveryClauseOnEveryEngine(): void
    {
        $aliased = CanonicalZoneSql::canonicalIdColumn('z');
        $qualified = CanonicalZoneSql::canonicalIdColumn('test_canonical_zones');

        foreach ($this->engines() as $name => $db) {
            $where = $db->query("SELECT zone_name FROM test_canonical_zones z WHERE $aliased = 2")->fetchColumn();
            $this->assertSame('zero.example.com', $where, "WHERE clause failed on $name");

            $distinct = $db->query("SELECT DISTINCT $aliased AS cid FROM test_canonical_zones z ORDER BY cid")
                ->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame([1, 2, 4, 99], array_map('intval', $distinct), "SELECT DISTINCT failed on $name");

            $join = $db->query(
                "SELECT z.zone_name FROM test_canonical_zones z
                 INNER JOIN test_canonical_groups g ON g.domain_id = $aliased"
            )->fetchColumn();
            $this->assertSame('zero.example.com', $join, "JOIN failed on $name");

            $tableQualified = $db->query(
                "SELECT zone_name FROM test_canonical_zones WHERE $qualified = 99"
            )->fetchColumn();
            $this->assertSame('migrated.example.com', $tableQualified, "table-qualified form failed on $name");
        }
    }
}
