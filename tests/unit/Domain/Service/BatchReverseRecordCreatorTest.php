<?php

namespace Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class BatchReverseRecordCreatorTest extends TestCase
{
    private function createService(
        ?DnsRecord $dnsRecord = null,
        ?ConfigurationManager $config = null,
        ?RecordRepository $recordRepository = null
    ): BatchReverseRecordCreator {
        $db = $this->createMock(PDOCommon::class);
        $logger = $this->createMock(LegacyLogger::class);

        if ($config === null) {
            $config = $this->createMock(ConfigurationManager::class);
            $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'interface' && $key === 'add_reverse_record') {
                    return true;
                }
                if ($group === 'dns' && $key === 'prevent_duplicate_ptr') {
                    return true;
                }
                return $default;
            });
        }

        if ($dnsRecord === null) {
            $dnsRecord = $this->createMock(DnsRecord::class);
        }

        $ipValidator = new IPAddressValidator();

        return new BatchReverseRecordCreator($db, $config, $logger, $dnsRecord, $ipValidator, $recordRepository);
    }

    public function testCreateIPv6NetworkGeneratesCorrectPtrNames(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);

        $createdRecords = [];

        $dnsRecord->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $dnsRecord->method('addRecord')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$createdRecords) {
                $createdRecords[] = ['name' => $name, 'content' => $content];
                return true;
            });

        $recordRepo = $this->createMock(RecordRepository::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($dnsRecord, null, $recordRepo);

        $result = $service->createIPv6Network(
            '2001:db8:1:1',
            'host-',
            'example.com',
            '1',
            3600,
            0,
            '',
            '',
            5,
            false
        );

        $this->assertTrue($result['success']);

        // Verify nibble expansion is correct for ::1
        $firstRecord = $createdRecords[0];
        $this->assertEquals(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $firstRecord['name']
        );
        $this->assertEquals('host-1.example.com', $firstRecord['content']);

        // Verify multi-digit hex (::ff at index 255 would be tested with count 256+)
        // For now, verify ::2 has correct expansion
        $secondRecord = $createdRecords[1];
        $this->assertEquals(
            '2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $secondRecord['name']
        );
    }

    public function testCreateIPv6NetworkRejectsInvalidPrefix(): void
    {
        $service = $this->createService();

        $result = $service->createIPv6Network(
            'invalid-prefix',
            '',
            'example.com',
            '1',
            3600
        );

        $this->assertFalse($result['success']);
    }

    public function testCreateIPv6NetworkReturnsErrorWhenNoReverseZone(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')
            ->willReturn(-1);

        $service = $this->createService($dnsRecord);

        $result = $service->createIPv6Network(
            '2001:db8:1:1',
            '',
            'example.com',
            '1',
            3600
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('reverse zone', $result['message']);
    }

    public function testCreateIPv6NetworkSkipsNetworkAddress(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $addedNames = [];
        $dnsRecord->method('addRecord')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$addedNames) {
                $addedNames[] = $name;
                return true;
            });

        $recordRepo = $this->createMock(RecordRepository::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($dnsRecord, null, $recordRepo);

        $result = $service->createIPv6Network(
            '2001:db8:1:1',
            '',
            'example.com',
            '1',
            3600,
            0,
            '',
            '',
            3,
            false
        );

        $this->assertTrue($result['success']);
        // Count 3 means indices 0,1,2 - but 0 is skipped, so 2 records created
        $this->assertCount(2, $addedNames);

        // Verify ::0 (network address) PTR is NOT in the created records
        $networkPtr = '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $this->assertNotContains($networkPtr, $addedNames);
    }

    public function testCreateIPv6NetworkRespectsCountLimit(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $addCount = 0;
        $dnsRecord->method('addRecord')
            ->willReturnCallback(function () use (&$addCount) {
                $addCount++;
                return true;
            });

        $recordRepo = $this->createMock(RecordRepository::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($dnsRecord, null, $recordRepo);

        $result = $service->createIPv6Network(
            '2001:db8:1:1',
            '',
            'example.com',
            '1',
            3600,
            0,
            '',
            '',
            5000, // Exceeds 1000 limit
            false
        );

        $this->assertTrue($result['success']);
        // Should be capped at 1000 minus 1 (skipped network address) = 999
        $this->assertEquals(999, $addCount);
    }
}
