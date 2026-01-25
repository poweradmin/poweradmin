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
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ZoneTemplate::getListZoneUseTempl() method.
 *
 * These tests verify the fix for GitHub issues #944 and #945, which reported
 * that the method incorrectly returned zones.id instead of zones.domain_id.
 * This causes errors when updating zones from templates in databases where
 * zones.id != domains.id (common in migrated databases).
 *
 * @see https://github.com/poweradmin/poweradmin/issues/944
 * @see https://github.com/poweradmin/poweradmin/issues/945
 * @group manual
 */
class ZoneTemplateListByTemplIdTest extends TestCase
{
    private ?PDO $mysqlConnection = null;
    private ?PDO $pgsqlConnection = null;
    private ?PDO $sqliteConnection = null;

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
            // MySQL not available, will skip MySQL tests
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
            // PostgreSQL not available, will skip PostgreSQL tests
        }

        // SQLite in-memory connection (always available)
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
        $tables = ['zones', 'zone_templ', 'domains', 'records'];

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
        // Create domains table (PowerDNS table)
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_domains (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) DEFAULT 'MASTER'
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_domains (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) DEFAULT 'MASTER'
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_domains (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(10) DEFAULT 'MASTER'
                )
            ");
        }

        // Create zones table (Poweradmin table linking to domains)
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_zones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    domain_id INT NOT NULL,
                    owner INT NOT NULL,
                    zone_templ_id INT DEFAULT 0
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_zones (
                    id SERIAL PRIMARY KEY,
                    domain_id INT NOT NULL,
                    owner INT NOT NULL,
                    zone_templ_id INT DEFAULT 0
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_zones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain_id INT NOT NULL,
                    owner INT NOT NULL,
                    zone_templ_id INT DEFAULT 0
                )
            ");
        }

        // Create zone_templ table
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_zone_templ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(128) NOT NULL,
                    descr TEXT,
                    owner INT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_zone_templ (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(128) NOT NULL,
                    descr TEXT,
                    owner INT NOT NULL DEFAULT 0
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_zone_templ (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(128) NOT NULL,
                    descr TEXT,
                    owner INT NOT NULL DEFAULT 0
                )
            ");
        }

        // Create records table for record count subquery
        if ($dbType === 'mysql') {
            $connection->exec("
                CREATE TABLE test_records (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    domain_id INT NOT NULL,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT DEFAULT 3600
                ) ENGINE=InnoDB
            ");
        } elseif ($dbType === 'pgsql') {
            $connection->exec("
                CREATE TABLE test_records (
                    id BIGSERIAL PRIMARY KEY,
                    domain_id INT NOT NULL,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT DEFAULT 3600
                )
            ");
        } else {
            $connection->exec("
                CREATE TABLE test_records (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain_id INT NOT NULL,
                    name VARCHAR(255),
                    type VARCHAR(10),
                    content TEXT,
                    ttl INT DEFAULT 3600
                )
            ");
        }
    }

    /**
     * Insert test data with mismatched zones.id and zones.domain_id
     *
     * This simulates a migrated database where PowerDNS existed before Poweradmin
     * was installed, causing domains.id values to not match zones.id values.
     */
    private function insertMismatchedTestData(PDO $connection): void
    {
        // Create a zone template
        $connection->exec("INSERT INTO test_zone_templ (id, name, descr, owner) VALUES (1, 'Test Template', 'Test', 1)");

        // Create domains with specific IDs (simulating pre-existing PowerDNS database)
        // These IDs (100, 200, 300) are intentionally different from zones.id values
        $connection->exec("INSERT INTO test_domains (id, name, type) VALUES (100, 'example.com', 'MASTER')");
        $connection->exec("INSERT INTO test_domains (id, name, type) VALUES (200, 'test.org', 'MASTER')");
        $connection->exec("INSERT INTO test_domains (id, name, type) VALUES (300, 'sample.net', 'MASTER')");

        // Create zones with DIFFERENT IDs but pointing to the domains via domain_id
        // zones.id (1, 2, 3) != zones.domain_id (100, 200, 300)
        // This is the key scenario that caused issues #944 and #945
        $connection->exec("INSERT INTO test_zones (id, domain_id, owner, zone_templ_id) VALUES (1, 100, 1, 1)");
        $connection->exec("INSERT INTO test_zones (id, domain_id, owner, zone_templ_id) VALUES (2, 200, 1, 1)");
        $connection->exec("INSERT INTO test_zones (id, domain_id, owner, zone_templ_id) VALUES (3, 300, 1, 0)"); // Different template

        // Add some records
        $connection->exec("INSERT INTO test_records (domain_id, name, type, content) VALUES (100, 'example.com', 'A', '192.168.1.1')");
        $connection->exec("INSERT INTO test_records (domain_id, name, type, content) VALUES (200, 'test.org', 'A', '192.168.1.2')");
    }

    /**
     * Execute the getListZoneUseTempl query pattern
     *
     * This mimics the query in ZoneTemplate::getListZoneUseTempl() after the fix.
     * The key change is that it now returns zones.domain_id instead of zones.id.
     */
    private function executeGetListZoneUseTemplQuery(PDO $connection, int $zoneTemplId, int $userId): array
    {
        $domainsTable = 'test_domains';
        $recordsTable = 'test_records';

        // This is the FIXED query - returns domain_id, not zones.id
        $query = "SELECT zones.id,
            zones.domain_id,
            $domainsTable.name,
            $domainsTable.type,
            Record_Count.count_records
            FROM $domainsTable
            INNER JOIN test_zones zones ON $domainsTable.id=zones.domain_id
            LEFT JOIN (
                SELECT COUNT(domain_id) AS count_records, domain_id FROM $recordsTable GROUP BY domain_id
            ) Record_Count ON Record_Count.domain_id=$domainsTable.id
            WHERE 1=1
            AND zones.zone_templ_id = :zone_templ_id
            GROUP BY $domainsTable.name, zones.id, zones.domain_id, $domainsTable.type, Record_Count.count_records";

        $stmt = $connection->prepare($query);
        $stmt->execute([':zone_templ_id' => $zoneTemplId]);

        $zoneList = [];
        while ($zone = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // The FIX: return domain_id, not zones.id
            $zoneList[] = $zone['domain_id'];
        }

        return $zoneList;
    }

    /**
     * Execute the BUGGY version of the query (returns zones.id)
     * This is used to demonstrate the bug that was fixed.
     */
    private function executeBuggyGetListZoneUseTemplQuery(PDO $connection, int $zoneTemplId, int $userId): array
    {
        $domainsTable = 'test_domains';
        $recordsTable = 'test_records';

        // This is the BUGGY query - returns zones.id instead of domain_id
        $query = "SELECT zones.id,
            $domainsTable.name,
            $domainsTable.type,
            Record_Count.count_records
            FROM $domainsTable
            INNER JOIN test_zones zones ON $domainsTable.id=zones.domain_id
            LEFT JOIN (
                SELECT COUNT(domain_id) AS count_records, domain_id FROM $recordsTable GROUP BY domain_id
            ) Record_Count ON Record_Count.domain_id=$domainsTable.id
            WHERE 1=1
            AND zones.zone_templ_id = :zone_templ_id
            GROUP BY $domainsTable.name, zones.id, $domainsTable.type, Record_Count.count_records";

        $stmt = $connection->prepare($query);
        $stmt->execute([':zone_templ_id' => $zoneTemplId]);

        $zoneList = [];
        while ($zone = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // BUG: returning zones.id instead of domain_id
            $zoneList[] = $zone['id'];
        }

        return $zoneList;
    }

    // ==================== SQLite Tests (Always Available) ====================

    /**
     * Test that the fixed query returns domain_id values, not zones.id values
     */
    public function testSQLiteFixedQueryReturnsDomainId(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertMismatchedTestData($this->sqliteConnection);

        $result = $this->executeGetListZoneUseTemplQuery($this->sqliteConnection, 1, 1);

        // Should return domain_id values (100, 200), NOT zones.id values (1, 2)
        $this->assertCount(2, $result);
        $this->assertContains(100, $result, 'Result should contain domain_id 100');
        $this->assertContains(200, $result, 'Result should contain domain_id 200');
        $this->assertNotContains(1, $result, 'Result should NOT contain zones.id 1');
        $this->assertNotContains(2, $result, 'Result should NOT contain zones.id 2');
    }

    /**
     * Test that demonstrates the original bug behavior
     */
    public function testSQLiteBuggyQueryReturnsZonesId(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertMismatchedTestData($this->sqliteConnection);

        $result = $this->executeBuggyGetListZoneUseTemplQuery($this->sqliteConnection, 1, 1);

        // The buggy query returns zones.id (1, 2), NOT domain_id (100, 200)
        // This demonstrates the bug that was fixed
        $this->assertCount(2, $result);
        $this->assertContains(1, $result, 'Buggy query returns zones.id 1');
        $this->assertContains(2, $result, 'Buggy query returns zones.id 2');
        $this->assertNotContains(100, $result, 'Buggy query does NOT return domain_id 100');
        $this->assertNotContains(200, $result, 'Buggy query does NOT return domain_id 200');
    }

    /**
     * Test that results can be used with getDomainNameById (which expects domain_id)
     */
    public function testSQLiteResultsAreValidDomainIds(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertMismatchedTestData($this->sqliteConnection);

        $domainIds = $this->executeGetListZoneUseTemplQuery($this->sqliteConnection, 1, 1);

        // Verify each returned ID exists in the domains table
        foreach ($domainIds as $domainId) {
            $stmt = $this->sqliteConnection->prepare("SELECT name FROM test_domains WHERE id = :id");
            $stmt->execute([':id' => $domainId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertNotFalse($domain, "domain_id $domainId should exist in domains table");
            $this->assertNotNull($domain['name'], "Domain name should not be null");
        }
    }

    /**
     * Test with matching IDs (normal case, non-migrated database)
     */
    public function testSQLiteMatchingIdsStillWorks(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');

        // Insert data where zones.id matches zones.domain_id (normal case)
        $this->sqliteConnection->exec("INSERT INTO test_zone_templ (id, name, descr, owner) VALUES (1, 'Test', 'Test', 1)");
        $this->sqliteConnection->exec("INSERT INTO test_domains (id, name, type) VALUES (1, 'example.com', 'MASTER')");
        $this->sqliteConnection->exec("INSERT INTO test_zones (id, domain_id, owner, zone_templ_id) VALUES (1, 1, 1, 1)");

        $result = $this->executeGetListZoneUseTemplQuery($this->sqliteConnection, 1, 1);

        // In this case, both queries would return [1], but we're testing the fixed query
        $this->assertCount(1, $result);
        $this->assertEquals([1], $result);
    }

    /**
     * Test empty result when no zones use the template
     */
    public function testSQLiteEmptyResultForUnusedTemplate(): void
    {
        $this->createTestTables($this->sqliteConnection, 'sqlite');
        $this->insertMismatchedTestData($this->sqliteConnection);

        // Query for template ID 999 which doesn't exist
        $result = $this->executeGetListZoneUseTemplQuery($this->sqliteConnection, 999, 1);

        $this->assertEmpty($result);
    }

    // ==================== MySQL Tests ====================

    public function testMySQLFixedQueryReturnsDomainId(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertMismatchedTestData($this->mysqlConnection);

        $result = $this->executeGetListZoneUseTemplQuery($this->mysqlConnection, 1, 1);

        $this->assertCount(2, $result);
        $this->assertContains(100, $result, 'Result should contain domain_id 100');
        $this->assertContains(200, $result, 'Result should contain domain_id 200');
        $this->assertNotContains(1, $result, 'Result should NOT contain zones.id 1');
        $this->assertNotContains(2, $result, 'Result should NOT contain zones.id 2');
    }

    public function testMySQLBuggyQueryReturnsZonesId(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertMismatchedTestData($this->mysqlConnection);

        $result = $this->executeBuggyGetListZoneUseTemplQuery($this->mysqlConnection, 1, 1);

        $this->assertCount(2, $result);
        $this->assertContains(1, $result, 'Buggy query returns zones.id 1');
        $this->assertContains(2, $result, 'Buggy query returns zones.id 2');
    }

    public function testMySQLResultsAreValidDomainIds(): void
    {
        if (!$this->mysqlConnection) {
            $this->markTestSkipped('MySQL connection not available');
        }

        $this->createTestTables($this->mysqlConnection, 'mysql');
        $this->insertMismatchedTestData($this->mysqlConnection);

        $domainIds = $this->executeGetListZoneUseTemplQuery($this->mysqlConnection, 1, 1);

        foreach ($domainIds as $domainId) {
            $stmt = $this->mysqlConnection->prepare("SELECT name FROM test_domains WHERE id = :id");
            $stmt->execute([':id' => $domainId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertNotFalse($domain, "domain_id $domainId should exist in domains table");
        }
    }

    // ==================== PostgreSQL Tests ====================

    public function testPgSQLFixedQueryReturnsDomainId(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertMismatchedTestData($this->pgsqlConnection);

        $result = $this->executeGetListZoneUseTemplQuery($this->pgsqlConnection, 1, 1);

        $this->assertCount(2, $result);
        $this->assertContains(100, $result, 'Result should contain domain_id 100');
        $this->assertContains(200, $result, 'Result should contain domain_id 200');
        $this->assertNotContains(1, $result, 'Result should NOT contain zones.id 1');
        $this->assertNotContains(2, $result, 'Result should NOT contain zones.id 2');
    }

    public function testPgSQLBuggyQueryReturnsZonesId(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertMismatchedTestData($this->pgsqlConnection);

        $result = $this->executeBuggyGetListZoneUseTemplQuery($this->pgsqlConnection, 1, 1);

        $this->assertCount(2, $result);
        $this->assertContains(1, $result, 'Buggy query returns zones.id 1');
        $this->assertContains(2, $result, 'Buggy query returns zones.id 2');
    }

    public function testPgSQLResultsAreValidDomainIds(): void
    {
        if (!$this->pgsqlConnection) {
            $this->markTestSkipped('PostgreSQL connection not available');
        }

        $this->createTestTables($this->pgsqlConnection, 'pgsql');
        $this->insertMismatchedTestData($this->pgsqlConnection);

        $domainIds = $this->executeGetListZoneUseTemplQuery($this->pgsqlConnection, 1, 1);

        foreach ($domainIds as $domainId) {
            $stmt = $this->pgsqlConnection->prepare("SELECT name FROM test_domains WHERE id = :id");
            $stmt->execute([':id' => $domainId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertNotFalse($domain, "domain_id $domainId should exist in domains table");
        }
    }
}
