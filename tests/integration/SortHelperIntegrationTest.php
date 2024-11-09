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

    private const EXAMPLE_TEST_DATA = [
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

    private const EXAMPLE_EXPECTED_ORDER_ASC = [
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

    private const ARPA_TEST_DATA = [
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

    private const ARPA_EXPECTED_ORDER_ASC = [
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
    }

    protected function tearDown(): void
    {
        $this->mysqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->pgsqlConnection->exec("DROP TABLE IF EXISTS test_table");
        $this->sqliteConnection->exec("DROP TABLE IF EXISTS test_table");
    }

    public function testGetNaturalSortMySQLExample()
    {
        $this->runTestForDatabase(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::EXAMPLE_TEST_DATA,
            self::EXAMPLE_EXPECTED_ORDER_ASC
        );
    }

    public function testGetNaturalSortMySQLExampleDesc()
    {
        $this->runTestForDatabase(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::EXAMPLE_TEST_DATA,
            array_reverse(self::EXAMPLE_EXPECTED_ORDER_ASC)
        );
    }

    public function testGetNaturalSortMySQLArpa()
    {
        $this->runTestForDatabase(
            $this->mysqlConnection,
            'mysql',
            'ASC',
            self::ARPA_TEST_DATA,
            self::ARPA_EXPECTED_ORDER_ASC
        );
    }

    public function testGetNaturalSortMySQLArpaDesc()
    {
        $this->runTestForDatabase(
            $this->mysqlConnection,
            'mysql',
            'DESC',
            self::ARPA_TEST_DATA,
            array_reverse(self::ARPA_EXPECTED_ORDER_ASC)
        );
    }

    private function runTestForDatabase(PDO $connection, string $dbType, string $direction, array $testData, array $expectedOrder)
    {
        $table = 'test_table';

        // Create table and insert test data
        $connection->exec("CREATE TABLE $table (name VARCHAR(255))");
        foreach ($testData as $data) {
            $connection->exec("INSERT INTO $table (name) VALUES ('$data')");
        }

        // Get the natural sort query
        $query = "SELECT * FROM $table ORDER BY " . SortHelper::getZoneSortOrder($table, $dbType, $direction);
        $stmt = $connection->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assert the order
        foreach ($expectedOrder as $index => $expectedName) {
            $this->assertEquals($expectedName, $results[$index]['name']);
        }
    }
}
