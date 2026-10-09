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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;

/**
 * A log line longer than the varchar(2048) event column used to abort the request that
 * produced it. Runs the real loggers against scratch tables with the shipped column
 * definitions, on MySQL in strict mode and on PostgreSQL; an engine that is not reachable
 * is skipped.
 */
class LogEventColumnLengthIntegrationTest extends TestCase
{
    private const MYSQL_DB = 'poweradmin_it';
    private const PGSQL_SCHEMA = 'poweradmin_it';

    private mixed $savedConfigInstance = null;

    protected function setUp(): void
    {
        // DbZoneLogger initializes the ConfigurationManager singleton; keep that out of other tests.
        $property = new \ReflectionProperty(ConfigurationManager::class, 'instance');
        $this->savedConfigInstance = $property->getValue();
        $property->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ConfigurationManager::class, 'instance'))->setValue(null, $this->savedConfigInstance);
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql strict' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::MYSQL_DB);
                $db->exec('USE ' . self::MYSQL_DB);
                $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
                $id = 'id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY';
                $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
                $created = 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
            } else {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::PGSQL_SCHEMA . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::PGSQL_SCHEMA);
                $db->exec('SET search_path TO ' . self::PGSQL_SCHEMA);
                $id = 'id SERIAL PRIMARY KEY';
                $tail = '';
                $created = 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        foreach (['log_zones', 'log_users'] as $table) {
            $db->exec("DROP TABLE IF EXISTS $table");
        }
        $zoneColumn = ', zone_id INT DEFAULT NULL';
        $db->exec("CREATE TABLE log_users ($id, event VARCHAR(2048) NOT NULL, $created, priority INT NOT NULL) $tail");
        $db->exec("CREATE TABLE log_zones ($id, event VARCHAR(2048) NOT NULL, $created, priority INT NOT NULL$zoneColumn) $tail");

        return $db;
    }

    private function longMessage(): string
    {
        return 'operation:add_record content:"' . str_repeat('x', 5000) . '"';
    }

    #[DataProvider('engines')]
    public function testUnfittedLongMessageIsRejectedByTheDatabase(string $engine): void
    {
        $db = $this->connect($engine);

        $this->expectException(PDOException::class);
        $stmt = $db->prepare('INSERT INTO log_zones (zone_id, event, priority) VALUES (1, :msg, 6)');
        $stmt->execute([':msg' => $this->longMessage()]);
    }

    #[DataProvider('engines')]
    public function testZoneLoggerStoresLongMessageTruncated(string $engine): void
    {
        $db = $this->connect($engine);

        (new DbZoneLogger($db))->doLog($this->longMessage(), 1, LOG_INFO);

        $stored = (string) $db->query('SELECT event FROM log_zones')->fetchColumn();
        $this->assertSame(2048, mb_strlen($stored));
        $this->assertStringStartsWith('operation:add_record ', $stored);
    }

    #[DataProvider('engines')]
    public function testUserLoggerStoresLongMessageTruncated(string $engine): void
    {
        $db = $this->connect($engine);

        (new DbUserLogger($db))->doLog(str_repeat("\u{20AC}", 5000), LOG_INFO);

        $stored = (string) $db->query('SELECT event FROM log_users')->fetchColumn();
        $this->assertSame(2048, mb_strlen($stored));
    }
}
