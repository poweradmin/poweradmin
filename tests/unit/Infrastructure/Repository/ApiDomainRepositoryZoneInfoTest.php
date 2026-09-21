<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;

#[CoversClass(ApiDomainRepository::class)]
class ApiDomainRepositoryZoneInfoTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;
    private UserContextService $userContext;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER,
            owner INTEGER,
            zone_name TEXT,
            zone_type TEXT
        )");

        // Permission tables so PermissionService::hasPermission('zone_content_view_others') passes
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, perm_templ) VALUES (1, 1)");
        $this->db->exec("INSERT INTO perm_templ (id) VALUES (1)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, '" . Permission::PERM_ZONE_CONTENT_VIEW_OTHERS . "')");
        $this->db->exec("INSERT INTO perm_templ_items (id, templ_id, perm_id) VALUES (1, 1, 1)");

        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();

        $this->userContext = new UserContextService();
        $this->userContext->setSessionData(SessionKeys::USERID, 1);
    }

    protected function tearDown(): void
    {
        $this->userContext->unsetSessionData(SessionKeys::USERID);
    }

    #[Test]
    public function listZoneNamesReadsTheLiveListSortedWithoutTrailingDots(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('getZones')->willReturn([
            ['id' => 101, 'name' => 'two.example.com.', 'kind' => 'Master'],
            ['id' => 0, 'name' => 'unresolved.example.com.', 'type' => 'NATIVE'],
            ['id' => 100, 'name' => 'One.example.com.', 'type' => 'NATIVE'],
        ]);

        $zones = (new ApiDomainRepository($this->db, $this->config, $backend))->listZoneNames();

        $this->assertSame([
            ['id' => 100, 'name' => 'One.example.com', 'type' => 'NATIVE'],
            ['id' => 101, 'name' => 'two.example.com', 'type' => 'Master'],
        ], $zones);
    }

    #[Test]
    public function getZoneInfoFromIdsUsesOneBulkZoneListCall(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);

        // Name/type/master come from a single bulk zone-list call rather than a
        // zone-body fetch per zone. Record counts have no bulk equivalent - the
        // PowerDNS zone list carries none - so they stay one call per zone.
        $backend->expects($this->once())->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'one.example.com',   'type' => 'NATIVE', 'master' => ''],
            ['id' => 101, 'name' => 'two.example.com',   'type' => 'MASTER', 'master' => '192.0.2.1'],
            ['id' => 102, 'name' => 'three.example.com', 'type' => 'NATIVE', 'master' => ''],
        ]);
        $backend->expects($this->never())->method('getZoneStats');
        $backend->expects($this->exactly(3))
            ->method('countZoneRecords')
            ->willReturnMap([[100, 5], [101, 9], [102, 2]]);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZoneInfoFromIds([100, 101, 102]);

        $this->assertCount(3, $result);
        $byId = [];
        foreach ($result as $info) {
            $byId[$info['id']] = $info;
        }
        $this->assertSame('two.example.com', $byId[101]['name']);
        $this->assertSame('MASTER', $byId[101]['type']);
        $this->assertSame('192.0.2.1', $byId[101]['master_ip']);
        $this->assertSame(9, $byId[101]['record_count']);
        $this->assertSame(5, $byId[100]['record_count']);
        $this->assertSame(2, $byId[102]['record_count']);
    }

    #[Test]
    public function getZoneInfoFromIdsSkipsZonesMissingFromTheApi(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->expects($this->once())->method('getZones')->willReturn([
            ['id' => 100, 'name' => 'present.example.com', 'type' => 'NATIVE', 'master' => ''],
        ]);
        // The unknown id must not trigger a lookup for a zone that isn't there
        $backend->expects($this->once())->method('countZoneRecords')->with(100)->willReturn(42);

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);
        $result = $repo->getZoneInfoFromIds([100, 999]);

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['record_count']);
    }

    #[Test]
    public function getZoneInfoFromIdsReturnsEmptyForEmptyInput(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->expects($this->never())->method('getZones');
        $backend->expects($this->never())->method('countZoneRecords');

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);

        $this->assertSame([], $repo->getZoneInfoFromIds([]));
    }

    #[Test]
    public function zoneInfoIsEmptyForAZoneTheApiDoesNotHave(): void
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('getZoneById')->with(100)->willReturn(null);
        $backend->expects($this->never())->method('countZoneRecords');

        $repo = new ApiDomainRepository($this->db, $this->config, $backend);

        $this->assertSame([], $repo->getZoneInfoFromId(100));
    }
}
