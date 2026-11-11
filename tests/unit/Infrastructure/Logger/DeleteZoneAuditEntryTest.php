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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionClass;

/**
 * The delete entry is written after the zone is gone. It has to land in log_zones
 * (Zone Logs page, not log_users) and then lose its zone id, which SQLite reuses.
 */
class DeleteZoneAuditEntryTest extends TestCase
{
    private array $settingsBackup = [];
    private bool $initializedBackup = false;

    protected function setUp(): void
    {
        $property = (new ReflectionClass(ConfigurationManager::class))->getProperty('settings');
        $initialized = (new ReflectionClass(ConfigurationManager::class))->getProperty('initialized');
        $config = ConfigurationManager::getInstance();
        $this->settingsBackup = $property->getValue($config);
        $this->initializedBackup = $initialized->getValue($config);
        $property->setValue($config, ['logging' => ['database_enabled' => true, 'syslog_enabled' => false]]);
        $initialized->setValue($config, true);
    }

    protected function tearDown(): void
    {
        $config = ConfigurationManager::getInstance();
        (new ReflectionClass(ConfigurationManager::class))->getProperty('settings')->setValue($config, $this->settingsBackup);
        (new ReflectionClass(ConfigurationManager::class))->getProperty('initialized')->setValue($config, $this->initializedBackup);
    }

    public function testDeleteEntryStaysInTheZoneLogWithoutAZoneId(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT, priority INTEGER, zone_id INTEGER, created_at TEXT)');
        $db->exec('CREATE TABLE log_users (id INTEGER PRIMARY KEY, event TEXT, priority INTEGER, created_at TEXT)');

        (new LegacyLogger($db))->logInfo('operation:delete_zone zone:example.com', 9);
        SharedZoneIds::detachZoneHistory($db, 9);

        $this->assertSame('1', (string)$db->query("SELECT count(*) FROM log_zones WHERE zone_id IS NULL AND event LIKE '%delete_zone%'")->fetchColumn());
        $this->assertSame('0', (string)$db->query('SELECT count(*) FROM log_zones WHERE zone_id = 9')->fetchColumn());
        $this->assertSame('0', (string)$db->query('SELECT count(*) FROM log_users')->fetchColumn());
    }
}
