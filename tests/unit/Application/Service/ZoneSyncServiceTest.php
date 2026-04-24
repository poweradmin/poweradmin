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

    public function testSyncSkipsRemovalWhenApiReturnsEmptyButLocalZonesExist(): void
    {
        // API returns empty (could be an outage - getZones() catches ApiErrorException)
        $this->mockBackend->method('getZones')->willReturn([]);

        // But we have local zones
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            ['id' => 2, 'zone_name' => 'other.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        // Should NOT call prepare for DELETE
        $this->mockDb->expects($this->never())->method('prepare');

        $result = $this->service->sync();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
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

    /**
     * When the backend's getZones() records an API error (it swallows the
     * exception and returns []), sync() must surface that as an exception so
     * callers don't interpret "0 added, 0 updated, 0 removed" as success.
     */
    public function testSyncThrowsWhenApiFailedDuringThisCall(): void
    {
        $_SESSION = [];

        // Simulate the backend recording an error mid-call, which is what
        // ApiDnsBackendProvider::getZones() does when it catches ApiErrorException.
        $this->mockBackend->method('getZones')->willReturnCallback(function () {
            (new \Poweradmin\Application\Service\ApiStatusService())
                ->recordError('An API request failed', ['endpoint' => 'zones', 'http_code' => 500]);
            return [];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PowerDNS API unreachable/');
        $this->service->sync();
    }

    /**
     * A stale error from a previous request (e.g. a failed getZones on the
     * prior page load) must not make a fresh successful sync look broken.
     */
    public function testSyncIgnoresStaleApiStatusError(): void
    {
        $_SESSION = [];
        // Previous error recorded well before this sync starts.
        $_SESSION['pdns_api_last_error'] = [
            'message' => 'old boom',
            'context' => ['endpoint' => 'zones', 'http_code' => 500],
            'timestamp' => time() - 60,
        ];

        $this->mockBackend->method('getZones')->willReturn([]);

        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturn(false);
        $this->mockDb->method('query')->willReturn($localStmt);

        $result = $this->service->sync();
        $this->assertSame(['added' => 0, 'removed' => 0, 'updated' => 0], $result);
    }

    /**
     * The outage guard path (API returned [] with non-empty local) must not
     * advance the stale-sync throttle, so syncIfStale() keeps retrying on
     * subsequent requests if PowerDNS recovers quickly.
     */
    public function testSyncDoesNotRecordLastSyncOnEmptyApiGuard(): void
    {
        $_SESSION = [];
        $this->mockBackend->method('getZones')->willReturn([]);

        // Local has zones.
        $localStmt = $this->createMock(PDOStatement::class);
        $localStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => null],
            false
        );
        $this->mockDb->method('query')->willReturn($localStmt);

        $result = $this->service->sync();

        $this->assertSame(['added' => 0, 'removed' => 0, 'updated' => 0], $result);
        $this->assertArrayNotHasKey('zone_sync_last', $_SESSION);
    }
}
