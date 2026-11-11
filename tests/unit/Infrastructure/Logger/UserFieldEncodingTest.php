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
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionClass;

/**
 * A username with a space or colon is stored encoded in the user: field, so the log filters
 * cannot mistake "admin evil" for "admin".
 */
class UserFieldEncodingTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->resetConfigurationManager([
            'database' => ['type' => 'sqlite'],
            'logging' => ['database_enabled' => true],
            'interface' => [], 'security' => [], 'dns' => [], 'mail' => [], 'dnssec' => [],
            'pdns_api' => [], 'ldap' => [], 'misc' => [],
        ]);
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->db->exec('CREATE TABLE log_users (id INTEGER PRIMARY KEY, event TEXT, priority INTEGER, created_at TEXT)');
        $this->db->exec('CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT, priority INTEGER, zone_id INTEGER)');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->resetConfigurationManager(null);
    }

    public function testPlainNamesAreUnchangedAndSpecialCharactersAreEncoded(): void
    {
        $this->assertSame('admin', LegacyLogger::userField('admin'));
        $this->assertSame('admin%20evil', LegacyLogger::userField('admin evil'));
        $this->assertSame('evil%20user%3Aadmin', LegacyLogger::userField('evil user:admin'));
    }

    public function testTheActorIsEncodedWhereTheLineWritesIt(): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = 'admin evil';

        $line = $this->encode('client_ip:10.0.0.1 user:admin evil operation:add_zone zone:user:admin evil x');

        $this->assertSame('client_ip:10.0.0.1 user:admin%20evil operation:add_zone zone:user:admin evil x', $line);
    }

    public function testAPlainActorLineIsByteIdentical(): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = 'admin';
        $line = 'client_ip:10.0.0.1 user:admin operation:add_zone zone:a.example';

        $this->assertSame($line, $this->encode($line));
    }

    public function testUserFilterForAdminDoesNotMatchAUserNamedAdminEvil(): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = 'admin evil';
        $evil = $this->encode('client_ip:1.1.1.1 user:admin evil operation:add_user');
        $colon = $this->encode('client_ip:1.1.1.1 user:admin evil operation:x');
        $insert = $this->db->prepare('INSERT INTO log_users (event, priority) VALUES (:e, 6)');
        foreach ([$evil, $colon, 'client_ip:1.1.1.1 user:admin operation:add_user', 'client_ip:1.1.1.1 user:evil%20user%3Aadmin operation:x'] as $event) {
            $insert->execute([':e' => $event]);
        }

        $logger = new DbUserLogger($this->db);

        $this->assertSame(1, (int)$logger->countLogsByUser('admin'));
        $this->assertSame(2, (int)$logger->countLogsByUser('admin evil'));
    }

    public function testOwnerDropdownRebuildsTheEncodedName(): void
    {
        $this->db->exec("INSERT INTO users (username) VALUES ('admin'), ('admin evil'), ('a:b c')");
        $this->db->exec("INSERT INTO log_zones (event, priority, zone_id) VALUES
            ('client_ip:1.1.1.1 user:admin%20evil operation:edit_record', 6, 5),
            ('client_ip:1.1.1.1 user:a%3Ab%20c operation:edit_record', 6, 5)");

        $users = (new DbZoneLogger($this->db))->getDistinctUsersForZones([5]);

        $this->assertSame(['a:b c', 'admin evil'], $users);
    }

    public function testOwnerDropdownDoesNotTreatTheEncodedPercentAsAWildcard(): void
    {
        $this->db->exec("INSERT INTO users (username) VALUES ('admin evil')");
        $this->db->exec("INSERT INTO log_zones (event, priority, zone_id) VALUES
            ('client_ip:1.1.1.1 user:admin operation:add_record record:20evil x', 6, 5)");

        $this->assertSame([], (new DbZoneLogger($this->db))->getDistinctUsersForZones([5]));
    }

    public function testAnEmptyClientIpStillEncodesTheActor(): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = 'admin evil';

        $this->assertSame('client_ip: user:admin%20evil operation:x', $this->encode('client_ip: user:admin evil operation:x'));
    }

    private function encode(string $line): string
    {
        $logger = (new ReflectionClass(LegacyLogger::class))->newInstanceWithoutConstructor();

        return (new ReflectionClass(LegacyLogger::class))->getMethod('encodeActor')->invoke($logger, $line);
    }

    /**
     * @param array<string, array<string, mixed>>|null $settings
     */
    private function resetConfigurationManager(?array $settings): void
    {
        $class = new ReflectionClass(ConfigurationManager::class);
        $class->getProperty('instance')->setValue(null, null);

        if ($settings === null) {
            return;
        }

        $manager = ConfigurationManager::getInstance();
        $class->getProperty('settings')->setValue($manager, $settings);
        $class->getProperty('initialized')->setValue($manager, true);
    }
}
