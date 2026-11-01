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
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;

/**
 * The records a signed parent keeps at a delegation point (NS, DS, NSEC, RRSIG)
 * do not block creating the child zone, on every engine (#1840).
 * MySQL and PostgreSQL run when the devcontainer is up.
 */
class DelegationPointRecordsCrossEngineIntegrationTest extends TestCase
{
    private static function scratchName(): string
    {
        return 'poweradmin_it_delegation_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function repository(string $engine): SqlRecordRepository
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::scratchName());
                $db->exec('USE ' . self::scratchName());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratchName());
                $db->exec('SET search_path TO ' . self::scratchName());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec('DROP TABLE IF EXISTS records');
        $db->exec("CREATE TABLE records ($id, domain_id INT, name VARCHAR(255), type VARCHAR(10), content VARCHAR(255))");
        $db->exec("INSERT INTO records (domain_id, name, type, content) VALUES
            (1, 'signed.example.com', 'NS', 'ns1.other.net'),
            (1, 'signed.example.com', 'DS', '1234 13 2 abcd'),
            (1, 'signed.example.com', 'NSEC', 'www.example.com. NS DS RRSIG NSEC'),
            (1, 'signed.example.com', 'RRSIG', 'DS 13 3 3600 20261201000000 20261101000000 1234 example.com. abcd'),
            (1, 'taken.example.com', 'NS', 'ns1.other.net'),
            (1, 'taken.example.com', 'RRSIG', 'A 13 3 3600 20261201000000 20261101000000 1234 example.com. abcd'),
            (1, 'taken.example.com', 'A', '192.0.2.1')");

        return new SqlRecordRepository($db, new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]));
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratchName());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    #[DataProvider('engines')]
    public function testASignedDelegationDoesNotBlockTheChildZone(string $engine): void
    {
        $this->assertFalse($this->repository($engine)->hasNonDelegationRecords('signed.example.com'));
    }

    #[DataProvider('engines')]
    public function testAnAddressAtTheNameStillBlocksIt(string $engine): void
    {
        $this->assertTrue($this->repository($engine)->hasNonDelegationRecords('taken.example.com'));
    }
}
