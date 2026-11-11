<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionClass;

/**
 * A username with a space or colon must not be read as another field of the log line.
 */
class AuditUserFieldEncodingTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->resetConfigurationManager([
            'database' => ['type' => 'sqlite'],
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

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->db->exec('CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT, priority INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationManager(null);
    }

    public function testEncodeUserLeavesPlainNamesUntouched(): void
    {
        $this->assertSame('admin.user-1@example.com', LegacyLogger::encodeUser('admin.user-1@example.com'));
        $this->assertSame('', LegacyLogger::encodeUser(null));
    }

    public function testEncodeUserEncodesSpaceAndColon(): void
    {
        $this->assertSame('evil%20user%3Aadmin', LegacyLogger::encodeUser('evil user:admin'));
    }

    public function testFilterForPlainNameStillMatchesOldRow(): void
    {
        $this->addEvent(1, 'client_ip:10.0.0.1 user:admin operation:edit_record');

        $logs = (new DbZoneLogger($this->db))->getFilteredLogs(['user' => 'admin'], 50, 0);

        $this->assertCount(1, $logs);
    }

    public function testFilterForAdminDoesNotMatchEncodedNamesContainingIt(): void
    {
        $this->addEvent(1, 'client_ip:10.0.0.1 user:' . LegacyLogger::encodeUser('admin evil') . ' operation:edit_record');
        $this->addEvent(1, 'client_ip:10.0.0.1 user:' . LegacyLogger::encodeUser('evil user:admin') . ' operation:edit_record');

        $logger = new DbZoneLogger($this->db);

        $this->assertCount(0, $logger->getFilteredLogs(['user' => 'admin'], 50, 0));
        $this->assertCount(1, $logger->getFilteredLogs(['user' => 'admin evil'], 50, 0));
    }

    public function testFilterTreatsWildcardsInTheNameAsLiterals(): void
    {
        $this->addEvent(1, 'client_ip:10.0.0.1 user:admin operation:edit_record');

        $this->assertCount(0, (new DbZoneLogger($this->db))->getFilteredLogs(['user' => 'ad_in'], 50, 0));
    }

    public function testOwnerDropdownListsOnlyRealAuthors(): void
    {
        $this->db->exec("INSERT INTO users (id, username) VALUES (1, 'admin'), (2, 'admin evil'), (3, 'bob')");
        $this->addEvent(1, 'client_ip:10.0.0.1 user:' . LegacyLogger::encodeUser('admin evil') . ' operation:edit_record');
        $this->addEvent(1, 'client_ip:10.0.0.1 user:bob operation:edit_record');

        $users = (new DbZoneLogger($this->db))->getDistinctUsersForZones([1]);

        $this->assertSame(['admin evil', 'bob'], $users);
    }

    private function addEvent(int $zoneId, string $event): void
    {
        $stmt = $this->db->prepare('INSERT INTO log_zones (zone_id, event, priority) VALUES (:z, :e, 6)');
        $stmt->execute([':z' => $zoneId, ':e' => $event]);
    }

    /**
     * @param array<string, array<string, mixed>>|null $settings
     */
    private function resetConfigurationManager(?array $settings): void
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $reflectionClass->getProperty('instance')->setValue(null, null);

        if ($settings === null) {
            return;
        }

        $configManager = ConfigurationManager::getInstance();
        $reflectionClass->getProperty('settings')->setValue($configManager, $settings);
        $reflectionClass->getProperty('initialized')->setValue($configManager, true);
    }
}
