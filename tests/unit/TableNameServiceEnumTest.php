<?php

namespace unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;

class TableNameServiceEnumTest extends TestCase
{
    private TableNameService $service;
    private ConfigurationManager&MockObject $mockConfig;

    protected function setUp(): void
    {
        $this->mockConfig = $this->createMock(ConfigurationManager::class);
        $this->service = new TableNameService($this->mockConfig);
    }

    public function testGetTableWithoutPrefix(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null);

        $service = new TableNameService($this->mockConfig);

        $this->assertEquals('domains', $service->getTable(PdnsTable::DOMAINS));
        $this->assertEquals('records', $service->getTable(PdnsTable::RECORDS));
        $this->assertEquals('comments', $service->getTable(PdnsTable::COMMENTS));
    }

    public function testGetTableWithPrefix(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('pdns_test');

        $service = new TableNameService($this->mockConfig);

        $this->assertEquals('pdns_test.domains', $service->getTable(PdnsTable::DOMAINS));
        $this->assertEquals('pdns_test.records', $service->getTable(PdnsTable::RECORDS));
        $this->assertEquals('pdns_test.comments', $service->getTable(PdnsTable::COMMENTS));
    }

    public function testGetTablesMultiple(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('test_db');

        $service = new TableNameService($this->mockConfig);

        $result = $service->getTables(
            PdnsTable::DOMAINS,
            PdnsTable::RECORDS,
            PdnsTable::COMMENTS
        );

        $expected = [
            'test_db.domains',
            'test_db.records',
            'test_db.comments'
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetTablesSingleTable(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null);

        $service = new TableNameService($this->mockConfig);

        $result = $service->getTables(PdnsTable::DOMAINS);

        $this->assertEquals(['domains'], $result);
    }

    public function testGetTablesEmptyArray(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('test');

        $service = new TableNameService($this->mockConfig);

        $result = $service->getTables();

        $this->assertEquals([], $result);
    }

    public function testAllValidTablesWork(): void
    {
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('full_test');

        $service = new TableNameService($this->mockConfig);

        $validTables = [
            ['enum' => PdnsTable::DOMAINS, 'expected' => 'full_test.domains'],
            ['enum' => PdnsTable::RECORDS, 'expected' => 'full_test.records'],
            ['enum' => PdnsTable::SUPERMASTERS, 'expected' => 'full_test.supermasters'],
            ['enum' => PdnsTable::COMMENTS, 'expected' => 'full_test.comments'],
            ['enum' => PdnsTable::DOMAINMETADATA, 'expected' => 'full_test.domainmetadata'],
            ['enum' => PdnsTable::CRYPTOKEYS, 'expected' => 'full_test.cryptokeys'],
            ['enum' => PdnsTable::TSIGKEYS, 'expected' => 'full_test.tsigkeys'],
        ];

        foreach ($validTables as $test) {
            $this->assertEquals($test['expected'], $service->getTable($test['enum']));
        }
    }

    public function testEnumPerformanceAndFunctionality(): void
    {
        // This test verifies enum-based method performance and functionality
        $this->mockConfig->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('perf_test');

        $service = new TableNameService($this->mockConfig);

        $testTables = [
            ['enum' => PdnsTable::DOMAINS, 'expected' => 'perf_test.domains'],
            ['enum' => PdnsTable::RECORDS, 'expected' => 'perf_test.records'],
            ['enum' => PdnsTable::COMMENTS, 'expected' => 'perf_test.comments'],
        ];

        foreach ($testTables as $test) {
            $enumResult = $service->getTable($test['enum']);
            $this->assertEquals(
                $test['expected'],
                $enumResult,
                "Enum method should return correct table name for {$test['enum']->value}"
            );
        }
    }
}
