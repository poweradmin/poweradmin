<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RecordRepository::getFilteredRecords() method.
 *
 * These tests verify the SQL query behavior across MySQL, PostgreSQL, and SQLite,
 * specifically testing:
 * - ORDER BY column qualification when JOINs are used
 * - LIMIT/OFFSET parameter binding
 * - Search and filter conditions
 *
 * @group manual
 */
class RecordRepositoryFilteredRecordsTest extends TestCase
{
    private ?PDO $mysqlConnection = null;
    private ?PDO $pgsqlConnection = null;
    private ?PDO $sqliteConnection = null;

    private const TEST_RECORDS = [
        ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
        ['name' => 'mail.example.com', 'type' => 'A', 'content' => '192.168.1.2', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
        ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'prio' => 10, 'disabled' => 0],
        ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 86400, 'prio' => 0, 'disabled' => 0],
        ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns2.example.com', 'ttl' => 86400, 'prio' => 0, 'disabled' => 0],
        ['name' => 'ftp.example.com', 'type' => 'CNAME', 'content' => 'www.example.com', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
        ['name' => 'api.example.com', 'type' => 'A', 'content' => '192.168.1.3', 'ttl' => 3600, 'prio' => 0, 'disabled' => 1],
        ['name' => 'example.com', 'type' => 'TXT', 'content' => 'v=spf1 include:_spf.example.com ~all', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
    ];

    private const TEST_COMMENTS = [
        ['name' => 'www.example.com', 'type' => 'A', 'comment' => 'Main website'],
        ['name' => 'mail.example.com', 'type' => 'A', 'comment' => 'Mail server'],
        ['name' => 'example.com', 'type' => 'MX', 'comment' => 'Primary MX record'],
    ];

    protected function setUp(): void
    {
        // MySQL connection (devcontainer uses default port 3306)
        try {
            $this->mysqlConnection = new PDO(
                'mysql:host=127.0.0.1;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('MySQL connection not available: ' . $e->getMessage());
        }

        // PostgreSQL connection (devcontainer uses default port 5432)
        try {
            $this->pgsqlConnection = new PDO(
                'pgsql:host=127.0.0.1;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('PostgreSQL connection not available: ' . $e->getMessage());
        }

        // SQLite in-memory connection
        $this->sqliteConnection = new PDO(
            'sqlite::memory:',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function tearDown(): void
    {
        // Clean up test tables
        $tables = ['comments', 'records', 'domains'];

        foreach ($tables as $table) {
            if (isset($this->mysqlConnection)) {
                $this->mysqlConnection->exec("DROP TABLE IF EXISTS test_$table");
            }
            if (isset($this->pgsqlConnection)) {
                $this->pgsqlConnection->exec("DROP TABLE IF EXISTS test_$table");
            }
            if (isset($this->sqliteConnection)) {
                $this->sqliteConnection->exec("DROP TABLE IF EXISTS test_$table");
            }
        }
    }

    /**
     * Create test tables for a specific database connection
     */
    private function createTestTables(PDO $connection, string $dbType): void
    {
        // Create domains table
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_domains (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_domains (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) NOT NULL
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_domains (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL
                )
            ");
        }

        // Create records table
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_records (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    domain_id INT,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT,
                    prio INT,
                    disabled TINYINT(1) DEFAULT 0
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_records (
                    id BIGSERIAL PRIMARY KEY,
                    domain_id INT,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT,
                    prio INT,
                    disabled SMALLINT DEFAULT 0
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_records (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain_id INT,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT,
                    prio INT,
                    disabled INT DEFAULT 0
                )
            ");
        }

        // Create comments table (has columns with same names as records: name, type)
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    domain_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) NOT NULL,
                    modified_at INT NOT NULL,
                    comment TEXT NOT NULL
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_comments (
                    id SERIAL PRIMARY KEY,
                    domain_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) NOT NULL,
                    modified_at INT NOT NULL,
                    comment TEXT NOT NULL
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) NOT NULL,
                    modified_at INT NOT NULL,
                    comment TEXT NOT NULL
                )
            ");
        }
    }

    /**
     * Insert test data into tables
     */
    private function insertTestData(PDO $connection, int $domainId): void
    {
        // Insert domain
        $stmt = $connection->prepare("INSERT INTO test_domains (id, name) VALUES (:id, :name)");
        $stmt->execute([':id' => $domainId, ':name' => 'example.com']);

        // Insert records
        $stmt = $connection->prepare("
            INSERT INTO test_records (domain_id, name, type, content, ttl, prio, disabled)
            VALUES (:domain_id, :name, :type, :content, :ttl, :prio, :disabled)
        ");

        foreach (self::TEST_RECORDS as $record) {
            $stmt->execute([
                ':domain_id' => $domainId,
                ':name' => $record['name'],
                ':type' => $record['type'],
                ':content' => $record['content'],
                ':ttl' => $record['ttl'],
                ':prio' => $record['prio'],
                ':disabled' => $record['disabled'],
            ]);
        }

        // Insert comments
        $stmt = $connection->prepare("
            INSERT INTO test_comments (domain_id, name, type, modified_at, comment)
            VALUES (:domain_id, :name, :type, :modified_at, :comment)
        ");

        foreach (self::TEST_COMMENTS as $comment) {
            $stmt->execute([
                ':domain_id' => $domainId,
                ':name' => $comment['name'],
                ':type' => $comment['type'],
                ':modified_at' => time(),
                ':comment' => $comment['comment'],
            ]);
        }
    }

    /**
     * Execute the getFilteredRecords query pattern (mimics RecordRepository::getFilteredRecords)
     */
    private function executeFilteredRecordsQuery(
        PDO $connection,
        int $zoneId,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $includeComments,
        string $searchTerm = '',
        string $typeFilter = '',
        string $contentFilter = ''
    ): array {
        $recordsTable = 'test_records';
        $commentsTable = 'test_comments';

        // Prepare query parameters
        $params = [':zone_id' => $zoneId];

        // Apply search term
        $searchCondition = '';
        if (!empty($searchTerm)) {
            if (strpos($searchTerm, '%') === false) {
                $searchTerm = '%' . $searchTerm . '%';
            }
            $searchCondition = " AND ($recordsTable.name LIKE :search_term1 OR $recordsTable.content LIKE :search_term2)";
            $params[':search_term1'] = $searchTerm;
            $params[':search_term2'] = $searchTerm;
        }

        // Apply type filter
        $typeCondition = '';
        if (!empty($typeFilter)) {
            $typeCondition = " AND $recordsTable.type = :type_filter";
            $params[':type_filter'] = $typeFilter;
        }

        // Apply content filter
        $contentCondition = '';
        if (!empty($contentFilter)) {
            if (strpos($contentFilter, '%') === false) {
                $contentFilter = '%' . $contentFilter . '%';
            }
            $contentCondition = " AND $recordsTable.content LIKE :content_filter";
            $params[':content_filter'] = $contentFilter;
        }

        // Base query
        $query = "SELECT $recordsTable.id, $recordsTable.domain_id, $recordsTable.name, $recordsTable.type,
                 $recordsTable.content, $recordsTable.ttl, $recordsTable.prio, $recordsTable.disabled";

        // Add comment column if needed
        if ($includeComments) {
            $query .= ", c.comment";
        }

        // From and joins
        $query .= " FROM $recordsTable";
        if ($includeComments) {
            $query .= " LEFT JOIN $commentsTable c ON $recordsTable.domain_id = c.domain_id
                      AND $recordsTable.name = c.name AND $recordsTable.type = c.type";
        }

        // Where clause
        $query .= " WHERE $recordsTable.domain_id = :zone_id AND $recordsTable.type IS NOT NULL AND $recordsTable.type != ''" .
                 $searchCondition . $typeCondition . $contentCondition;

        // Sorting and limits - THIS IS THE PATTERN FROM THE CURRENT CODE
        $query .= " ORDER BY $recordsTable.$sortBy $sortDirection LIMIT :row_amount OFFSET :row_start";

        $stmt = $connection->prepare($query);

        // Bind parameters - use PDO::PARAM_INT for LIMIT and OFFSET
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':row_amount', $rowAmount, PDO::PARAM_INT);
        $stmt->bindValue(':row_start', $rowStart, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==================== MySQL Tests ====================

    public function testMySQLBasicQueryWithoutComments(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(8, $results);
        $this->assertEquals('api.example.com', $results[0]['name']);
    }

    public function testMySQLQueryWithCommentsJoin(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
        // www.example.com should have a comment
        $wwwRecord = array_filter($results, fn($r) => $r['name'] === 'www.example.com' && $r['type'] === 'A');
        $wwwRecord = array_values($wwwRecord)[0];
        $this->assertEquals('Main website', $wwwRecord['comment']);
    }

    public function testMySQLLimitOffset(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        // Get first 3 records
        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $firstBatchIds = array_column($results, 'id');

        // Get next 3 records with offset
        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            3,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $secondBatchIds = array_column($results, 'id');

        // Ensure no overlap - IDs should be unique
        $this->assertEmpty(array_intersect($firstBatchIds, $secondBatchIds));
    }

    public function testMySQLSortByType(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            10,
            'type',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
        // First should be 'A' type records
        $this->assertEquals('A', $results[0]['type']);
    }

    public function testMySQLSearchTerm(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false,
            'mail'
        );

        $this->assertCount(2, $results); // mail.example.com A record and MX record content
    }

    public function testMySQLTypeFilter(): void
    {
        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertTestData($this->mysqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false,
            '',
            'A'
        );

        // Test data has 3 A records: www, mail, api
        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertEquals('A', $record['type']);
        }
    }

    // ==================== PostgreSQL Tests ====================

    public function testPgSQLBasicQueryWithoutComments(): void
    {
        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertTestData($this->pgsqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(8, $results);
    }

    public function testPgSQLQueryWithCommentsJoin(): void
    {
        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertTestData($this->pgsqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
    }

    public function testPgSQLLimitOffset(): void
    {
        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertTestData($this->pgsqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            1,
            0,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $firstBatchIds = array_column($results, 'id');

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            1,
            3,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $secondBatchIds = array_column($results, 'id');

        // Ensure no overlap - IDs should be unique
        $this->assertEmpty(array_intersect($firstBatchIds, $secondBatchIds));
    }

    public function testPgSQLSortByTypeWithComments(): void
    {
        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertTestData($this->pgsqlConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            1,
            0,
            10,
            'type',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
        $this->assertEquals('A', $results[0]['type']);
    }

    // ==================== SQLite Tests ====================

    public function testSQLiteBasicQueryWithoutComments(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(8, $results);
    }

    /**
     * This test specifically checks the "ambiguous column name" issue
     * that PR #952 claims to fix. The comments table has columns 'name' and 'type'
     * which match the records table, potentially causing ambiguity in ORDER BY.
     */
    public function testSQLiteQueryWithCommentsJoinAmbiguousColumn(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        // This should NOT throw "ambiguous column name" error
        // if the ORDER BY is properly qualified with table name
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
    }

    /**
     * Test sorting by 'type' column which exists in both records and comments tables.
     * This is the most likely scenario to trigger "ambiguous column name" in SQLite.
     */
    public function testSQLiteSortByTypeWithCommentsJoin(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        // Sort by 'type' with comments JOIN - type column exists in both tables
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'type',
            'ASC',
            true
        );

        $this->assertCount(8, $results);
        $this->assertEquals('A', $results[0]['type']);
    }

    /**
     * Test LIMIT/OFFSET binding which PR #952 claims can be problematic on SQLite.
     */
    public function testSQLiteLimitOffsetBinding(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        // Test with bound LIMIT/OFFSET parameters
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $firstBatchIds = array_column($results, 'id');

        // Test with non-zero offset
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            3,
            3,
            'id',
            'ASC',
            false
        );
        $this->assertCount(3, $results);
        $secondBatchIds = array_column($results, 'id');

        // Verify pagination works correctly - IDs should not overlap
        $this->assertEmpty(array_intersect($firstBatchIds, $secondBatchIds));
    }

    public function testSQLiteZeroOffset(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        // Test with offset = 0 specifically
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            100,
            'name',
            'ASC',
            false
        );

        $this->assertCount(8, $results);
    }

    public function testSQLiteLargeOffset(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        // Test with offset larger than result set
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            100,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertEmpty($results);
    }

    public function testSQLiteSearchWithCommentsJoin(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            true,
            'www'
        );

        $this->assertCount(2, $results); // www.example.com and ftp CNAME pointing to www
    }

    public function testSQLiteDescendingSort(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        $resultsAsc = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $resultsDesc = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            1,
            0,
            10,
            'name',
            'DESC',
            false
        );

        $this->assertEquals(
            array_reverse(array_column($resultsAsc, 'name')),
            array_column($resultsDesc, 'name')
        );
    }

    /**
     * Test all valid sort columns to ensure they all work with the JOIN.
     */
    public function testSQLiteAllSortColumnsWithJoin(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertTestData($this->sqliteConnection, 1);

        $allowedSortColumns = ['id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled'];

        foreach ($allowedSortColumns as $column) {
            $results = $this->executeFilteredRecordsQuery(
                $this->sqliteConnection,
                1,
                0,
                10,
                $column,
                'ASC',
                true
            );

            $this->assertCount(8, $results, "Failed for sort column: $column");
        }
    }
}
