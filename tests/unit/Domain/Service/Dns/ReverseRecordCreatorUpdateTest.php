<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Port\RecordReadBackendInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Application\Service\AuditService;

class ReverseRecordCreatorUpdateTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>>|null $deleteLookupRows PTR records the backend
     *        answers to the lookup inside deleteReverseRecord. Null means the test does not
     *        expect deleteReverseRecord to run at all.
     */
    private function createService(?DomainRepositoryInterface $domainRepository = null, ?RecordManagerInterface $recordManager = null, ?array $deleteLookupRows = null): ReverseRecordCreator
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        if ($deleteLookupRows === null) {
            $backend->expects($this->never())->method('findRecordsByName');
        } else {
            $backend->method('findRecordsByName')->willReturn($deleteLookupRows);
        }

        $audit = $this->createMock(AuditService::class);
        $domainRepository ??= $this->createMock(DomainRepositoryInterface::class);
        $recordManager ??= $this->createMock(RecordManagerInterface::class);

        return new ReverseRecordCreator(true, $audit, $domainRepository, $recordManager, $backend);
    }

    public function testUpdateReverseRecordSkipsWhenBothTypesAreNonAddress(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->expects($this->never())->method('getBestMatchingZoneIdFromName');
        $recordManager->expects($this->never())->method('addRecord');
        $recordManager->expects($this->never())->method('deleteRecord');

        $service = $this->createService($domainRepository, $recordManager);

        $result = $service->updateReverseRecord(
            'CNAME',
            'old.example.com',
            'alias.example.com',
            'CNAME',
            'new.example.com',
            'alias.example.com',
            1,
            3600,
            0
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('not A or AAAA', $result['message']);
    }

    public function testUpdateReverseRecordReSyncsPtrEvenWhenAddressUnchanged(): void
    {
        // TTL/priority-only edits must propagate to the PTR, so the service
        // always runs delete-then-recreate when newIsAddress is true.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->expects($this->atLeastOnce())->method('getBestMatchingZoneIdFromName')->willReturn(-1);
        $recordManager->expects($this->never())->method('addRecord');

        $service = $this->createService($domainRepository, $recordManager, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'A',
            '192.0.2.10',
            'host.example.com',
            1,
            7200,
            0
        );

        // With no reverse zone in the mock, createReverseRecord returns an error.
        // The point of the assertion is that the delete-then-create path RAN
        // rather than being short-circuited.
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no matching reverse-zone', $result['message']);
    }

    public function testUpdateReverseRecordReportsMissingReverseZoneOnNewContent(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        // Simulate "no matching reverse zone" for the new IP.
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(-1);
        $recordManager->expects($this->never())->method('addRecord');

        // Old PTR lookup returns no rows - that's fine, delete is best-effort.
        $service = $this->createService($domainRepository, $recordManager, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'A',
            '198.51.100.20',
            'host.example.com',
            1,
            3600,
            0
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no matching reverse-zone', $result['message']);
    }

    public function testUpdateReverseRecordOnlyDeletesWhenNewTypeIsNotAddress(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->expects($this->never())->method('getBestMatchingZoneIdFromName');
        $recordManager->expects($this->never())->method('addRecord');

        // Old PTR lookup returns no rows - delete is best-effort.
        $service = $this->createService($domainRepository, $recordManager, []);

        $result = $service->updateReverseRecord(
            'A',
            '192.0.2.10',
            'host.example.com',
            'CNAME',
            'target.example.com',
            'host.example.com',
            1,
            3600,
            0
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('removed', strtolower($result['message']));
    }
}
