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

    public function testGetNaturalSortMySQL()
    {
        $this->runTestForDatabase($this->mysqlConnection, 'mysql');
    }

    public function testGetNaturalSortPostgreSQL()
    {
        $this->runTestForDatabase($this->pgsqlConnection, 'pgsql');
    }

    public function testGetNaturalSortSQLite()
    {
        $this->runTestForDatabase($this->sqliteConnection, 'sqlite');
    }

    public function testGetNaturalSortMySQLDesc()
    {
        $this->runTestForDatabase($this->mysqlConnection, 'mysql', 'DESC');
    }

    public function testGetNaturalSortPostgreSQLDesc()
    {
        $this->runTestForDatabase($this->pgsqlConnection, 'pgsql', 'DESC');
    }

    public function testGetNaturalSortSQLiteDesc()
    {
        $this->runTestForDatabase($this->sqliteConnection, 'sqlite', 'DESC');
    }

    private function runTestForDatabase(PDO $connection, string $dbType, string $direction = 'ASC')
    {
        $table = 'test_table';

        // Create table and insert test data
        $connection->exec("CREATE TABLE $table (name VARCHAR(255))");
        $connection->exec("INSERT INTO $table (name) VALUES 
            ('0.168.192.in-addr.arpa'), 
            ('27.168.192.in-addr.arpa'), 
            ('45.168.192.in-addr.arpa'), 
            ('73.168.192.in-addr.arpa'), 
            ('89.168.192.in-addr.arpa'), 
            ('110.168.192.in-addr.arpa'), 
            ('132.168.192.in-addr.arpa'), 
            ('154.168.192.in-addr.arpa'), 
            ('194.168.192.in-addr.arpa'), 
            ('201.168.192.in-addr.arpa')");

        // Get the natural sort query
        $query = "SELECT * FROM $table ORDER BY " . SortHelper::getZoneSortOrder($table, $dbType, $direction);
        $stmt = $connection->query($query);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assert the order
        $expectedOrder = $direction === 'ASC' ? [
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
        ] : [
            '201.168.192.in-addr.arpa',
            '194.168.192.in-addr.arpa',
            '154.168.192.in-addr.arpa',
            '132.168.192.in-addr.arpa',
            '110.168.192.in-addr.arpa',
            '89.168.192.in-addr.arpa',
            '73.168.192.in-addr.arpa',
            '45.168.192.in-addr.arpa',
            '27.168.192.in-addr.arpa',
            '0.168.192.in-addr.arpa'
        ];

        foreach ($expectedOrder as $index => $expectedName) {
            $this->assertEquals($expectedName, $results[$index]['name']);
        }
    }
}
