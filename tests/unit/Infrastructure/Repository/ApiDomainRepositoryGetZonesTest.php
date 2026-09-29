<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Constants;
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
    public function getZonesCountsRecordsOnlyForTheVisiblePage(): void
    {
        $this->addZones([102 => 'c.example.com', 103 => 'd.example.com', 104 => 'e.example.com'], 1);
        $counted = [];
        $backend = $this->backendCountingInto($counted, [
            100 => 'signed.example.com', 101 => 'unsigned.example.com',
            102 => 'c.example.com', 103 => 'd.example.com', 104 => 'e.example.com',
        ]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('all', 0, 'all', 0, 2, 'name', 'ASC');

        $this->assertSame(['c.example.com', 'd.example.com'], array_keys($result));
        $this->assertSame([102, 103], $counted);
        $this->assertSame(1020, $result['c.example.com']['count_records']);
    }

    #[Test]
    public function getZonesSortedByRecordCountCountsOnlyTheUsersZones(): void
    {
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (2, 'owner', 'Owner')");
        $this->addZones([102 => 'c.example.com', 103 => 'd.example.com'], 2);
        $counted = [];
        $backend = $this->backendCountingInto($counted, [
            100 => 'signed.example.com', 101 => 'unsigned.example.com',
            102 => 'c.example.com', 103 => 'd.example.com',
        ]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('own', 2, 'all', 0, 1, 'count_records', 'DESC');

        $this->assertSame(['d.example.com'], array_keys($result));
        $this->assertSame([102, 103], $counted);
    }

    #[Test]
    public function getZonesWithoutPaginationCountsOnlyTheFilteredZones(): void
    {
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (2, 'owner', 'Owner')");
        $this->addZones([102 => 'c.example.com', 103 => 'd.example.com', 104 => 'cx.example.com'], 2);
        $counted = [];
        $backend = $this->backendCountingInto($counted, [
            100 => 'signed.example.com', 101 => 'unsigned.example.com',
            102 => 'c.example.com', 103 => 'd.example.com', 104 => 'cx.example.com',
        ]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZones('own', 2, 'c', 0, Constants::DEFAULT_MAX_ROWS, 'name', 'ASC');

        $this->assertSame(['c.example.com', 'cx.example.com'], array_keys($result));
        $this->assertSame([102, 104], $counted);
        $this->assertSame(1040, $result['cx.example.com']['count_records']);
    }

    /**
     * @param array<int, string> $zones domain id => zone name
     */
    private function addZones(array $zones, int $owner): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, comment, zone_templ_id, zone_name, zone_type)
            VALUES (?, ?, '', 0, ?, 'NATIVE')");
        foreach ($zones as $id => $name) {
            $stmt->execute([$id, $owner, $name]);
        }
    }

    /**
     * @param int[] $counted receives the domain ids passed to countZoneRecords
     * @param array<int, string> $zones domain id => zone name
     */
    private function backendCountingInto(array &$counted, array $zones): DnsBackendProvider
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZones')->willReturn(array_map(
            fn(int $id, string $name) => ['id' => $id, 'name' => $name, 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            array_keys($zones),
            $zones
        ));
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZoneStats')->willReturn([]);
        $backend->method('countZoneRecords')->willReturnCallback(function (int $id) use (&$counted) {
            $counted[] = $id;
            return $id * 10;
        });

        return $backend;
    }
}
