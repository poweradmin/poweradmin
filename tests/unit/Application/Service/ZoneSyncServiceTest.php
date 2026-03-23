<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ZoneSyncService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Psr\Log\LoggerInterface;

#[CoversClass(ZoneSyncService::class)]
class ZoneSyncServiceTest extends TestCase
{
    private $mockDb;
    private $mockBackend;
    private $mockLogger;
    private ZoneSyncService $service;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockBackend = $this->createMock(DnsBackendProvider::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->service = new ZoneSyncService($this->mockDb, $this->mockBackend, 300, $this->mockLogger);
    }

    public function testSyncAddsZonesMissingLocally(): void
    {
        $this->mockBackend->method('getZones')->willReturn([
            ['name' => 'example.com', 'type' => 'NATIVE', 'master' => null],
        ]);

        // getLocalZones returns empty
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturn(false);
        $this->mockDb->method('query')->willReturn($localStmt);

        // INSERT + UPDATE for self-referencing domain_id
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('bindValue');
        $insertStmt->method('execute')->willReturn(true);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls($insertStmt, $updateStmt);
        $this->mockDb->method('lastInsertId')->willReturn('1');

        $result = $this->service->sync();

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['updated']);
    }

    public function testSyncRemovesOrphanedLocalZones(): void
    {
        $this->mockBackend->method('getZones')->willReturn([
            ['name' => 'kept.com', 'type' => 'NATIVE', 'master' => null],
        ]);

        // Local has two zones, one not in API
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'kept.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            ['id' => 2, 'zone_name' => 'orphan.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        // DELETE statements for orphaned zone
        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')->willReturn($deleteStmt);

        $result = $this->service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['updated']);
    }

    public function testSyncUpdatesChangedMetadata(): void
    {
        $this->mockBackend->method('getZones')->willReturn([
            ['name' => 'example.com', 'type' => 'MASTER', 'master' => null],
        ]);

        // Local has same zone but different type
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('bindValue');
        $updateStmt->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')->willReturn($updateStmt);

        $result = $this->service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['updated']);
    }

    public function testSyncSkipsWhenBothEmpty(): void
    {
        $this->mockBackend->method('getZones')->willReturn([]);

        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturn(false);
        $this->mockDb->method('query')->willReturn($localStmt);

        $result = $this->service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['updated']);
    }

    public function testSyncCleansUpLocalZonesWhenApiGenuinelyEmpty(): void
    {
        // API returns empty - all zones were deleted from PowerDNS
        $this->mockBackend->method('getZones')->willReturn([]);

        // Local still has zones
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            ['id' => 2, 'zone_name' => 'other.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);
        $this->mockDb->method('prepare')->willReturn($deleteStmt);

        $result = $this->service->sync();

        // Both local zones should be removed
        $this->assertSame(0, $result['added']);
        $this->assertSame(2, $result['removed']);
        $this->assertSame(0, $result['updated']);
    }

    public function testSyncNoChangesWhenInSync(): void
    {
        $this->mockBackend->method('getZones')->willReturn([
            ['name' => 'example.com', 'type' => 'NATIVE', 'master' => null],
        ]);

        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        $result = $this->service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['updated']);
    }

    public function testSyncIfStaleSkipsWhenRecent(): void
    {
        $_SESSION = [];
        $_SESSION['zone_sync_last'] = time();

        $service = new ZoneSyncService($this->mockDb, $this->mockBackend, 300, $this->mockLogger);

        // Backend should never be called
        $this->mockBackend->expects($this->never())->method('getZones');

        $result = $service->syncIfStale();
        $this->assertNull($result);
    }

    public function testSyncIfStaleRunsWhenExpired(): void
    {
        $_SESSION = [];
        $_SESSION['zone_sync_last'] = time() - 600;

        $this->mockBackend->method('getZones')->willReturn([]);

        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturn(false);
        $this->mockDb->method('query')->willReturn($localStmt);

        $service = new ZoneSyncService($this->mockDb, $this->mockBackend, 300, $this->mockLogger);
        $result = $service->syncIfStale();

        $this->assertIsArray($result);
    }

    public function testSyncIfStaleReturnsNullAndLogsOnException(): void
    {
        $_SESSION = [];

        $this->mockBackend->method('getZones')
            ->willThrowException(new \RuntimeException('API unreachable'));

        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Zone sync failed'),
                $this->callback(function (array $context) {
                    return $context['error'] === 'API unreachable'
                        && $context['exception'] instanceof \RuntimeException;
                })
            );

        $service = new ZoneSyncService($this->mockDb, $this->mockBackend, 0, $this->mockLogger);
        $result = $service->syncIfStale();

        $this->assertNull($result);
    }
}
