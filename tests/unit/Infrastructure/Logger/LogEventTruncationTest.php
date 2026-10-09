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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;

class LogEventTruncationTest extends TestCase
{
    private const LIMIT = 2048;

    protected function setUp(): void
    {
        $this->resetConfigurationManager([
            'database' => ['type' => 'mysql'],
            'logging' => ['database_enabled' => true],
            'interface' => [],
            'security' => [],
            'dns' => [],
            'mail' => [],
            'dnssec' => [],
            'pdns_api' => [],
            'ldap' => [],
            'misc' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationManager(null);
    }

    /**
     * @param array<string, array<string, mixed>>|null $settings
     */
    private function resetConfigurationManager(?array $settings): void
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);

        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        if ($settings === null) {
            return;
        }

        $configManager = ConfigurationManager::getInstance();

        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $settings);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }

    /** @return array<string, array{string, callable(PDO, string): void}> */
    public static function loggers(): array
    {
        return [
            'log_zones' => ['log_zones', fn(PDO $db, string $m) => (new DbZoneLogger($db))->doLog($m, 1, LOG_INFO)],
            'log_users' => ['log_users', fn(PDO $db, string $m) => (new DbUserLogger($db))->doLog($m, LOG_INFO)],
            'log_groups' => ['log_groups', fn(PDO $db, string $m) => (new DbGroupLogger($db))->doLog($m, 1, LOG_INFO)],
            'log_api' => ['log_api', fn(PDO $db, string $m) => (new DbApiLogger($db))->doLog($m, LOG_INFO)],
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

        $log($db, $message);

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

        $log($db, $message);

        $this->assertSame($message, $this->stored($db, $table));
    }

    #[Test]
    #[DataProvider('loggers')]
    public function truncationCountsCharactersAndNeverSplitsAMultibyteCharacter(string $table, callable $log): void
    {
        $db = $this->dbWith($table);

        $log($db, str_repeat("\u{20AC}", 5000));

        $stored = $this->stored($db, $table);
        $this->assertSame(self::LIMIT, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }
}
