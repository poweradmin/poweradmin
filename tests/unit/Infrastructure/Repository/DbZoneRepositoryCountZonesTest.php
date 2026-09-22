<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Zone counts over the PowerDNS domains table joined with the local ownership tables.
 */
class DbZoneRepositoryCountZonesTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // DbCompat maps the numeric filter to REGEXP, which sqlite only has when registered
        $this->db->sqliteCreateFunction('regexp', fn($pattern, $value) => preg_match('/' . $pattern . '/', (string)$value) === 1);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (1, 'alpha.example', 'MASTER'),
            (2, 'beta.example', 'MASTER'),
            (3, '1.168.192.in-addr.arpa', 'MASTER'),
            (4, '42.example', 'MASTER'),
            (5, '_dmarc.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 1, 5), (2, 2, 9), (3, 3, 9), (4, 4, 5), (5, 5, 9)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, 7)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (5, 7)");
    }

    private function createRepository(string $prefix = ''): DbZoneRepository
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) use ($prefix) {
            if ($group === 'database' && $key === 'type') {
                return 'sqlite';
            }
            if ($group === 'database' && $key === 'pdns_db_name') {
                return $prefix;
            }
            return $default;
        });

        return new DbZoneRepository($this->db, $config);
    }

    public function testCountsForwardZonesForAll(): void
    {
        $this->assertSame(4, $this->createRepository()->countZones('all', null));
    }

    public function testCountsReverseAndAllTypes(): void
    {
        $repository = $this->createRepository();

        $this->assertSame(1, $repository->countZones('all', null, 'all', 'reverse'));
        $this->assertSame(5, $repository->countZones('all', null, 'all', 'all'));
    }

    public function testLetterAndNumericFilters(): void
    {
        $repository = $this->createRepository();

        $this->assertSame(1, $repository->countZones('all', null, 'a'));
        $this->assertSame(1, $repository->countZones('all', null, '1'));
        // An underscore filter matches literally rather than as a LIKE wildcard
        $this->assertSame(1, $repository->countZones('all', null, '_'));
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

    public function testPrefixedDomainsTableIsUsed(): void
    {
        $this->db->exec("ATTACH DATABASE ':memory:' AS pdns");
        $this->db->exec("CREATE TABLE pdns.domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("INSERT INTO pdns.domains (id, name, type) VALUES (1, 'only.example', 'MASTER')");

        $this->assertSame(1, $this->createRepository('pdns')->countZones('all', null));
    }
}
