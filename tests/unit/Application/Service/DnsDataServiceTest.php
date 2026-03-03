<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use PDO;

#[CoversClass(DnsDataService::class)]
class DnsDataServiceTest extends TestCase
{
    private $mockBackend;
    private $mockDb;
    private $mockConfig;

    protected function setUp(): void
    {
        $this->mockBackend = $this->createMock(DnsBackendProvider::class);
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);
    }

    private function createService(): DnsDataService
    {
        return new DnsDataService($this->mockBackend, $this->mockDb, $this->mockConfig);
    }

    // ---------------------------------------------------------------
    // isApiBackend()
    // ---------------------------------------------------------------

    public function testIsApiBackendDelegates(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $service = $this->createService();
        $this->assertTrue($service->isApiBackend());
    }

    public function testIsNotApiBackend(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(false);
        $service = $this->createService();
        $this->assertFalse($service->isApiBackend());
    }

    // ---------------------------------------------------------------
    // countZones() - API mode
    // ---------------------------------------------------------------

    public function testCountZonesApiModeForward(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZones')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 2, 'name' => 'test.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 3, 'name' => '10.in-addr.arpa', 'type' => 'NATIVE', 'dnssec' => false],
        ]);

        $service = $this->createService();
        $count = $service->countZones('all', 'all', 'forward');

        $this->assertSame(2, $count);
    }

    public function testCountZonesApiModeReverse(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZones')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 2, 'name' => '10.in-addr.arpa', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 3, 'name' => '8.b.d.0.1.0.0.2.ip6.arpa', 'type' => 'NATIVE', 'dnssec' => false],
        ]);

        $service = $this->createService();
        $count = $service->countZones('all', 'all', 'reverse');

        $this->assertSame(2, $count);
    }

    public function testCountZonesApiModeWithLetterFilter(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZones')->willReturn([
            ['id' => 1, 'name' => 'alpha.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 2, 'name' => 'bravo.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 3, 'name' => 'another.com', 'type' => 'NATIVE', 'dnssec' => false],
        ]);

        $service = $this->createService();
        $count = $service->countZones('all', 'a', 'forward');

        $this->assertSame(2, $count);
    }

    // ---------------------------------------------------------------
    // getReverseZoneCounts() - API mode
    // ---------------------------------------------------------------

    public function testGetReverseZoneCountsApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZones')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 2, 'name' => '10.in-addr.arpa', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 3, 'name' => '192.in-addr.arpa', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 4, 'name' => '8.b.d.0.1.0.0.2.ip6.arpa', 'type' => 'NATIVE', 'dnssec' => false],
        ]);

        $service = $this->createService();
        $counts = $service->getReverseZoneCounts('all', 1);

        $this->assertSame(3, $counts['count_all']);
        $this->assertSame(2, $counts['count_ipv4']);
        $this->assertSame(1, $counts['count_ipv6']);
    }

    // ---------------------------------------------------------------
    // getDistinctStartingLetters() - API mode
    // ---------------------------------------------------------------

    public function testGetDistinctStartingLettersApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZones')->willReturn([
            ['id' => 1, 'name' => 'alpha.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 2, 'name' => 'bravo.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 3, 'name' => 'another.com', 'type' => 'NATIVE', 'dnssec' => false],
            ['id' => 4, 'name' => '10.in-addr.arpa', 'type' => 'NATIVE', 'dnssec' => false],
        ]);

        $service = $this->createService();
        $letters = $service->getDistinctStartingLetters(1, true);

        // Should only include forward zones (no reverse), letters a, b
        $this->assertContains('a', $letters);
        $this->assertContains('b', $letters);
        $this->assertCount(2, $letters);
    }

    // ---------------------------------------------------------------
    // searchZones() - API mode
    // ---------------------------------------------------------------

    public function testSearchZonesApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [
                ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE'],
            ],
            'records' => [],
        ]);

        // Mock the DB: query() for ownership, prepare() for record counts
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example', 'zones' => true, 'records' => false];
        $service = $this->createService();
        $result = $service->searchZones($parameters, 'all', 'name', 'ASC', 10, false, 1);

        $this->assertCount(1, $result);
        $this->assertSame('example.com', $result[0]['name']);
        $this->assertArrayHasKey('user_id', $result[0]);
        $this->assertArrayHasKey('owner_fullnames', $result[0]);
    }

    // ---------------------------------------------------------------
    // searchRecords() - API mode
    // ---------------------------------------------------------------

    public function testSearchRecordsApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [],
            'records' => [
                ['id' => 10, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
            ],
        ]);

        // Mock the DB: query() for ownership, prepare() for zone ownership enrichment
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example', 'zones' => false, 'records' => true, 'type_filter' => '', 'content_filter' => ''];
        $service = $this->createService();
        $result = $service->searchRecords($parameters, 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertCount(1, $result);
        $this->assertSame('www.example.com', $result[0]['name']);
        $this->assertSame('A', $result[0]['type']);
        $this->assertArrayHasKey('user_id', $result[0]);
        $this->assertArrayHasKey('domain_id', $result[0]);
    }

    public function testSearchZonesApiModeReturnsEmptyWhenNoMatch(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [],
            'records' => [],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'nonexistent', 'zones' => true, 'records' => false];
        $service = $this->createService();
        $result = $service->searchZones($parameters, 'all', 'name', 'ASC', 10, false, 1);

        $this->assertCount(0, $result);
    }

    public function testSearchZonesTotalCountApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [
                ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE'],
                ['id' => 2, 'name' => 'test.com', 'type' => 'NATIVE'],
            ],
            'records' => [],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example', 'zones' => true, 'records' => false];
        $service = $this->createService();
        $total = $service->searchZonesTotalCount($parameters, 'all');

        $this->assertSame(2, $total);
    }

    public function testSearchRecordsApiModeWithTypeFilter(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [],
            'records' => [
                ['id' => 10, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
                ['id' => 11, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'disabled' => 0, 'zone_name' => 'example.com'],
            ],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example', 'zones' => false, 'records' => true, 'type_filter' => 'A', 'content_filter' => ''];
        $service = $this->createService();
        $result = $service->searchRecords($parameters, 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertCount(1, $result);
        $this->assertSame('A', $result[0]['type']);
    }

    public function testSearchRecordsTotalCountApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [],
            'records' => [
                ['id' => 10, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
                ['id' => 11, 'domain_id' => 1, 'name' => 'mail.example.com', 'type' => 'A', 'content' => '5.6.7.8', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
            ],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example', 'zones' => false, 'records' => true, 'type_filter' => '', 'content_filter' => ''];
        $service = $this->createService();
        $total = $service->searchRecordsTotalCount($parameters, 'all', false);

        $this->assertSame(2, $total);
    }

    // ---------------------------------------------------------------
    // searchZones() - API mode: preprocessing
    // ---------------------------------------------------------------

    public function testSearchZonesApiModeExactMatchWhenWildcardOff(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [
                ['id' => 1, 'name' => 'example.com', 'type' => 'NATIVE'],
                ['id' => 2, 'name' => 'sub.example.com', 'type' => 'NATIVE'],
            ],
            'records' => [],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'example.com', 'zones' => true, 'records' => false, 'wildcard' => false, 'reverse' => false];
        $service = $this->createService();
        $result = $service->searchZones($parameters, 'all', 'name', 'ASC', 10, false, 1);

        // Only exact match should remain
        $this->assertCount(1, $result);
        $this->assertSame('example.com', $result[0]['name']);
    }

    public function testSearchRecordsApiModeExactMatchWhenWildcardOff(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('searchDnsData')->willReturn([
            'zones' => [],
            'records' => [
                ['id' => 10, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
                ['id' => 11, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'A', 'content' => '5.6.7.8', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => 'example.com'],
            ],
        ]);

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => 'www.example.com', 'zones' => false, 'records' => true, 'wildcard' => false, 'reverse' => false, 'type_filter' => '', 'content_filter' => ''];
        $service = $this->createService();
        $result = $service->searchRecords($parameters, 'all', 'name', 'ASC', false, 10, false, 1);

        // Only exact name match should remain
        $this->assertCount(1, $result);
        $this->assertSame('www.example.com', $result[0]['name']);
    }

    public function testSearchZonesApiModeReverseIpExpansion(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);

        // First call: direct query "192.168.1" - no results
        // Second call: reverse query "1.168.192" - finds the arpa zone
        $this->mockBackend->method('searchDnsData')->willReturnCallback(
            function (string $query, string $objectType) {
                if ($query === '192.168.1.0') {
                    return ['zones' => [], 'records' => []];
                }
                if ($query === '0.1.168.192') {
                    return [
                        'zones' => [
                            ['id' => 5, 'name' => '1.168.192.in-addr.arpa', 'type' => 'NATIVE'],
                        ],
                        'records' => [],
                    ];
                }
                return ['zones' => [], 'records' => []];
            }
        );

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        $this->mockDb->method('query')->willReturn($mockStmt);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockConfig->method('get')->willReturn('');

        $parameters = ['query' => '192.168.1.0', 'zones' => true, 'records' => false, 'wildcard' => true, 'reverse' => true];
        $service = $this->createService();
        $result = $service->searchZones($parameters, 'all', 'name', 'ASC', 10, false, 1);

        $this->assertCount(1, $result);
        $this->assertSame('1.168.192.in-addr.arpa', $result[0]['name']);
    }

    // ---------------------------------------------------------------
    // getZoneRecords() - API mode
    // ---------------------------------------------------------------

    public function testGetZoneRecordsApiMode(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZoneRecords')->willReturn([
            ['id' => 1, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com admin.example.com 2024010101 3600 600 86400 3600', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 2, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 3, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 4, 'domain_id' => 1, 'name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'disabled' => 0],
        ]);

        $service = $this->createService();
        $result = $service->getZoneRecords(1, 'example.com', 0, 10, 'name', 'ASC');

        $this->assertArrayHasKey('records', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(4, $result['total']);
        $this->assertCount(4, $result['records']);
    }

    public function testGetZoneRecordsApiModeWithTypeFilter(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZoneRecords')->willReturn([
            ['id' => 1, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'test', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 2, 'domain_id' => 1, 'name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 3, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
        ]);

        $service = $this->createService();
        $result = $service->getZoneRecords(1, 'example.com', 0, 10, 'name', 'ASC', false, '', 'A');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['records']);
        $this->assertSame('A', $result['records'][0]['type']);
    }

    public function testGetZoneRecordsApiModeWithPagination(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);

        $records = [];
        for ($i = 1; $i <= 20; $i++) {
            $records[] = [
                'id' => $i, 'domain_id' => 1, 'name' => "host{$i}.example.com",
                'type' => 'A', 'content' => "10.0.0.{$i}", 'ttl' => 3600, 'prio' => 0, 'disabled' => 0,
            ];
        }
        $this->mockBackend->method('getZoneRecords')->willReturn($records);

        $service = $this->createService();
        $result = $service->getZoneRecords(1, 'example.com', 5, 10, 'name', 'ASC');

        $this->assertSame(20, $result['total']);
        $this->assertCount(10, $result['records']);
    }

    public function testGetZoneRecordsApiModeWithSearch(): void
    {
        $this->mockBackend->method('isApiBackend')->willReturn(true);
        $this->mockBackend->method('getZoneRecords')->willReturn([
            ['id' => 1, 'domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 2, 'domain_id' => 1, 'name' => 'mail.example.com', 'type' => 'A', 'content' => '5.6.7.8', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 3, 'domain_id' => 1, 'name' => 'ftp.example.com', 'type' => 'A', 'content' => '1.2.3.5', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
        ]);

        $service = $this->createService();
        $result = $service->getZoneRecords(1, 'example.com', 0, 10, 'name', 'ASC', false, 'mail');

        $this->assertSame(1, $result['total']);
        $this->assertSame('mail.example.com', $result['records'][0]['name']);
    }
}
