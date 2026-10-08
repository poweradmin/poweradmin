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
use Poweradmin\Domain\Database\DbCompat;

/**
 * Smoke-tests every DbCompat function against real database engines.
 *
 * Run locally via `composer tests:integration` against the devcontainer
 * (MariaDB on 3306, PostgreSQL on 5432). SQLite uses an in-memory DB and is
 * always exercised. Each engine is its own test case; one that isn't reachable
 * is reported as skipped, so the suite stays green outside the devcontainer.
 *
 * Not run in CI (`.github/workflows/php.yml` only runs `composer tests`).
 *
 * When adding a new method to DbCompat, add a corresponding test here so the
 * emitted SQL fragment is proven to parse and behave identically on every
 * engine - that's the cross-engine class of bug unit tests can't catch.
 */
class DbCompatIntegrationTest extends TestCase
{
    /** @var array<string, PDO> */
    private array $opened = [];

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    protected function tearDown(): void
    {
        foreach ($this->opened as $engine => $conn) {
            if ($engine !== 'sqlite') {
                $conn->exec("DROP TABLE IF EXISTS test_dbcompat");
            }
        }
        $this->opened = [];
    }

    private function useEngine(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            $conn = match ($engine) {
                'sqlite' => new PDO('sqlite::memory:', null, null, $options),
                'mysql' => new PDO('mysql:host=127.0.0.1;port=3306;dbname=pdns', 'pdns', 'poweradmin', $options),
                default => new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options),
            };
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $this->opened[$engine] = $conn;
        $this->setupFixture($conn);

        return $conn;
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

    #[DataProvider('engines')]
    public function testSubstr(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $func = DbCompat::substr($type);
        $row = $conn->query("SELECT $func('FOOBAR', 2, 3) AS r")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('OOB', $row['r'], "substr failed on $engine");
    }

    #[DataProvider('engines')]
    public function testRegexp(string $engine): void
    {
        // A literal pattern matches itself under REGEXP, ~, and GLOB.
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $op = DbCompat::regexp($type);
        $rows = $conn->query("SELECT id FROM test_dbcompat WHERE label $op 'apple'")->fetchAll();
        $this->assertCount(1, $rows, "regexp failed on $engine");
    }

    #[DataProvider('engines')]
    public function testNow(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::now($type);
        $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
        $this->assertNotEmpty($value, "now() returned empty on $engine");
        $this->assertNotFalse(strtotime((string) $value), "now() returned unparseable on $engine: $value");
    }

    #[DataProvider('engines')]
    public function testBoolTrueFalse(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tValue = $conn->query("SELECT " . DbCompat::boolTrue($type) . " AS r")->fetch(PDO::FETCH_ASSOC)['r'];
        $fValue = $conn->query("SELECT " . DbCompat::boolFalse($type) . " AS r")->fetch(PDO::FETCH_ASSOC)['r'];
        $this->assertSame(1, DbCompat::boolFromDb($tValue), "boolTrue normalize failed on $engine");
        $this->assertSame(0, DbCompat::boolFromDb($fValue), "boolFalse normalize failed on $engine");
    }

    #[DataProvider('engines')]
    public function testDateSubtract(string $engine): void
    {
        // Verify the fragment parses and yields a valid timestamp on each engine.
        // Skip arithmetic comparison: timezone semantics differ across drivers
        // and aren't what DbCompat is responsible for.
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::dateSubtract($type, 3600);
        $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
        $this->assertNotEmpty($value, "dateSubtract returned empty on $engine");
        $this->assertNotFalse(strtotime((string) $value), "dateSubtract returned unparseable on $engine: $value");
    }

    #[DataProvider('engines')]
    public function testConcat(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::concat($type, ["'foo'", "'bar'"]);
        $value = $conn->query("SELECT $expr AS r")->fetch(PDO::FETCH_ASSOC)['r'];
        $this->assertSame('foobar', $value, "concat failed on $engine");
    }

    #[DataProvider('engines')]
    public function testGroupConcat(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::groupConcat($type, 'label', '-');
        $value = $conn->query("SELECT $expr AS r FROM test_dbcompat")->fetch(PDO::FETCH_ASSOC)['r'];
        $parts = explode('-', (string) $value);
        sort($parts);
        $this->assertSame(['apple', 'banana', 'cherry'], $parts, "groupConcat failed on $engine");
    }

    #[DataProvider('engines')]
    public function testCastToString(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::castToString($type, 'id');
        $value = $conn->query("SELECT $expr AS r FROM test_dbcompat WHERE id = 1")->fetch(PDO::FETCH_ASSOC)['r'];
        $this->assertSame('1', (string) $value, "castToString failed on $engine");
    }

    #[DataProvider('engines')]
    public function testIsNumericString(string $engine): void
    {
        $conn = $this->useEngine($engine);
        $type = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expr = DbCompat::isNumericString($type, 'val');
        $rows = $conn->query("SELECT id FROM test_dbcompat WHERE $expr ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $this->assertSame([1, 3], $ids, "isNumericString failed on $engine");
    }

    /**
     * handleSqlMode must read and write the same scope. It previously read
     * @@GLOBAL.sql_mode while writing SESSION, so a session-only ONLY_FULL_GROUP_BY
     * was never cleared and the grouped queries in RecordSearch failed with error 1055.
     */
    public function testHandleSqlModeClearsOnlyFullGroupByFromTheSession(): void
    {
        $mysql = $this->useEngine('mysql');

        // Position matters: a substring replace only caught the comma-terminated form.
        $cases = [
            'only mode' => 'ONLY_FULL_GROUP_BY',
            'last in list' => 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY',
            'first in list' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
        ];

        foreach ($cases as $label => $mode) {
            $mysql->exec("SET SESSION sql_mode = '$mode'");

            $original = DbCompat::handleSqlMode($mysql, 'mysql');
            $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $original, "original not returned for: $label");

            $active = (string) $mysql->query("SELECT @@SESSION.sql_mode")->fetchColumn();
            $this->assertStringNotContainsString('ONLY_FULL_GROUP_BY', $active, "not cleared for: $label");

            DbCompat::restoreSqlMode($mysql, 'mysql', $original);
            $restored = (string) $mysql->query("SELECT @@SESSION.sql_mode")->fetchColumn();
            $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $restored, "not restored for: $label");
        }
    }

    public function testHandleSqlModeReadsTheSessionNotTheGlobalMode(): void
    {
        $mysql = $this->useEngine('mysql');

        $global = (string) $mysql->query("SELECT @@GLOBAL.sql_mode")->fetchColumn();
        if (str_contains($global, 'ONLY_FULL_GROUP_BY')) {
            $this->markTestSkipped('Global sql_mode already sets ONLY_FULL_GROUP_BY; cannot isolate the session');
        }

        // Global lacks the mode, the session has it: reading GLOBAL saw nothing to do.
        $mysql->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");
        $original = DbCompat::handleSqlMode($mysql, 'mysql');
        $this->assertNotSame('', $original, 'session-only ONLY_FULL_GROUP_BY went undetected');

        $rows = $mysql
            ->query("SELECT label, val FROM test_dbcompat GROUP BY label")
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows, 'ungrouped column still rejected after handleSqlMode');

        DbCompat::restoreSqlMode($mysql, 'mysql', $original);
    }
}
