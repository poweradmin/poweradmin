<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use PDO;
use PDOStatement;

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

    private function createSqlService(?DomainRepositoryInterface $domainRepository, ?RecordManagerInterface $recordManager, ?array $lookupRow): ReverseRecordCreator
    {
        $db = $this->createMock(PDO::class);

        if ($lookupRow !== null) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn($lookupRow);
            $db->method('prepare')->willReturn($stmt);
        }

        return new ReverseRecordCreator(
            $db,
            $this->createConfig(),
            $this->createMock(LegacyLogger::class),
            $domainRepository ?? $this->createMock(DomainRepositoryInterface::class),
            $recordManager ?? $this->createMock(RecordManagerInterface::class)
        );
    }

    private function createApiService(DomainRepositoryInterface $domainRepository, RecordManagerInterface $recordManager, DnsBackendProvider $backendProvider): ReverseRecordCreator
    {
        return new ReverseRecordCreator(
            $this->createMock(PDO::class),
            $this->createConfig(),
            $this->createMock(LegacyLogger::class),
            $domainRepository,
            $recordManager,
            null,
            $backendProvider
        );
    }

    public function testDeleteReverseRecordIgnoresNonAddressTypes(): void
    {
        // CNAME/MX/etc never have a reverse mapping, so the helper must short-circuit
        // before any DB lookup runs.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $service = $this->createSqlService($domainRepository, $recordManager, null);

        $this->assertFalse($service->deleteReverseRecord('CNAME', 'other.example.com', 'alias.example.com'));
        $this->assertFalse($service->deleteReverseRecord('MX', 'mail.example.com', 'mx.example.com'));
        $this->assertFalse($service->deleteReverseRecord('TXT', 'v=spf1', 'spf.example.com'));
    }

    public function testDeleteReverseRecordReturnsFalseWhenNoPtrFound(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        // Empty fetch() result simulates "no PTR row matched".
        $service = $this->createSqlService($domainRepository, $recordManager, []);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordViaApiBackendDeletesMatchingPtr(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->with('10.2.0.192.in-addr.arpa')
            ->willReturn(42);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(7)
            ->willReturn(true);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getRecordsByZoneId')
            ->with(42, 'PTR')
            ->willReturn([
                ['id' => 6, 'name' => '11.2.0.192.in-addr.arpa', 'content' => 'other.example.com.'],
                ['id' => 7, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'host.example.com'],
            ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordViaApiBackendMatchesTrailingDotContent(): void
    {
        // PowerDNS stores PTR content with a trailing dot - the matcher uses
        // str_starts_with("$name.") so "host.example.com." should still match.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(99)
            ->willReturn(true);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getRecordsByZoneId')->willReturn([
            ['id' => 99, 'name' => '10.2.0.192.in-addr.arpa', 'content' => 'host.example.com.'],
        ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordViaApiBackendReturnsFalseWhenNoMatch(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getRecordsByZoneId')->willReturn([
            ['id' => 1, 'name' => '99.2.0.192.in-addr.arpa', 'content' => 'unrelated.example.com.'],
        ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordViaApiBackendReturnsFalseWhenReverseZoneMissing(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(-1);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->expects($this->never())->method('getRecordsByZoneId');

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteReverseRecord('A', '192.0.2.10', 'host.example.com'));
    }

    public function testDeleteReverseRecordViaApiBackendHandlesAaaa(): void
    {
        $expectedReverse = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->with($expectedReverse)
            ->willReturn(7);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(123)
            ->willReturn(true);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getRecordsByZoneId')->willReturn([
            ['id' => 123, 'name' => $expectedReverse, 'content' => 'host6.example.com'],
        ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteReverseRecord('AAAA', '2001:db8::1', 'host6.example.com'));
    }

    public function testDeleteForwardRecordReturnsFalseForInvalidPtrName(): void
    {
        $service = $this->createSqlService(null, null, null);

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

        $service = $this->createSqlService($domainRepository, $recordManager, []);

        $this->assertFalse($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com'));
    }

    public function testDeleteForwardRecordViaApiBackendDeletesMatchingARecord(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(55)
            ->willReturn(true);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')
            ->with('host.example.com', 'record', 100)
            ->willReturn([
                'records' => [
                    ['id' => 50, 'type' => 'A', 'name' => 'host.example.com', 'content' => '192.0.2.99', 'domain_id' => 1],
                    ['id' => 55, 'type' => 'A', 'name' => 'host.example.com', 'content' => '192.0.2.10', 'domain_id' => 1],
                ],
            ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertTrue($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com.'));
    }

    public function testDeleteForwardRecordViaApiBackendReturnsFalseWhenNoMatch(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('deleteRecord');

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')->willReturn([
            'records' => [
                ['id' => 1, 'type' => 'A', 'name' => 'host.example.com', 'content' => '192.0.2.99', 'domain_id' => 1],
            ],
        ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $this->assertFalse($service->deleteForwardRecord('10.2.0.192.in-addr.arpa', 'host.example.com'));
    }

    public function testDeleteForwardRecordViaApiBackendHandlesIpv6(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->once())
            ->method('deleteRecord')
            ->with(77)
            ->willReturn(true);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')->willReturn([
            'records' => [
                ['id' => 77, 'type' => 'AAAA', 'name' => 'host6.example.com', 'content' => '2001:0db8:0000:0000:0000:0000:0000:0001', 'domain_id' => 9],
            ],
        ]);

        $service = $this->createApiService($domainRepository, $recordManager, $backend);

        $ptrName = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $this->assertTrue($service->deleteForwardRecord($ptrName, 'host6.example.com'));
    }
}
