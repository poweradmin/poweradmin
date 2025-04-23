<?php

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\NaturalSorting;

/**
 * @group manual
 */
class NaturalSortingIntegrationTest extends TestCase
{
    private PDO $mysqlConnection;
    private PDO $pgsqlConnection;
    private PDO $sqliteConnection;
    private NaturalSorting $naturalSorting;

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

    private const REVERSE_ZONE_SORT_TEST_DATA = [
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

    private const REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC = [
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

    protected function setUp(): void
    {
        $this->mysqlConnection = new PDO('mysql:host=127.0.0.1;dbname=pdns', 'pdns', 'poweradmin');
        $this->pgsqlConnection = new PDO('pgsql:host=127.0.0.1;dbname=pdns', 'pdns', 'poweradmin');
        $this->sqliteConnection = new PDO('sqlite::memory:');
        $this->naturalSorting = new NaturalSorting();
    }

    protected function tearDown(): void
    {
        $this->mysqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->pgsqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->sqliteConnection->exec("DROP TABLE IF EXISTS test_table");
    }

    public function testGetNaturalSortOrderMySQLExample()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getNaturalSortOrder'
        );
    }

    public function testGetNaturalSortOrderMySQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getNaturalSortOrder'
        );
    }

    public function testGetNaturalSortOrderPgSQLExample()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getNaturalSortOrder'
        );
    }

    public function testGetNaturalSortOrderPgSQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getNaturalSortOrder'
        );
    }

    public function testGetNaturalSortOrderSQLiteExample()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::ZONE_SORT_TEST_DATA,
            self::ZONE_SORT_EXPECTED_ORDER_ASC,
            'getNaturalSortOrder'
        );
    }

    public function testGetNaturalSortOrderSQLiteExampleDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::ZONE_SORT_TEST_DATA,
            array_reverse(self::ZONE_SORT_EXPECTED_ORDER_ASC),
            'getNaturalSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderMySQLExample()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC,
            'getReverseZoneSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderMySQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            array_reverse(self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC),
            'getReverseZoneSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderPgSQLExample()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'ASC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC,
            'getReverseZoneSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderPgSQLExampleDesc()
    {
        $this->runDatabaseTest(
            $this->pgsqlConnection,
            'pgsql',
            'DESC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            array_reverse(self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC),
            'getReverseZoneSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderSQLiteExample()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'ASC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC,
            'getReverseZoneSortOrder'
        );
    }

    public function testGetReverseZoneSortOrderSQLiteExampleDesc()
    {
        $this->runDatabaseTest(
            $this->sqliteConnection,
            'sqlite',
            'DESC',
            self::REVERSE_ZONE_SORT_TEST_DATA,
            array_reverse(self::REVERSE_ZONE_SORT_EXPECTED_ORDER_ASC),
            'getReverseZoneSortOrder'
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
        $query = "SELECT * FROM $table ORDER BY " . $this->naturalSorting->$sortMethod($table, $dbType, $direction);
        $stmt = $connection->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assert the order
        foreach ($expectedOrder as $index => $expectedName) {
            $this->assertEquals($expectedName, $results[$index]['name']);
        }
    }
}
