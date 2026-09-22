<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * Zones come from the backend, ownership from the local zones tables, and the
 * letter/type filters work on the names.
 */
class ApiZoneRepositoryCountZonesTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (1, NULL, 'alpha.example', 5),
            (2, NULL, 'beta.example', 9),
            (3, NULL, '1.168.192.in-addr.arpa', 9),
            (4, NULL, '42.example', 5)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, 7)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (5, 7)");
    }

    private function createRepository(): ApiZoneRepository
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('getZones')->with(false)->willReturn([
            ['id' => 1, 'name' => 'alpha.example.'],
            ['id' => 2, 'name' => 'beta.example'],
            ['id' => 3, 'name' => '1.168.192.in-addr.arpa'],
            ['id' => 4, 'name' => '42.example'],
        ]);
        $config = new FakeConfiguration();

        return new ApiZoneRepository($this->db, $backend, 'sqlite', $config);
    }

    public function testCountsForwardZonesForAll(): void
    {
        $this->assertSame(3, $this->createRepository()->countZones('all', null));
    }

    public function testCountsReverseAndAllTypes(): void
    {
        $repository = $this->createRepository();

        $this->assertSame(1, $repository->countZones('all', null, 'all', 'reverse'));
        $this->assertSame(4, $repository->countZones('all', null, 'all', 'all'));
    }

    public function testLetterAndNumericFilters(): void
    {
        $repository = $this->createRepository();

        $this->assertSame(1, $repository->countZones('all', null, 'A'));
        $this->assertSame(1, $repository->countZones('all', null, '1'));
        $this->assertSame(0, $repository->countZones('all', null, 'z'));
    }

    public function testOwnCountsDirectAndGroupOwnership(): void
    {
        $this->assertSame(3, $this->createRepository()->countZones('own', 5));
    }

    public function testOwnWithoutUserAndUnknownPermCountNothing(): void
    {
        $repository = $this->createRepository();

        $this->assertSame(0, $repository->countZones('own', null));
        $this->assertSame(0, $repository->countZones('invalid', 5));
    }
}
