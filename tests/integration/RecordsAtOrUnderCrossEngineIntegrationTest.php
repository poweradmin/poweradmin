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
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\FakeConfiguration;

/**
 * The subtree query behind the hidden parent-zone records warning on every engine:
 * the escaped LIKE, the case-insensitive match and the disabled column, which is a
 * boolean on PostgreSQL. MySQL and PostgreSQL run when the devcontainer is up.
 */
class RecordsAtOrUnderCrossEngineIntegrationTest extends TestCase
{
    /** Per-process names, so parallel suite runs do not drop each other's scratch data. */
    private static function pgsqlSchema(): string
    {
        return 'poweradmin_it_records_' . getmypid();
    }

    private static function mysqlDb(): string
    {
        return 'poweradmin_it_records_' . getmypid();
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
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::mysqlDb());
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

        // The disabled column as each engine's PowerDNS schema declares it
        [$id, $disabled, $off, $on] = match ($engine) {
            'mysql' => ['id INT AUTO_INCREMENT PRIMARY KEY', 'TINYINT(1) DEFAULT 0', '0', '1'],
            'pgsql' => ['id SERIAL PRIMARY KEY', "BOOL DEFAULT 'f'", "'f'", "'t'"],
            default => ['id INTEGER PRIMARY KEY', 'BOOLEAN DEFAULT 0', '0', '1'],
        };
        $db->exec('DROP TABLE IF EXISTS records');
        $db->exec("CREATE TABLE records ($id, domain_id INT, name VARCHAR(255), type VARCHAR(10),
            content VARCHAR(255), ttl INT, prio INT, disabled $disabled)");
        $db->exec("INSERT INTO records (domain_id, name, type, content, disabled) VALUES
            (1, 'sub.example.com', 'NS', 'ns1.sub.example.com', $off),
            (1, 'WWW.sub.example.com', 'A', '192.0.2.1', $off),
            (1, '_dmarc.sub.example.com', 'TXT', 'v=DMARC1', $off),
            (1, 'off.sub.example.com', 'A', '192.0.2.2', $on),
            (1, 'xsub.example.com', 'A', '192.0.2.3', $off),
            (1, 'www.axb.example.com', 'A', '192.0.2.4', $off),
            (1, 'ent.sub.example.com', NULL, NULL, $off),
            (2, 'www.sub.example.com', 'A', '192.0.2.5', $off)");

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

    private function repository(PDO $db, string $engine): SqlRecordRepository
    {
        return new SqlRecordRepository($db, new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]));
    }

    #[DataProvider('engines')]
    public function testTheSubtreeIsMatchedOnEveryEngine(string $engine): void
    {
        $rows = $this->repository($this->connect($engine), $engine)->getRecordsAtOrUnder(1, 'Sub.Example.com.');
        $found = array_map(fn(array $r): string => $r['name'] . ' ' . $r['type'], $rows);
        // The collation decides where '_' sorts (MySQL puts it after letters), so compare as a set
        sort($found);

        $this->assertSame(['WWW.sub.example.com A', '_dmarc.sub.example.com TXT', 'sub.example.com NS'], $found);
    }

    #[DataProvider('engines')]
    public function testAnUnderscoreInTheNameIsNotAWildcard(string $engine): void
    {
        $this->assertSame([], $this->repository($this->connect($engine), $engine)->getRecordsAtOrUnder(1, 'a_b.example.com'));
    }
}
