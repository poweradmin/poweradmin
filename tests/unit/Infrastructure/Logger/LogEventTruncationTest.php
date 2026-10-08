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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Logger\LogEventColumn;

class LogEventTruncationTest extends TestCase
{
    private const LIMIT = 2048;

    /** @return array<string, array{string, callable(PDO, string, ConfigurationInterface): void}> */
    public static function loggers(): array
    {
        return [
            'log_zones' => ['log_zones', fn(PDO $db, string $m, ConfigurationInterface $c) => (new DbZoneLogger($db, $c))->doLog($m, 1, LOG_INFO)],
            'log_users' => ['log_users', fn(PDO $db, string $m, ConfigurationInterface $c) => (new DbUserLogger($db))->doLog($m, LOG_INFO)],
            'log_groups' => ['log_groups', fn(PDO $db, string $m, ConfigurationInterface $c) => (new DbGroupLogger($db))->doLog($m, 1, LOG_INFO)],
            'log_api' => ['log_api', fn(PDO $db, string $m, ConfigurationInterface $c) => (new DbApiLogger($db))->doLog($m, LOG_INFO)],
        ];
    }

    private function dbWith(string $table): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, priority INTEGER NOT NULL, zone_id INTEGER, group_id INTEGER)");

        return $db;
    }

    private function stored(PDO $db, string $table): string
    {
        return (string) $db->query("SELECT event FROM $table")->fetchColumn();
    }

    #[Test]
    #[DataProvider('loggers')]
    public function longMessageIsStoredTruncatedToTheColumnLength(string $table, callable $log): void
    {
        $db = $this->dbWith($table);
        $message = 'operation:add_record ' . str_repeat('a', 5000);

        $log($db, $message, $this->createStub(ConfigurationInterface::class));

        $stored = $this->stored($db, $table);
        $this->assertSame(self::LIMIT, mb_strlen($stored));
        $this->assertStringStartsWith('operation:add_record ', $stored);
        $this->assertStringEndsWith('...', $stored);
    }

    #[Test]
    #[DataProvider('loggers')]
    public function messageWithinTheLimitIsStoredUnchanged(string $table, callable $log): void
    {
        $db = $this->dbWith($table);
        $message = str_repeat('b', self::LIMIT);

        $log($db, $message, $this->createStub(ConfigurationInterface::class));

        $this->assertSame($message, $this->stored($db, $table));
    }

    #[Test]
    public function truncationCountsCharactersAndNeverSplitsAMultibyteCharacter(): void
    {
        $fitted = LogEventColumn::fit(str_repeat("\u{20AC}", 5000));

        $this->assertSame(self::LIMIT, mb_strlen($fitted));
        $this->assertTrue(mb_check_encoding($fitted, 'UTF-8'));
    }
}
