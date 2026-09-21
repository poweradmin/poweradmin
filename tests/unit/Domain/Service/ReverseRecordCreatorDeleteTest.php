<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\RecordReadBackendInterface;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Application\Service\AuditService;

/**
 * Direct coverage for the cascade-delete paths exercised when an A/AAAA
 * record is removed with the "also remove PTR" checkbox ticked
 * (and the inverse direction when a PTR is removed).
 */
class ReverseRecordCreatorDeleteTest extends TestCase
{
    private function createConfig(): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_reverse_record') {
                return true;
            }
            return $default;
        });
        return $config;
    }

    private function createService(?DomainRepositoryInterface $domainRepository, ?RecordManagerInterface $recordManager, ?RecordReadBackendInterface $backend = null): ReverseRecordCreator
    {
        return new ReverseRecordCreator(
            $this->createConfig(),
            $this->createMock(AuditService::class),
            $domainRepository ?? $this->createMock(DomainRepositoryInterface::class),
            $recordManager ?? $this->createMock(RecordManagerInterface::class),
            $backend ?? $this->createMock(RecordReadBackendInterface::class)
        );
    }

    public function testDeleteReverseRecordIgnoresNonAddressTypes(): void
    {
        // CNAME/MX/etc never have a reverse mapping, so the helper must short-circuit
        // before any DB lookup runs.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->expects($this->never())->method('findRecordsByName');

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('CNAME', 'other.example.com', 'alias.example.com'));
        $this->assertFalse($service->deleteReverseRecord('MX', 'mail.example.com', 'mx.example.com'));
        $this->assertFalse($service->deleteReverseRecord('TXT', 'v=spf1', 'spf.example.com'));
    }

    public function testDeleteReverseRecordReturnsFalseWhenNoPtrFound(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')->with('10.2.0.192.in-addr.arpa', 'PTR')->willReturn([]);
        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordDeletesMatchingPtr(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(7)
            ->willReturn(RecordWriteResult::ok());

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')
            ->with('10.2.0.192.in-addr.arpa', 'PTR')
            ->willReturn([
                ['id' => 6, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'other.example.com.'],
                ['id' => 7, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'host.example.com'],
            ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordMatchesTrailingDotContent(): void
    {
        // PowerDNS stores PTR content with a trailing dot - the matcher uses
        // str_starts_with("$name.") so "host.example.com." should still match.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(99)
            ->willReturn(RecordWriteResult::ok());

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')->willReturn([
            ['id' => 99, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'host.example.com.'],
        ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordReturnsFalseWhenPtrPointsElsewhere(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')->willReturn([
            ['id' => 1, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'unrelated.example.com.'],
        ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordSkipsRecordsWithoutId(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')->willReturn([
            ['name' => '10.2.0.192.in-addr.arpa', 'content' => 'host.example.com'],
        ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordHandlesAaaa(): void
    {
        $expectedReverse = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(123)
            ->willReturn(RecordWriteResult::ok());

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByName')
            ->with($expectedReverse, 'PTR')
            ->willReturn([
                ['id' => 123, 'name' => $expectedReverse, 'content' => 'host6.example.com'],
            ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('AAAA', '2001:db8::1', 'host6.example.com'));
    }

    public function testDeleteForwardRecordReturnsFalseForInvalidPtrName(): void
    {
        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->expects($this->never())->method('findRecordsByContent');
        $service = $this->createService(null, null, $backend);

        // Neither in-addr.arpa nor ip6.arpa: extractIpFromPtrName returns null.
        $this->assertFalse($service->deleteForwardRecord('host.example.com', 'something.example.com'));
        // Wrong octet count for in-addr.arpa.
        $this->assertFalse($service->deleteForwardRecord('1.2.in-addr.arpa', 'host.example.com'));
        // Wrong nibble count for ip6.arpa.
        $this->assertFalse($service->deleteForwardRecord('a.b.c.ip6.arpa', 'host6.example.com'));
    }

    public function testDeleteForwardRecordReturnsFalseWhenNoForwardRowFound(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByContent')->with('192.0.2.10', 'A')->willReturn([]);
        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com'));
    }

    public function testDeleteForwardRecordDeletesMatchingARecord(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(55)
            ->willReturn(RecordWriteResult::ok());

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByContent')
            ->with('192.0.2.10', 'A')
            ->willReturn([
                ['id' => 50, 'type' => 'A', 'name' => 'other.example.com', 'content' => '192.0.2.10', 'domain_id' => 1],
                ['id' => 55, 'type' => 'A', 'name' => 'host.example.com', 'content' => '192.0.2.10', 'domain_id' => 1],
            ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com.'));
    }

    public function testDeleteForwardRecordReturnsFalseWhenHostnameDiffers(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByContent')->willReturn([
            ['id' => 1, 'type' => 'A', 'name' => 'other.example.com', 'content' => '192.0.2.10', 'domain_id' => 1],
        ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com'));
    }

    public function testDeleteForwardRecordHandlesIpv6(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(77)
            ->willReturn(RecordWriteResult::ok());

        $backend = $this->createMock(RecordReadBackendInterface::class);
        $backend->method('findRecordsByContent')
            ->with('2001:0db8:0000:0000:0000:0000:0000:0001', 'AAAA')
            ->willReturn([
                ['id' => 77, 'type' => 'AAAA', 'name' => 'host6.example.com', 'content' => '2001:0db8:0000:0000:0000:0000:0000:0001', 'domain_id' => 9],
            ]);

        $service = $this->createService($domainRepository, $recordManager, $backend);

        $ptrName = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $this->assertTrue($service->deleteForwardRecord($ptrName, 'host6.example.com'));
    }
}
