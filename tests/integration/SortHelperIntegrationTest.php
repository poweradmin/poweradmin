<?php

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * @group manual
 */
class SortHelperIntegrationTest extends TestCase
{
    private PDO $mysqlConnection;
    private PDO $pgsqlConnection;
    private PDO $sqliteConnection;

    private const ZONE_SORT_TEST_DATA = [
        'example47.com',
        'example16.com',
        'example31.com',
        'example99.com',
        'example12.com',
        'example5.com',
        'example27.com',
        'example36.com',
        'example66.com',
        'example91.com'
    ];

    private const ZONE_SORT_EXPECTED_ORDER_ASC = [
        'example12.com',
        'example16.com',
        'example27.com',
        'example31.com',
        'example36.com',
        'example47.com',
        'example5.com',
        'example66.com',
        'example91.com',
        'example99.com'
    ];

    private const ZONE_SORT_ARPA_TEST_DATA = [
        '0.168.192.in-addr.arpa',
        '27.168.192.in-addr.arpa',
        '45.168.192.in-addr.arpa',
        '73.168.192.in-addr.arpa',
        '89.168.192.in-addr.arpa',
        '110.168.192.in-addr.arpa',
        '132.168.192.in-addr.arpa',
        '154.168.192.in-addr.arpa',
        '194.168.192.in-addr.arpa',
        '201.168.192.in-addr.arpa'
    ];

    private const ZONE_SORT_ARPA_EXPECTED_ORDER_ASC = [
        '0.168.192.in-addr.arpa',
        '27.168.192.in-addr.arpa',
        '45.168.192.in-addr.arpa',
        '73.168.192.in-addr.arpa',
        '89.168.192.in-addr.arpa',
        '110.168.192.in-addr.arpa',
        '132.168.192.in-addr.arpa',
        '154.168.192.in-addr.arpa',
        '194.168.192.in-addr.arpa',
        '201.168.192.in-addr.arpa'
    ];

    // Test data from ticket https://github.com/poweradmin/poweradmin/issues/80
    private const RECORD_SORT_PTR_RECORDS = [
        '15.192.168.1.in-addr.arpa',
        '251.192.168.1.in-addr.arpa',
        '1.192.168.1.in-addr.arpa',
        '100.192.168.1.in-addr.arpa',
        '20.192.168.1.in-addr.arpa',
        '10.192.168.1.in-addr.arpa'
    ];

    private const RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC = [
        '1.192.168.1.in-addr.arpa',
        '10.192.168.1.in-addr.arpa',
        '15.192.168.1.in-addr.arpa',
        '20.192.168.1.in-addr.arpa',
        '100.192.168.1.in-addr.arpa',
        '251.192.168.1.in-addr.arpa'
    ];

    protected function setUp(): void
    {
        $this->mysqlConnection = new PDO('mysql:host=127.0.0.1;dbname=pdns', 'pdns', 'poweradmin');
        $this->pgsqlConnection = new PDO('pgsql:host=127.0.0.1;dbname=pdns', 'pdns', 'poweradmin');
        $this->sqliteConnection = new PDO('sqlite::memory:');
    }

    protected function tearDown(): void
    {
        $this->mysqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->pgsqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->sqliteConnection->exec("DROP TABLE IF EXISTS test_table");
    }

    public function testGetZoneSortOrderMySQLExample()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderMySQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderMySQLArpa()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderMySQLArpaDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            array_reverse(self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderPgSQLExample()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderPgSQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderPgSQLArpa()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderPgSQLArpaDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            array_reverse(self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderSQLiteExample()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderSQLiteExampleDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderSQLiteArpa()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC,
            'getZoneSortOrder'
        );
    }

    public function testGetZoneSortOrderSQLiteArpaDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::ZONE_SORT_ARPA_TEST_DATA,
            array_reverse(self::ZONE_SORT_ARPA_EXPECTED_ORDER_ASC),
            'getZoneSortOrder'
        );
    }

    public function testGetRecordSortOrderMySQLAsc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderMySQLDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderMySQLArpaAsc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::RECORD_SORT_PTR_RECORDS,
            self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderMySQLArpaDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::RECORD_SORT_PTR_RECORDS,
            array_reverse(self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderPgSQLAsc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderPgSQLDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderPgSQLArpaAsc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::RECORD_SORT_PTR_RECORDS,
            self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderPgSQLArpaDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::RECORD_SORT_PTR_RECORDS,
            array_reverse(self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderSQLiteAsc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderSQLiteDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderSQLiteArpaAsc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::RECORD_SORT_PTR_RECORDS,
            self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC,
            'getRecordSortOrder'
        );
    }

    public function testGetRecordSortOrderSQLiteArpaDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::RECORD_SORT_PTR_RECORDS,
            array_reverse(self::RECORD_SORT_PTR_RECORDS_EXPECTED_ORDER_ASC),
            'getRecordSortOrder'
        );
    }

    private function runDatabaseTest(PDO $connection, string $dbType, string $direction, array $testData, array $expectedOrder, string $sortMethod): void
    {
        $table = 'test_table';

        // Create table and insert test data
        $connection->exec("CREATE TABLE $table (name VARCHAR(255))");
        foreach ($testData as $data) {
            $connection->exec("INSERT INTO $table (name) VALUES ('$data')");
        }

        // Get the natural sort query
        $query = "SELECT * FROM $table ORDER BY " . SortHelper::$sortMethod($table, $dbType, $direction);
        $stmt = $connection->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assert the order
        foreach ($expectedOrder as $index => $expectedName) {
            $this->assertEquals($expectedName, $results[$index]['name']);
        }
    }
}
