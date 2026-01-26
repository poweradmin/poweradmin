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
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RecordRepository::getFilteredRecords() SQL query behavior
 *
 * Tests the SQL query patterns used in getFilteredRecords() across all supported
 * database types (MySQL, PostgreSQL, SQLite) to ensure:
 * - LIMIT/OFFSET with bound parameters work correctly
 * - ORDER BY with JOINs doesn't cause ambiguous column errors
 * - Pagination works correctly across all databases
 *
 * @group manual
 */
class RecordRepositoryFilteredRecordsTest extends TestCase
{
    private ?PDO $mysqlConnection = null;
    private ?PDO $pgsqlConnection = null;
    private ?PDO $sqliteConnection = null;

    private const TEST_ZONE_ID = 1;

    protected function setUp(): void
    {
        // MySQL test database (port 3307)
        try {
            $this->mysqlConnection = new PDO(
                'mysql:host=127.0.0.1;port=3307;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->setupMySQLTables($this->mysqlConnection);
        } catch (PDOException $e) {
            $this->mysqlConnection = null;
        }

        // PostgreSQL test database (port 5433)
        try {
            $this->pgsqlConnection = new PDO(
                'pgsql:host=127.0.0.1;port=5433;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->setupPgSQLTables($this->pgsqlConnection);
        } catch (PDOException $e) {
            $this->pgsqlConnection = null;
        }

        // SQLite in-memory database
        $this->sqliteConnection = new PDO(
            'sqlite::memory:',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->setupSQLiteTables($this->sqliteConnection);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlConnection) {
            $this->mysqlConnection->exec("DROP TABLE IF EXISTS comments");
            $this->mysqlConnection->exec("DROP TABLE IF EXISTS records");
            $this->mysqlConnection->exec("DROP TABLE IF EXISTS domains");
        }
        if ($this->pgsqlConnection) {
            $this->pgsqlConnection->exec("DROP TABLE IF EXISTS comments");
            $this->pgsqlConnection->exec("DROP TABLE IF EXISTS records");
            $this->pgsqlConnection->exec("DROP TABLE IF EXISTS domains");
        }
        // SQLite in-memory is automatically cleaned up
    }

    private function setupMySQLTables(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS comments");
        $db->exec("DROP TABLE IF EXISTS records");
        $db->exec("DROP TABLE IF EXISTS domains");

        $db->exec("CREATE TABLE domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL
        )");

        $db->exec("CREATE TABLE records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            name VARCHAR(255),
            type VARCHAR(10),
            content VARCHAR(65535),
            ttl INT,
            prio INT,
            disabled TINYINT DEFAULT 0,
            auth TINYINT DEFAULT 1
        )");

        $db->exec("CREATE TABLE comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            name VARCHAR(255),
            type VARCHAR(10),
            comment TEXT
        )");

        $this->insertTestData($db);
    }

    private function setupPgSQLTables(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS comments");
        $db->exec("DROP TABLE IF EXISTS records");
        $db->exec("DROP TABLE IF EXISTS domains");

        $db->exec("CREATE TABLE domains (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL
        )");

        $db->exec("CREATE TABLE records (
            id SERIAL PRIMARY KEY,
            domain_id INT NOT NULL,
            name VARCHAR(255),
            type VARCHAR(10),
            content TEXT,
            ttl INT,
            prio INT,
            disabled SMALLINT DEFAULT 0,
            auth SMALLINT DEFAULT 1
        )");

        $db->exec("CREATE TABLE comments (
            id SERIAL PRIMARY KEY,
            domain_id INT NOT NULL,
            name VARCHAR(255),
            type VARCHAR(10),
            comment TEXT
        )");

        $this->insertTestData($db);
    }

    private function setupSQLiteTables(PDO $db): void
    {
        $db->exec("CREATE TABLE domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )");

        $db->exec("CREATE TABLE records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            name TEXT,
            type TEXT,
            content TEXT,
            ttl INTEGER,
            prio INTEGER,
            disabled INTEGER DEFAULT 0,
            auth INTEGER DEFAULT 1
        )");

        $db->exec("CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            name TEXT,
            type TEXT,
            comment TEXT
        )");

        $this->insertTestData($db);
    }

    private function insertTestData(PDO $db): void
    {
        // Insert test domain
        $db->exec("INSERT INTO domains (id, name) VALUES (1, 'example.com')");

        // Insert test records with various types
        $records = [
            [1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 3600 900 604800 86400', 86400, 0],
            [1, 'example.com', 'NS', 'ns1.example.com', 86400, 0],
            [1, 'example.com', 'NS', 'ns2.example.com', 86400, 0],
            [1, 'example.com', 'A', '192.0.2.1', 3600, 0],
            [1, 'www.example.com', 'A', '192.0.2.2', 3600, 0],
            [1, 'mail.example.com', 'A', '192.0.2.3', 3600, 0],
            [1, 'example.com', 'MX', 'mail.example.com', 3600, 10],
            [1, 'example.com', 'TXT', '"v=spf1 mx -all"', 3600, 0],
            [1, 'ftp.example.com', 'CNAME', 'www.example.com', 3600, 0],
            [1, 'api.example.com', 'A', '192.0.2.4', 3600, 0],
        ];

        $stmt = $db->prepare("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($records as $record) {
            $stmt->execute($record);
        }

        // Insert comments for some records (to test JOIN scenario)
        $comments = [
            [1, 'example.com', 'A', 'Main website IP'],
            [1, 'www.example.com', 'A', 'WWW subdomain'],
            [1, 'mail.example.com', 'A', 'Mail server'],
        ];

        $stmt = $db->prepare("INSERT INTO comments (domain_id, name, type, comment) VALUES (?, ?, ?, ?)");
        foreach ($comments as $comment) {
            $stmt->execute($comment);
        }
    }

    /**
     * Execute the getFilteredRecords query pattern
     *
     * This replicates the exact SQL query pattern from RecordRepository::getFilteredRecords()
     */
    private function executeFilteredRecordsQuery(
        PDO $db,
        int $zoneId,
        int $rowStart,
        int $rowAmount,
        string $sortBy,
        string $sortDirection,
        bool $includeComments
    ): array {
        $query = "SELECT records.id, records.domain_id, records.name, records.type,
                 records.content, records.ttl, records.prio, records.disabled, records.auth";

        if ($includeComments) {
            $query .= ", c.comment";
        }

        $query .= " FROM records";

        if ($includeComments) {
            $query .= " LEFT JOIN comments c ON records.domain_id = c.domain_id
                      AND records.name = c.name AND records.type = c.type";
        }

        $query .= " WHERE records.domain_id = :zone_id AND records.type IS NOT NULL AND records.type != ''";
        $query .= " ORDER BY records.$sortBy $sortDirection LIMIT :row_amount OFFSET :row_start";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':row_amount', $rowAmount, PDO::PARAM_INT);
        $stmt->bindValue(':row_start', $rowStart, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ===========================================
    // SQLite Tests
    // ===========================================

    public function testSQLiteBasicQueryWithoutComments(): void
    {
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(10, $results);
        $this->assertEquals('api.example.com', $results[0]['name']);
    }

    public function testSQLiteQueryWithCommentsJoin(): void
    {
        // This tests the scenario from PR #952 - JOIN with ORDER BY
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(10, $results);
        $this->assertArrayHasKey('comment', $results[0]);
    }

    public function testSQLitePaginationWithBoundParameters(): void
    {
        // Test LIMIT/OFFSET with bound parameters
        $page1 = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            0,
            3,
            'name',
            'ASC',
            false
        );

        $page2 = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            3,
            3,
            'name',
            'ASC',
            false
        );

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }

    public function testSQLiteOrderByWithJoinDoesNotCauseAmbiguousColumn(): void
    {
        // Test various sort columns with JOIN - should not cause "ambiguous column" error
        $sortColumns = ['id', 'name', 'type', 'content', 'ttl'];

        foreach ($sortColumns as $column) {
            $results = $this->executeFilteredRecordsQuery(
                $this->sqliteConnection,
                self::TEST_ZONE_ID,
                0,
                5,
                $column,
                'ASC',
                true  // Include comments JOIN
            );

            $this->assertNotEmpty($results, "Query with ORDER BY $column should return results");
        }
    }

    public function testSQLiteZeroOffset(): void
    {
        // Edge case: OFFSET 0
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            0,
            5,
            'name',
            'ASC',
            true
        );

        $this->assertCount(5, $results);
    }

    public function testSQLiteLargeOffset(): void
    {
        // Edge case: OFFSET larger than result set
        $results = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            100,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertEmpty($results);
    }

    // ===========================================
    // MySQL Tests
    // ===========================================

    public function testMySQLBasicQueryWithoutComments(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(10, $results);
    }

    public function testMySQLQueryWithCommentsJoin(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(10, $results);
        $this->assertArrayHasKey('comment', $results[0]);
    }

    public function testMySQLPaginationWithBoundParameters(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $page1 = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            self::TEST_ZONE_ID,
            0,
            3,
            'name',
            'ASC',
            false
        );

        $page2 = $this->executeFilteredRecordsQuery(
            $this->mysqlConnection,
            self::TEST_ZONE_ID,
            3,
            3,
            'name',
            'ASC',
            false
        );

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }

    public function testMySQLOrderByWithJoinDoesNotCauseAmbiguousColumn(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $sortColumns = ['id', 'name', 'type', 'content', 'ttl'];

        foreach ($sortColumns as $column) {
            $results = $this->executeFilteredRecordsQuery(
                $this->mysqlConnection,
                self::TEST_ZONE_ID,
                0,
                5,
                $column,
                'ASC',
                true
            );

            $this->assertNotEmpty($results, "Query with ORDER BY $column should return results");
        }
    }

    // ===========================================
    // PostgreSQL Tests
    // ===========================================

    public function testPgSQLBasicQueryWithoutComments(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            false
        );

        $this->assertCount(10, $results);
    }

    public function testPgSQLQueryWithCommentsJoin(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            self::TEST_ZONE_ID,
            0,
            10,
            'name',
            'ASC',
            true
        );

        $this->assertCount(10, $results);
        $this->assertArrayHasKey('comment', $results[0]);
    }

    public function testPgSQLPaginationWithBoundParameters(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $page1 = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            self::TEST_ZONE_ID,
            0,
            3,
            'name',
            'ASC',
            false
        );

        $page2 = $this->executeFilteredRecordsQuery(
            $this->pgsqlConnection,
            self::TEST_ZONE_ID,
            3,
            3,
            'name',
            'ASC',
            false
        );

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }

    public function testPgSQLOrderByWithJoinDoesNotCauseAmbiguousColumn(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $sortColumns = ['id', 'name', 'type', 'content', 'ttl'];

        foreach ($sortColumns as $column) {
            $results = $this->executeFilteredRecordsQuery(
                $this->pgsqlConnection,
                self::TEST_ZONE_ID,
                0,
                5,
                $column,
                'ASC',
                true
            );

            $this->assertNotEmpty($results, "Query with ORDER BY $column should return results");
        }
    }

    // ===========================================
    // Cross-database consistency tests
    // ===========================================

    public function testAllDatabasesReturnSameRecordCount(): void
    {
        $sqliteResults = $this->executeFilteredRecordsQuery(
            $this->sqliteConnection,
            self::TEST_ZONE_ID,
            0,
            100,
            'name',
            'ASC',
            true
        );

        if ($this->mysqlConnection) {
            $mysqlResults = $this->executeFilteredRecordsQuery(
                $this->mysqlConnection,
                self::TEST_ZONE_ID,
                0,
                100,
                'name',
                'ASC',
                true
            );
            $this->assertCount(count($sqliteResults), $mysqlResults, 'MySQL should return same count as SQLite');
        }

        if ($this->pgsqlConnection) {
            $pgsqlResults = $this->executeFilteredRecordsQuery(
                $this->pgsqlConnection,
                self::TEST_ZONE_ID,
                0,
                100,
                'name',
                'ASC',
                true
            );
            $this->assertCount(count($sqliteResults), $pgsqlResults, 'PostgreSQL should return same count as SQLite');
        }
    }
}
