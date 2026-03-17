<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
use Poweradmin\Infrastructure\Database\DbCompat;

/**
 * Integration tests for record comment subquery patterns across all databases.
 *
 * Tests the COALESCE-based comment resolution used in:
 * - RecordRepository::getRecordsFromDomainId()
 * - RecordRepository::getFilteredRecords()
 * - RecordSearch::searchRecords()
 *
 * The comment subquery must:
 * 1. Prefer per-record linked comments (via record_comment_links table)
 * 2. Fall back to RRset-based comments (matched by domain_id + name + type)
 * 3. Work identically on MySQL, PostgreSQL, and SQLite
 *
 * The old pattern used ORDER BY with outer table references in a correlated
 * subquery, which causes "no such column: records.id" on SQLite.
 */
class RecordCommentSubqueryTest extends TestCase
{
    private ?PDO $mysqlConnection = null;
    private ?PDO $pgsqlConnection = null;
    private ?PDO $sqliteConnection = null;

    private const TEST_DOMAIN_ID = 1;

    protected function setUp(): void
    {
        try {
            $this->mysqlConnection = new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->setupTables($this->mysqlConnection, 'mysql');
        } catch (PDOException $e) {
            $this->mysqlConnection = null;
        }

        try {
            $this->pgsqlConnection = new PDO(
                'pgsql:host=127.0.0.1;port=5432;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->setupTables($this->pgsqlConnection, 'pgsql');
        } catch (PDOException $e) {
            $this->pgsqlConnection = null;
        }

        $this->sqliteConnection = new PDO(
            'sqlite::memory:',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->setupTables($this->sqliteConnection, 'sqlite');
    }

    protected function tearDown(): void
    {
        foreach (['mysql' => $this->mysqlConnection, 'pgsql' => $this->pgsqlConnection] as $conn) {
            if ($conn) {
                $conn->exec("DROP TABLE IF EXISTS test_rcl_links");
                $conn->exec("DROP TABLE IF EXISTS test_rcl_comments");
                $conn->exec("DROP TABLE IF EXISTS test_rcl_records");
                $conn->exec("DROP TABLE IF EXISTS test_rcl_domains");
            }
        }
    }

    private function setupTables(PDO $db, string $type): void
    {
        $db->exec("DROP TABLE IF EXISTS test_rcl_links");
        $db->exec("DROP TABLE IF EXISTS test_rcl_comments");
        $db->exec("DROP TABLE IF EXISTS test_rcl_records");
        $db->exec("DROP TABLE IF EXISTS test_rcl_domains");

        if ($type === 'mysql') {
            $db->exec("CREATE TABLE test_rcl_domains (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )");
            $db->exec("CREATE TABLE test_rcl_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                domain_id INT NOT NULL,
                name VARCHAR(255),
                type VARCHAR(10),
                content TEXT,
                ttl INT DEFAULT 3600,
                prio INT DEFAULT 0,
                disabled TINYINT DEFAULT 0,
                auth TINYINT DEFAULT 1
            )");
            $db->exec("CREATE TABLE test_rcl_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                domain_id INT NOT NULL,
                name VARCHAR(255),
                type VARCHAR(10),
                comment TEXT
            )");
            $db->exec("CREATE TABLE test_rcl_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                record_id VARCHAR(2048) CHARACTER SET ascii NOT NULL,
                comment_id INT NOT NULL
            )");
        } elseif ($type === 'pgsql') {
            $db->exec("CREATE TABLE test_rcl_domains (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )");
            $db->exec("CREATE TABLE test_rcl_records (
                id SERIAL PRIMARY KEY,
                domain_id INT NOT NULL,
                name VARCHAR(255),
                type VARCHAR(10),
                content TEXT,
                ttl INT DEFAULT 3600,
                prio INT DEFAULT 0,
                disabled SMALLINT DEFAULT 0,
                auth SMALLINT DEFAULT 1
            )");
            $db->exec("CREATE TABLE test_rcl_comments (
                id SERIAL PRIMARY KEY,
                domain_id INT NOT NULL,
                name VARCHAR(255),
                type VARCHAR(10),
                comment TEXT
            )");
            $db->exec("CREATE TABLE test_rcl_links (
                id SERIAL PRIMARY KEY,
                record_id VARCHAR(2048) NOT NULL,
                comment_id INT NOT NULL
            )");
        } else {
            $db->exec("CREATE TABLE test_rcl_domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )");
            $db->exec("CREATE TABLE test_rcl_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id INTEGER NOT NULL,
                name TEXT,
                type TEXT,
                content TEXT,
                ttl INTEGER DEFAULT 3600,
                prio INTEGER DEFAULT 0,
                disabled INTEGER DEFAULT 0,
                auth INTEGER DEFAULT 1
            )");
            $db->exec("CREATE TABLE test_rcl_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id INTEGER NOT NULL,
                name TEXT,
                type TEXT,
                comment TEXT
            )");
            $db->exec("CREATE TABLE test_rcl_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                record_id VARCHAR(2048) NOT NULL,
                comment_id INTEGER NOT NULL
            )");
        }

        $this->insertTestData($db);
    }

    private function insertTestData(PDO $db): void
    {
        // Domain
        $db->exec("INSERT INTO test_rcl_domains (id, name) VALUES (1, 'example.com')");

        // Records (IDs 1-5)
        $stmt = $db->prepare("INSERT INTO test_rcl_records (domain_id, name, type, content) VALUES (?, ?, ?, ?)");
        $stmt->execute([1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 3600 900 604800 86400']);
        $stmt->execute([1, 'example.com', 'A', '192.0.2.1']);
        $stmt->execute([1, 'www.example.com', 'A', '192.0.2.2']);
        $stmt->execute([1, 'mail.example.com', 'A', '192.0.2.3']);
        $stmt->execute([1, 'example.com', 'MX', 'mail.example.com']);

        // RRset-based comment (legacy style - no link)
        // Comment for www.example.com A - matched by domain_id + name + type
        $db->exec("INSERT INTO test_rcl_comments (domain_id, name, type, comment)
                    VALUES (1, 'www.example.com', 'A', 'Legacy RRset comment')");

        // Per-record linked comment for mail.example.com A (record id=4)
        $db->exec("INSERT INTO test_rcl_comments (domain_id, name, type, comment)
                    VALUES (1, 'mail.example.com', 'A', 'Linked per-record comment')");
        // Link comment_id=2 to record_id=4
        $db->exec("INSERT INTO test_rcl_links (record_id, comment_id) VALUES (4, 2)");

        // Both linked AND RRset comment for example.com A (record id=2)
        // The linked one should win
        $db->exec("INSERT INTO test_rcl_comments (domain_id, name, type, comment)
                    VALUES (1, 'example.com', 'A', 'Unlinked RRset fallback')");
        $db->exec("INSERT INTO test_rcl_comments (domain_id, name, type, comment)
                    VALUES (1, 'example.com', 'A', 'Preferred linked comment')");
        // Link comment_id=4 to record_id=2
        $db->exec("INSERT INTO test_rcl_links (record_id, comment_id) VALUES (2, 4)");
    }

    // =========================================================================
    // Old pattern: correlated subquery with ORDER BY referencing outer table
    // This is the pattern that breaks on SQLite
    // =========================================================================

    private function executeOldPattern(PDO $db): array
    {
        $dbType = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $castId = DbCompat::castToString($dbType, 'test_rcl_records.id');
        $query = "SELECT test_rcl_records.*,
            (
                SELECT c.comment
                FROM test_rcl_comments c
                LEFT JOIN test_rcl_links rcl ON rcl.comment_id = c.id
                WHERE (rcl.record_id = $castId)
                   OR (rcl.record_id IS NULL
                       AND c.domain_id = test_rcl_records.domain_id
                       AND c.name = test_rcl_records.name
                       AND c.type = test_rcl_records.type)
                ORDER BY CASE WHEN rcl.record_id = $castId THEN 0 ELSE 1 END
                LIMIT 1
            ) AS comment
            FROM test_rcl_records
            WHERE test_rcl_records.domain_id = :domain_id
            AND test_rcl_records.type IS NOT NULL AND test_rcl_records.type != ''
            ORDER BY test_rcl_records.name ASC";

        $stmt = $db->prepare($query);
        $stmt->execute([':domain_id' => self::TEST_DOMAIN_ID]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // New pattern: COALESCE with two separate subqueries
    // This works on all databases including SQLite
    // =========================================================================

    private function executeNewPattern(PDO $db): array
    {
        $dbType = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $castId = DbCompat::castToString($dbType, 'test_rcl_records.id');
        $query = "SELECT test_rcl_records.*,
            COALESCE(
                (
                    SELECT c.comment
                    FROM test_rcl_links rcl
                    JOIN test_rcl_comments c ON c.id = rcl.comment_id
                    WHERE rcl.record_id = $castId
                    LIMIT 1
                ),
                (
                    SELECT c.comment
                    FROM test_rcl_comments c
                    WHERE c.domain_id = test_rcl_records.domain_id
                      AND c.name = test_rcl_records.name
                      AND c.type = test_rcl_records.type
                      AND NOT EXISTS (
                          SELECT 1 FROM test_rcl_links rcl2
                          WHERE rcl2.comment_id = c.id
                      )
                    LIMIT 1
                )
            ) AS comment
            FROM test_rcl_records
            WHERE test_rcl_records.domain_id = :domain_id
            AND test_rcl_records.type IS NOT NULL AND test_rcl_records.type != ''
            ORDER BY test_rcl_records.name ASC";

        $stmt = $db->prepare($query);
        $stmt->execute([':domain_id' => self::TEST_DOMAIN_ID]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Helper to build a map of record name+type -> comment for easier assertions.
     */
    private function buildCommentMap(array $results): array
    {
        $map = [];
        foreach ($results as $row) {
            $key = $row['name'] . '/' . $row['type'];
            $map[$key] = $row['comment'];
        }
        return $map;
    }

    // =========================================================================
    // SQLite tests
    // =========================================================================

    public function testSQLiteOldPatternFailsWithOuterTableReference(): void
    {
        // The old pattern references records.id in the ORDER BY of a correlated
        // subquery, which SQLite does not support.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/no such column/i');
        $this->executeOldPattern($this->sqliteConnection);
    }

    public function testSQLiteNewPatternWorks(): void
    {
        $results = $this->executeNewPattern($this->sqliteConnection);
        $this->assertCount(5, $results);
    }

    public function testSQLiteNewPatternReturnsLinkedComment(): void
    {
        $results = $this->executeNewPattern($this->sqliteConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testSQLiteNewPatternReturnsLegacyRRsetComment(): void
    {
        $results = $this->executeNewPattern($this->sqliteConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Legacy RRset comment', $map['www.example.com/A']);
    }

    public function testSQLiteNewPatternPrefersLinkedOverRRset(): void
    {
        // Record example.com/A has both a linked and an unlinked comment.
        // The COALESCE should return the linked one.
        $results = $this->executeNewPattern($this->sqliteConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Preferred linked comment', $map['example.com/A']);
    }

    public function testSQLiteNewPatternNullForRecordsWithoutComments(): void
    {
        $results = $this->executeNewPattern($this->sqliteConnection);
        $map = $this->buildCommentMap($results);

        $this->assertNull($map['example.com/SOA']);
        $this->assertNull($map['example.com/MX']);
    }

    // =========================================================================
    // MySQL tests
    // =========================================================================

    public function testMySQLOldPatternWorks(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeOldPattern($this->mysqlConnection);
        $this->assertCount(5, $results);
    }

    public function testMySQLNewPatternWorks(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeNewPattern($this->mysqlConnection);
        $this->assertCount(5, $results);
    }

    public function testMySQLNewPatternReturnsLinkedComment(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeNewPattern($this->mysqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testMySQLNewPatternReturnsLegacyRRsetComment(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeNewPattern($this->mysqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Legacy RRset comment', $map['www.example.com/A']);
    }

    public function testMySQLNewPatternPrefersLinkedOverRRset(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeNewPattern($this->mysqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Preferred linked comment', $map['example.com/A']);
    }

    public function testMySQLOldAndNewPatternsReturnSameComments(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $oldMap = $this->buildCommentMap($this->executeOldPattern($this->mysqlConnection));
        $newMap = $this->buildCommentMap($this->executeNewPattern($this->mysqlConnection));

        $this->assertEquals($oldMap, $newMap, 'New COALESCE pattern should return identical comments as old pattern');
    }

    // =========================================================================
    // PostgreSQL tests
    // =========================================================================

    public function testPgSQLOldPatternWorks(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeOldPattern($this->pgsqlConnection);
        $this->assertCount(5, $results);
    }

    public function testPgSQLNewPatternWorks(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeNewPattern($this->pgsqlConnection);
        $this->assertCount(5, $results);
    }

    public function testPgSQLNewPatternReturnsLinkedComment(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeNewPattern($this->pgsqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testPgSQLNewPatternReturnsLegacyRRsetComment(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeNewPattern($this->pgsqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Legacy RRset comment', $map['www.example.com/A']);
    }

    public function testPgSQLNewPatternPrefersLinkedOverRRset(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeNewPattern($this->pgsqlConnection);
        $map = $this->buildCommentMap($results);

        $this->assertEquals('Preferred linked comment', $map['example.com/A']);
    }

    public function testPgSQLOldAndNewPatternsReturnSameComments(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $oldMap = $this->buildCommentMap($this->executeOldPattern($this->pgsqlConnection));
        $newMap = $this->buildCommentMap($this->executeNewPattern($this->pgsqlConnection));

        $this->assertEquals($oldMap, $newMap, 'New COALESCE pattern should return identical comments as old pattern');
    }

    // =========================================================================
    // Cross-database consistency
    // =========================================================================

    public function testAllDatabasesReturnSameCommentsWithNewPattern(): void
    {
        $sqliteMap = $this->buildCommentMap($this->executeNewPattern($this->sqliteConnection));

        if ($this->mysqlConnection) {
            $mysqlMap = $this->buildCommentMap($this->executeNewPattern($this->mysqlConnection));
            $this->assertEquals($sqliteMap, $mysqlMap, 'MySQL and SQLite should return identical comments');
        }

        if ($this->pgsqlConnection) {
            $pgsqlMap = $this->buildCommentMap($this->executeNewPattern($this->pgsqlConnection));
            $this->assertEquals($sqliteMap, $pgsqlMap, 'PostgreSQL and SQLite should return identical comments');
        }
    }

    // =========================================================================
    // Search query pattern (RecordSearch)
    // =========================================================================

    private function executeSearchPattern(PDO $db, string $searchTerm): array
    {
        $dbType = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $castId = DbCompat::castToString($dbType, 'test_rcl_records.id');
        $query = "SELECT test_rcl_records.id, test_rcl_records.domain_id,
                 test_rcl_records.name, test_rcl_records.type,
                 test_rcl_records.content, test_rcl_records.ttl,
                 test_rcl_records.prio, test_rcl_records.disabled,
                 COALESCE(
                    (
                        SELECT c.comment
                        FROM test_rcl_links rcl
                        JOIN test_rcl_comments c ON c.id = rcl.comment_id
                        WHERE rcl.record_id = $castId
                        LIMIT 1
                    ),
                    (
                        SELECT c.comment
                        FROM test_rcl_comments c
                        WHERE c.domain_id = test_rcl_records.domain_id
                          AND c.name = test_rcl_records.name
                          AND c.type = test_rcl_records.type
                          AND NOT EXISTS (
                              SELECT 1 FROM test_rcl_links rcl2
                              WHERE rcl2.comment_id = c.id
                          )
                        LIMIT 1
                    )
                ) AS comment
            FROM test_rcl_records
            WHERE test_rcl_records.domain_id = :domain_id
              AND test_rcl_records.type IS NOT NULL AND test_rcl_records.type != ''
              AND (test_rcl_records.name LIKE :search OR test_rcl_records.content LIKE :search2)
            ORDER BY test_rcl_records.name ASC
            LIMIT 100 OFFSET 0";

        $stmt = $db->prepare($query);
        $stmt->execute([
            ':domain_id' => self::TEST_DOMAIN_ID,
            ':search' => '%' . $searchTerm . '%',
            ':search2' => '%' . $searchTerm . '%',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testSQLiteSearchPatternWithComments(): void
    {
        // "mail" matches mail.example.com A (name) and MX record (content contains "mail")
        $results = $this->executeSearchPattern($this->sqliteConnection, 'mail');
        $this->assertCount(2, $results);
        $map = $this->buildCommentMap($results);
        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testMySQLSearchPatternWithComments(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $results = $this->executeSearchPattern($this->mysqlConnection, 'mail');
        $this->assertCount(2, $results);
        $map = $this->buildCommentMap($results);
        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testPgSQLSearchPatternWithComments(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $results = $this->executeSearchPattern($this->pgsqlConnection, 'mail');
        $this->assertCount(2, $results);
        $map = $this->buildCommentMap($results);
        $this->assertEquals('Linked per-record comment', $map['mail.example.com/A']);
    }

    public function testSearchPatternReturnsConsistentResultsAcrossDatabases(): void
    {
        $sqliteResults = $this->executeSearchPattern($this->sqliteConnection, 'example');
        $sqliteMap = $this->buildCommentMap($sqliteResults);

        if ($this->mysqlConnection) {
            $mysqlMap = $this->buildCommentMap($this->executeSearchPattern($this->mysqlConnection, 'example'));
            $this->assertEquals($sqliteMap, $mysqlMap, 'Search results should match between MySQL and SQLite');
        }

        if ($this->pgsqlConnection) {
            $pgsqlMap = $this->buildCommentMap($this->executeSearchPattern($this->pgsqlConnection, 'example'));
            $this->assertEquals($sqliteMap, $pgsqlMap, 'Search results should match between PostgreSQL and SQLite');
        }
    }
}
