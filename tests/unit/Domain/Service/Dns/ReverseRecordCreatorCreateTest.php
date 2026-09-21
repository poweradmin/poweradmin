<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Port\RecordReadBackendInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;

/**
 * Pins the duplicate check and the "PTR already exists" warning, which both
 * read the reverse zone through the record read port.
 */
class ReverseRecordCreatorCreateTest extends TestCase
{

    private function createDomainRepository(): DomainRepositoryInterface
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->with('10.2.0.192.in-addr.arpa')->willReturn(42);
        $domainRepository->method('getDomainNameById')->with(1)->willReturn('example.com');
        return $domainRepository;
    }

    public function testCreateReverseRecordRejectsWhenDisabled(): void
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->expects($this->never())->method('recordExists');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('addRecordGetId');

        $service = new ReverseRecordCreator(
            false,
            $this->createMock(AuditLoggerInterface::class),
            $this->createMock(DomainRepositoryInterface::class),
            $recordManager,
            $backend
        );

        $result = $service->createReverseRecord('host', 'A', '192.0.2.10', 1, 3600, 0);

        $this->assertFalse($result['success']);
    }

    public function testCreateReverseRecordRefusesIdenticalPtr(): void
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('recordExists')
            ->with(42, '10.2.0.192.in-addr.arpa', 'PTR', 'host.example.com')
            ->willReturn(true);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('addRecordGetId');

        $service = new ReverseRecordCreator(
            true,
            $this->createMock(AuditLoggerInterface::class),
            $this->createDomainRepository(),
            $recordManager,
            $backend
        );

        $result = $service->createReverseRecord('host', 'A', '192.0.2.10', 1, 3600, 0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['message']);
    }

    public function testCreateReverseRecordWarnsAboutOtherPtrsForSameAddress(): void
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('recordExists')->willReturn(false);
        $backend->method('getRecordsByZoneId')
            ->with(42, 'PTR')
            ->willReturn([
                ['id' => 5, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'other.example.com'],
                ['id' => 6, 'name' => '11.2.0.192.in-addr.arpa', 'content' => 'unrelated.example.com'],
            ]);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('addRecordGetId')
            ->with(42, '10.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 3600, 0)
            ->willReturn(RecordWriteResult::ok(7));
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())->method('logRecordAdd');

        $service = new ReverseRecordCreator(
            true,
            $audit,
            $this->createDomainRepository(),
            $recordManager,
            $backend
        );

        $result = $service->createReverseRecord('host', 'A', '192.0.2.10', 1, 3600, 0);

        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['type']);
        $this->assertStringContainsString('other.example.com', $result['message']);
        $this->assertStringNotContainsString('unrelated.example.com', $result['message']);
    }

    public function testCreateReverseRecordSucceedsWithoutExistingPtrs(): void
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('recordExists')->willReturn(false);
        $backend->method('getRecordsByZoneId')->willReturn([]);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(7));

        $service = new ReverseRecordCreator(
            true,
            $this->createMock(AuditLoggerInterface::class),
            $this->createDomainRepository(),
            $recordManager,
            $backend
        );

        $result = $service->createReverseRecord('host', 'A', '192.0.2.10', 1, 3600, 0);

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['type']);
    }

    public function testARefusedPtrWriteReportsTheRefusalReason(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $domains->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('recordExists')->willReturn(false);
        $backend->method('getRecordsByZoneId')->willReturn([]);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::forbidden('You do not have the permission to add a record to this zone.'));
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->never())->method('logRecordAdd');

        $service = new ReverseRecordCreator(true, $audit, $domains, $recordManager, $backend);
        $result = $service->createReverseRecord('host', 'A', '192.0.2.10', 1, 3600, 0);

        $this->assertFalse($result['success']);
        $this->assertSame('You do not have the permission to add a record to this zone.', $result['message']);
    }
}
