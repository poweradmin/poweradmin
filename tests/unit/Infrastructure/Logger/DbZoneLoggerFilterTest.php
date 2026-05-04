<?php

namespace unit\Infrastructure\Logger;

use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use ReflectionClass;

/**
 * Verifies that DbZoneLogger correctly applies the optional zone-id filter used
 * to scope audit log queries to zones a non-admin user owns.
 */
class DbZoneLoggerFilterTest extends TestCase
{
    private MockObject&PDO $db;

    protected function setUp(): void
    {
        $this->resetConfigurationManager([
            'database' => ['type' => 'mysql'],
            'logging' => ['database_enabled' => true],
            'interface' => [],
            'security' => [],
            'dns' => [],
            'mail' => [],
            'dnssec' => [],
            'pdns_api' => [],
            'ldap' => [],
            'misc' => [],
        ]);

        $this->db = $this->createMock(PDO::class);
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationManager(null);
    }

    public function testCountFilteredLogsWithEmptyZoneIdsReturnsZeroWithoutQuerying(): void
    {
        $this->db->expects($this->never())->method('prepare');

        $logger = new DbZoneLogger($this->db);

        $this->assertSame(0, $logger->countFilteredLogs([], []));
    }

    public function testGetFilteredLogsWithEmptyZoneIdsReturnsEmptyArrayWithoutQuerying(): void
    {
        $this->db->expects($this->never())->method('prepare');

        $logger = new DbZoneLogger($this->db);

        $this->assertSame([], $logger->getFilteredLogs([], 50, 0, []));
    }

    public function testCountFilteredLogsWithNullZoneIdsAppliesNoOwnerFilter(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['number_of_logs' => 0]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $logger = new DbZoneLogger($this->db);
        $logger->countFilteredLogs([], null);

        $this->assertNotNull($capturedSql);
        $this->assertStringNotContainsString('zone_id IN', $capturedSql);
        $this->assertStringNotContainsString(':zone_owner_id_', $capturedSql);
    }

    public function testCountFilteredLogsWithZoneIdsAppendsInClause(): void
    {
        $capturedSql = null;
        $boundParams = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('bindValue')
            ->willReturnCallback(function ($key, $value, $type) use (&$boundParams) {
                $boundParams[] = ['key' => $key, 'value' => $value, 'type' => $type];
                return true;
            });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['number_of_logs' => 7]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $logger = new DbZoneLogger($this->db);
        $count = $logger->countFilteredLogs([], [10, 20, 30]);

        $this->assertSame(7, $count);
        $this->assertStringContainsString(
            'log_zones.zone_id IN (:zone_owner_id_0, :zone_owner_id_1, :zone_owner_id_2)',
            $capturedSql
        );

        $idBinds = array_values(array_filter(
            $boundParams,
            fn($b) => str_starts_with($b['key'], ':zone_owner_id_')
        ));
        $this->assertCount(3, $idBinds);
        $this->assertSame(10, $idBinds[0]['value']);
        $this->assertSame(PDO::PARAM_INT, $idBinds[0]['type']);
        $this->assertSame(20, $idBinds[1]['value']);
        $this->assertSame(30, $idBinds[2]['value']);
    }

    public function testGetFilteredLogsWithZoneIdsAppendsInClauseAndBindsLimitOffset(): void
    {
        $capturedSql = null;
        $boundParams = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('bindValue')
            ->willReturnCallback(function ($key, $value, $type) use (&$boundParams) {
                $boundParams[] = ['key' => $key, 'value' => $value, 'type' => $type];
                return true;
            });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $logger = new DbZoneLogger($this->db);
        $logger->getFilteredLogs([], 25, 50, [11, 22]);

        $this->assertStringContainsString(
            'log_zones.zone_id IN (:zone_owner_id_0, :zone_owner_id_1)',
            $capturedSql
        );
        $this->assertStringContainsString('LIMIT :limit OFFSET :offset', $capturedSql);

        $keys = array_column($boundParams, 'key');
        $this->assertContains(':limit', $keys);
        $this->assertContains(':offset', $keys);
        $this->assertContains(':zone_owner_id_0', $keys);
        $this->assertContains(':zone_owner_id_1', $keys);
    }

    public function testZoneIdFilterCombinesWithNameAndDateFilters(): void
    {
        $capturedSql = null;
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['number_of_logs' => 0]);

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $logger = new DbZoneLogger($this->db);
        $logger->countFilteredLogs(
            ['name' => 'example.com', 'date_from' => '2026-01-01'],
            [42]
        );

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('INNER JOIN', $capturedSql);
        $this->assertStringContainsString('domains.name LIKE :search_by', $capturedSql);
        $this->assertStringContainsString('log_zones.created_at >= :date_from', $capturedSql);
        $this->assertStringContainsString('log_zones.zone_id IN (:zone_owner_id_0)', $capturedSql);
        $this->assertSame(2, substr_count($capturedSql, ' AND '));
    }

    public function testGetDistinctUsersForZonesWithEmptyArrayReturnsEmpty(): void
    {
        $this->db->expects($this->never())->method('prepare');

        $logger = new DbZoneLogger($this->db);

        $this->assertSame([], $logger->getDistinctUsersForZones([]));
    }

    public function testGetDistinctUsersForZonesParameterizesZoneIds(): void
    {
        $capturedSql = null;
        $boundParams = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('bindValue')
            ->willReturnCallback(function ($key, $value, $type) use (&$boundParams) {
                $boundParams[] = ['key' => $key, 'value' => $value, 'type' => $type];
                return true;
            });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['alice', 'bob']);

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $logger = new DbZoneLogger($this->db);
        $users = $logger->getDistinctUsersForZones([5, 9]);

        $this->assertSame(['alice', 'bob'], $users);
        $this->assertStringContainsString('lz.zone_id IN (:z0, :z1)', $capturedSql);
        $this->assertStringContainsString('CONCAT(', $capturedSql);

        $idBinds = array_values(array_filter(
            $boundParams,
            fn($b) => in_array($b['key'], [':z0', ':z1'], true)
        ));
        $this->assertCount(2, $idBinds);
        $this->assertSame(5, $idBinds[0]['value']);
        $this->assertSame(PDO::PARAM_INT, $idBinds[0]['type']);
        $this->assertSame(9, $idBinds[1]['value']);
    }

    /**
     * @param array<string, array<string, mixed>>|null $settings
     */
    private function resetConfigurationManager(?array $settings): void
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);

        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        if ($settings === null) {
            return;
        }

        $configManager = ConfigurationManager::getInstance();

        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $settings);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }
}
