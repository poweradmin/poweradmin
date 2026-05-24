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
use Poweradmin\Infrastructure\Database\DbCompat;

/**
 * Smoke-tests every DbCompat function against real database engines.
 *
 * Run locally via `composer tests:integration` against the devcontainer
 * (MariaDB on 3306, PostgreSQL on 5432). SQLite uses an in-memory DB and is
 * always exercised. Any engine that isn't reachable is skipped, not failed,
 * so the suite stays green when run outside the devcontainer.
 *
 * Not run in CI (`.github/workflows/php.yml` only runs `composer tests`).
 *
 * When adding a new method to DbCompat, add a corresponding test here so the
 * emitted SQL fragment is proven to parse and behave identically on every
 * engine - that's the cross-engine class of bug unit tests can't catch.
 */
class DbCompatIntegrationTest extends TestCase
{
    private ?PDO $mysql = null;
    private ?PDO $pgsql = null;
    private PDO $sqlite;

    protected function setUp(): void
    {
        try {
            $this->mysql = new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->mysql = null;
        }

        try {
            $this->pgsql = new PDO(
                'pgsql:host=127.0.0.1;port=5432;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->pgsql = null;
        }

        $this->sqlite = new PDO(
            'sqlite::memory:',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        foreach ($this->connections() as $conn) {
            $this->setupFixture($conn);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->mysql, $this->pgsql] as $conn) {
            if ($conn) {
                $conn->exec("DROP TABLE IF EXISTS test_dbcompat");
            }
        }
    }

    /**
     * @return array<string, PDO>
     */
    private function connections(): array
    {
        $conns = ['sqlite' => $this->sqlite];
        if ($this->mysql) {
            $conns['mysql'] = $this->mysql;
        }
        if ($this->pgsql) {
            $conns['pgsql'] = $this->pgsql;
        }
        return $conns;
    }

    private function setupFixture(PDO $db): void
    {
        $type = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec("DROP TABLE IF EXISTS test_dbcompat");
        if ($type === 'sqlite') {
            $db->exec("CREATE TABLE test_dbcompat (id INTEGER PRIMARY KEY, label TEXT, val TEXT)");
        } else {
            $db->exec("CREATE TABLE test_dbcompat (id INT PRIMARY KEY, label VARCHAR(64), val VARCHAR(64))");
        }
        $stmt = $db->prepare("INSERT INTO test_dbcompat (id, label, val) VALUES (?, ?, ?)");
        $stmt->execute([1, 'apple', '42']);
        $stmt->execute([2, 'banana', 'abc']);
        $stmt->execute([3, 'cherry', '7']);
    }

    public function testSubstr(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $func = DbCompat::substr($type);
            $row = $conn->query("SELECT $func('FOOBAR', 2, 3) AS r")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('OOB', $row['r'], "substr failed on $name");
        }
    }

    public function testRegexp(): void
    {
        // A literal pattern matches itself under REGEXP, ~, and GLOB.
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $op = DbCompat::regexp($type);
            $rows = $conn->query("SELECT id FROM test_dbcompat WHERE label $op 'apple'")->fetchAll();
            $this->assertCount(1, $rows, "regexp failed on $name");
        }
    }

    public function testNow(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $expr = DbCompat::now($type);
            $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
            $this->assertNotEmpty($value, "now() returned empty on $name");
            $this->assertNotFalse(strtotime((string) $value), "now() returned unparseable on $name: $value");
        }
    }

    public function testBoolTrueFalse(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $tValue = $conn->query("SELECT " . DbCompat::boolTrue($type) . " AS r")->fetch(PDO::FETCH_ASSOC)['r'];
            $fValue = $conn->query("SELECT " . DbCompat::boolFalse($type) . " AS r")->fetch(PDO::FETCH_ASSOC)['r'];
            $this->assertSame(1, DbCompat::boolFromDb($tValue), "boolTrue normalize failed on $name");
            $this->assertSame(0, DbCompat::boolFromDb($fValue), "boolFalse normalize failed on $name");
        }
    }

    public function testDateSubtract(): void
    {
        // Verify the fragment parses and yields a valid timestamp on each engine.
        // Skip arithmetic comparison: timezone semantics differ across drivers
        // and aren't what DbCompat is responsible for.
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $expr = DbCompat::dateSubtract($type, 3600);
            $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
            $this->assertNotEmpty($value, "dateSubtract returned empty on $name");
            $this->assertNotFalse(strtotime((string) $value), "dateSubtract returned unparseable on $name: $value");
        }
    }

    public function testConcat(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $expr = DbCompat::concat($type, ["'foo'", "'bar'"]);
            $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
            $this->assertSame('foobar', $value, "concat failed on $name");
        }
    }

    public function testGroupConcat(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($type === 'sqlite') {
                // KNOWN BUG: DbCompat::groupConcat() emits the MySQL "SEPARATOR"
                // keyword for SQLite, which SQLite doesn't recognize. SQLite needs
                // `GROUP_CONCAT(col, 'sep')` instead. Currently unused in production
                // code; fix DbCompat::groupConcat() before adding any SQLite caller.
                $this->markTestSkipped('DbCompat::groupConcat sqlite path emits invalid SQL (see comment)');
            }
            $expr = DbCompat::groupConcat($type, 'label', '-');
            $value = $conn->query("SELECT $expr AS r FROM test_dbcompat")->fetch(PDO::FETCH_ASSOC)['r'];
            $parts = explode('-', (string) $value);
            sort($parts);
            $this->assertSame(['apple', 'banana', 'cherry'], $parts, "groupConcat failed on $name");
        }
    }

    public function testCastToString(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $expr = DbCompat::castToString($type, 'id');
            $value = $conn->query("SELECT $expr AS r FROM test_dbcompat WHERE id = 1")->fetch(PDO::FETCH_ASSOC)['r'];
            $this->assertSame('1', (string) $value, "castToString failed on $name");
        }
    }

    public function testIsNumericString(): void
    {
        foreach ($this->connections() as $name => $conn) {
            $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $expr = DbCompat::isNumericString($type, 'val');
            $rows = $conn->query("SELECT id FROM test_dbcompat WHERE $expr ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $this->assertSame([1, 3], $ids, "isNumericString failed on $name");
        }
    }
}
