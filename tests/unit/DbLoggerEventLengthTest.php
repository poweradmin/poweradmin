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

namespace unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;

class DbLoggerEventLengthTest extends TestCase
{
    private const LIMIT = 2048;

    public static function loggers(): array
    {
        return [
            'log_zones' => ['log_zones', fn(PDOLayer $db, string $m) => (new DbZoneLogger($db))->do_log($m, 1, LOG_INFO)],
            'log_users' => ['log_users', fn(PDOLayer $db, string $m) => (new DbUserLogger($db))->do_log($m, LOG_INFO)],
        ];
    }

    private function dbWith(string $table): PDOLayer
    {
        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, priority INTEGER NOT NULL, zone_id INTEGER)");

        return $db;
    }

    private function stored(PDOLayer $db, string $table): string
    {
        return (string)$db->query("SELECT event FROM $table")->fetchColumn();
    }

    #[DataProvider('loggers')]
    public function testLongMessageIsStoredTruncatedToTheColumnLength(string $table, callable $log): void
    {
        $db = $this->dbWith($table);
        $message = 'operation:add_record ' . str_repeat('a', 5000);

        $log($db, $message);

        $stored = $this->stored($db, $table);
        $this->assertSame(self::LIMIT, mb_strlen($stored));
        $this->assertStringStartsWith('operation:add_record ', $stored);
        $this->assertStringEndsWith('...', $stored);
    }

    #[DataProvider('loggers')]
    public function testMessageWithinTheLimitIsStoredUnchanged(string $table, callable $log): void
    {
        $db = $this->dbWith($table);
        $message = str_repeat('b', self::LIMIT);

        $log($db, $message);

        $this->assertSame($message, $this->stored($db, $table));
    }

    #[DataProvider('loggers')]
    public function testTruncationNeverSplitsAMultibyteCharacter(string $table, callable $log): void
    {
        $db = $this->dbWith($table);

        $log($db, str_repeat("\u{20AC}", 5000));

        $stored = $this->stored($db, $table);
        $this->assertSame(self::LIMIT, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }
}
