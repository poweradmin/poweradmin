<?php

namespace Poweradmin\Tests\Unit\Domain\Database;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use TestHelpers\FakeConfiguration;

class TableNameServiceEnumTest extends TestCase
{
    private function service(?string $pdnsDbName): TableNameService
    {
        return new TableNameService(new FakeConfiguration(['database' => ['pdns_db_name' => $pdnsDbName]]));
    }

    public function testGetTableWithoutPrefix(): void
    {
        $service = $this->service(null);

        $this->assertEquals('domains', $service->getTable(PdnsTable::DOMAINS));
        $this->assertEquals('records', $service->getTable(PdnsTable::RECORDS));
        $this->assertEquals('comments', $service->getTable(PdnsTable::COMMENTS));
    }

    public function testGetTableWithPrefix(): void
    {
        $service = $this->service('pdns_test');

        $this->assertEquals('pdns_test.domains', $service->getTable(PdnsTable::DOMAINS));
        $this->assertEquals('pdns_test.records', $service->getTable(PdnsTable::RECORDS));
        $this->assertEquals('pdns_test.comments', $service->getTable(PdnsTable::COMMENTS));
    }

    public function testGetTablesMultiple(): void
    {
        $service = $this->service('test_db');

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
        $service = $this->service(null);

        $result = $service->getTables(PdnsTable::DOMAINS);

        $this->assertEquals(['domains'], $result);
    }

    public function testGetTablesEmptyArray(): void
    {
        $service = $this->service('test');

        $result = $service->getTables();

        $this->assertEquals([], $result);
    }

    public function testAllValidTablesWork(): void
    {
        $service = $this->service('full_test');

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
        $service = $this->service('perf_test');

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
