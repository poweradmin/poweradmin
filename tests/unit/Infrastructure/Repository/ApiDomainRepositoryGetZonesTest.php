<?php

namespace unit\Infrastructure\Repository;

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
}
