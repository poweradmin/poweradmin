<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class BatchReverseRecordCreatorTest extends TestCase
{
    private function createService(
        ?DnsRecord $dnsRecord = null,
        ?ConfigurationManager $config = null,
        ?RecordRepositoryInterface $recordRepository = null
    ): BatchReverseRecordCreator {
        $db = $this->createMock(PDO::class);
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

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
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

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
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

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
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

    public function testCreateIPv6NetworkMatchingModeCreatesPtrPerAaaaRecord(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $dnsRecord->method('getDomainIdByName')->willReturn(7);

        $created = [];
        $dnsRecord->method('addRecord')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$created) {
                $created[] = ['name' => $name, 'content' => $content, 'ttl' => $ttl];
                return true;
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        // One AAAA inside the /64, one outside - only the first should yield a PTR.
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
            ['name' => 'other.example.com', 'content' => '2001:db8:2:2::9', 'ttl' => 7200, 'prio' => 0],
        ]);

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
            256,
            false,
            null,
            true // onlyMatchingRecords
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $created);
        $this->assertEquals(
            '5.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $created[0]['name']
        );
        // PTR points back at the forward record's own hostname, not a generated host- name.
        $this->assertEquals('host5.example.com', $created[0]['content']);
    }

    public function testCreateIPv6NetworkMatchingModeReturnsErrorWhenNoMatches(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $dnsRecord->method('getDomainIdByName')->willReturn(7);

        $addCount = 0;
        $dnsRecord->method('addRecord')->willReturnCallback(function () use (&$addCount) {
            $addCount++;
            return true;
        });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        // AAAA exists but lives in a different /64, so nothing matches.
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'other.example.com', 'content' => '2001:db8:2:2::9', 'ttl' => 7200, 'prio' => 0],
        ]);

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
            256,
            false,
            null,
            true
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No AAAA records', $result['message']);
        $this->assertEquals(0, $addCount);
    }

    public function testCreateIPv6NetworkMatchingModeHonorsPtrTtlOverride(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $dnsRecord->method('getDomainIdByName')->willReturn(7);

        $ttls = [];
        $dnsRecord->method('addRecord')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$ttls) {
                $ttls[] = $ttl;
                return true;
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($dnsRecord, null, $recordRepo);

        $args = ['2001:db8:1:1', '', 'example.com', '1', 3600, 0, '', '', 256, false, null, true];

        // With an explicit matchingPtrTtl, the PTR uses it instead of the AAAA's TTL.
        $override = $service->createIPv6Network(...array_merge($args, [1800]));
        $this->assertTrue($override['success']);

        // With null, it falls back to the matched record's own TTL.
        $fallback = $service->createIPv6Network(...array_merge($args, [null]));
        $this->assertTrue($fallback['success']);

        $this->assertSame([1800, 7200], $ttls);
    }

    public function testCreateIPv6NetworkMatchingModeSkipsDuplicatePtr(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $dnsRecord->method('getDomainIdByName')->willReturn(7);

        $addCount = 0;
        $dnsRecord->method('addRecord')->willReturnCallback(function () use (&$addCount) {
            $addCount++;
            return true;
        });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(true); // a PTR already exists
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
        ]);

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
            256,
            false,
            null,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $addCount);
        $this->assertStringContainsString('skipped', $result['message']);
    }
}
