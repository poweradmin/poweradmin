<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;

#[CoversClass(ApiDomainRepository::class)]
class ApiDomainRepositoryGetZonesTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER,
            owner INTEGER,
            comment TEXT,
            zone_templ_id INTEGER,
            zone_name TEXT,
            zone_type TEXT,
            zone_master TEXT,
            is_disabled INTEGER NOT NULL DEFAULT 0,
            is_missing_soa INTEGER NOT NULL DEFAULT 0,
            last_synced_at INTEGER
        )");
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            fullname TEXT
        )");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Administrator')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (1, 100, 1, '', 0, 'signed.example.com', 'NATIVE'),
            (2, 101, 1, '', 0, 'unsigned.example.com', 'NATIVE')");

        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();
    }

    #[Test]
    public function getZonesExposesNotifyStateForNotifyingZones(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (3, 102, 1, '', 0, 'pending.example.com', 'MASTER'),
            (4, 103, 1, '', 0, 'sent.example.com', 'MASTER'),
            (5, 104, 1, '', 0, 'nativezone.example.com', 'NATIVE'),
            (6, 105, 1, '', 0, 'fresh.example.com', 'MASTER')");

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 102, 'name' => 'pending.example.com',    'type' => 'MASTER', 'master' => '', 'dnssec' => false],
            ['id' => 103, 'name' => 'sent.example.com',       'type' => 'MASTER', 'master' => '', 'dnssec' => false],
            ['id' => 104, 'name' => 'nativezone.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 105, 'name' => 'fresh.example.com',      'type' => 'MASTER', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([
            'pending.example.com.'    => ['rrset_count' => 1, 'dnssec' => false, 'serial' => 5, 'edited_serial' => null, 'notified_serial' => 4],
            'sent.example.com.'       => ['rrset_count' => 1, 'dnssec' => false, 'serial' => 7, 'edited_serial' => null, 'notified_serial' => 7],
            'nativezone.example.com.' => ['rrset_count' => 1, 'dnssec' => false, 'serial' => 9, 'edited_serial' => null, 'notified_serial' => 0],
            // Never-published zone: serial 0 must not surface a NOTIFY indicator
            'fresh.example.com.'      => ['rrset_count' => 1, 'dnssec' => false, 'serial' => 0, 'edited_serial' => null, 'notified_serial' => 0],
        ]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC', false, true);

        // Primary with serial ahead of notified_serial -> pending
        $this->assertTrue($result['pending.example.com']['notify_pending']);
        $this->assertSame(4, $result['pending.example.com']['notified_serial']);

        // Primary with serial == notified_serial -> sent
        $this->assertFalse($result['sent.example.com']['notify_pending']);
        $this->assertSame(7, $result['sent.example.com']['notified_serial']);

        // Native zones never notify
        $this->assertArrayNotHasKey('notify_pending', $result['nativezone.example.com']);
        $this->assertArrayNotHasKey('notified_serial', $result['nativezone.example.com']);

        // Serial 0 (no published SOA) -> no indicator even for a Primary
        $this->assertArrayNotHasKey('notify_pending', $result['fresh.example.com']);
        $this->assertArrayNotHasKey('notified_serial', $result['fresh.example.com']);
    }

    #[Test]
    public function getZonesOmitsNotifyStateWhenServerLacksNotifiedSerial(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type) VALUES
            (7, 106, 1, '', 0, 'legacy.example.com', 'MASTER')");

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 106, 'name' => 'legacy.example.com', 'type' => 'MASTER', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        // Older PowerDNS without the notified_serial field -> null, indicator hidden
        $backend->method('getZoneStats')->willReturn([
            'legacy.example.com.' => ['rrset_count' => 1, 'dnssec' => false, 'serial' => 5, 'edited_serial' => null, 'notified_serial' => null],
        ]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC', false, true);

        $this->assertArrayNotHasKey('notify_pending', $result['legacy.example.com']);
        $this->assertArrayNotHasKey('notified_serial', $result['legacy.example.com']);
    }

    #[Test]
    public function getZonesMapsDnssecFlagToSecuredField(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'signed.example.com',   'type' => 'NATIVE', 'master' => '', 'dnssec' => true],
            ['id' => 101, 'name' => 'unsigned.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC');

        $this->assertArrayHasKey('signed.example.com', $result);
        $this->assertArrayHasKey('unsigned.example.com', $result);
        $this->assertTrue($result['signed.example.com']['secured']);
        $this->assertFalse($result['unsigned.example.com']['secured']);
    }

    #[Test]
    public function getZonesAcceptsSecuredKeyForBackwardCompatibility(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'signed.example.com', 'type' => 'NATIVE', 'master' => '', 'secured' => true],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC');

        $this->assertTrue($result['signed.example.com']['secured']);
    }

    #[Test]
    public function getZonesReadsSoaHealthPerVisibleZoneInApiMode(): void
    {
        // API mode has no cache; getZones must call getZoneSoaHealth for each
        // visible zone after pagination.
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'signed.example.com',   'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 101, 'name' => 'unsigned.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([]);
        $backend->method('getZoneSoaHealth')->willReturnCallback(fn(string $name) => match ($name) {
            'signed.example.com' => ['is_disabled' => true, 'is_missing_soa' => false],
            'unsigned.example.com' => ['is_disabled' => false, 'is_missing_soa' => true],
            default => ['is_disabled' => false, 'is_missing_soa' => false],
        });

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC');

        $this->assertTrue($result['signed.example.com']['is_disabled']);
        $this->assertFalse($result['signed.example.com']['is_missing_soa']);
        $this->assertFalse($result['unsigned.example.com']['is_disabled']);
        $this->assertTrue($result['unsigned.example.com']['is_missing_soa']);
    }

    #[Test]
    public function getZonesMapsSignedSerialWhenSettingEnabled(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null) => ($group === 'interface' && $key === 'display_signed_serial_in_zone_list') ? true : $default
        );

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'signed.example.com',   'type' => 'NATIVE', 'master' => '', 'dnssec' => true],
            ['id' => 101, 'name' => 'unsigned.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([
            'signed.example.com.' => ['rrset_count' => 5, 'dnssec' => true, 'serial' => 2024010101, 'edited_serial' => 2024010199],
            'unsigned.example.com.' => ['rrset_count' => 3, 'dnssec' => false, 'serial' => 2024010101, 'edited_serial' => 2024010101],
        ]);

        $repo = new ApiDomainRepository($this->db, $config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC');

        $this->assertSame('2024010199', $result['signed.example.com']['signed_serial']);
        // Unsigned zones serve the plain serial; the signed-serial column stays blank for them
        $this->assertSame('', $result['unsigned.example.com']['signed_serial']);
        // Unsigned-serial column stays off unless its own setting is enabled
        $this->assertArrayNotHasKey('serial', $result['signed.example.com']);
    }

    #[Test]
    public function getZonesOmitsSignedSerialWhenSettingDisabled(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'signed.example.com', 'type' => 'NATIVE', 'master' => '', 'dnssec' => true],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('countZoneRecords')->willReturn(0);
        $backend->method('getZoneStats')->willReturn([]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 100, 'name', 'ASC');

        $this->assertArrayNotHasKey('signed_serial', $result['signed.example.com']);
    }
}
