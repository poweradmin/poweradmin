<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use Closure;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\DnssecProviderInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Application\Service\AuditService;

class BatchReverseRecordCreatorTest extends TestCase
{
    private function createService(
        ?DomainRepositoryInterface $domainRepository = null,
        ?RecordManagerInterface $recordManager = null,
        ?ConfigurationManager $config = null,
        ?RecordRepositoryInterface $recordRepository = null,
        ?Closure $dnssecProvider = null
    ): BatchReverseRecordCreator {
        $audit = $this->createMock(AuditService::class);

        if ($config === null) {
            $config = $this->createMock(ConfigurationManager::class);
            $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'interface' && $key === 'add_reverse_record') {
                    return RecordWriteResult::ok(1);
                }
                if ($group === 'dns' && $key === 'prevent_duplicate_ptr') {
                    return RecordWriteResult::ok(1);
                }
                return $default;
            });
        }

        if ($domainRepository === null) {
            $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        }

        if ($recordManager === null) {
            $recordManager = $this->createMock(RecordManagerInterface::class);
        }

        $ipValidator = new IPAddressValidator();

        $dnssecProvider ??= fn() => $this->createMock(DnssecProviderInterface::class);

        return new BatchReverseRecordCreator($config, $audit, $domainRepository, $recordRepository ?? $this->createMock(RecordRepositoryInterface::class), $recordManager, $dnssecProvider, $ipValidator);
    }

    private function dnssecConfig(bool $enabled): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) use ($enabled) {
            if ($group === 'dnssec' && $key === 'enabled') {
                return $enabled;
            }
            if ($group === 'interface' && $key === 'add_reverse_record') {
                return RecordWriteResult::ok(1);
            }
            return $default;
        });
        return $config;
    }

    private function createDnssecService(bool $enabled, int &$builds): BatchReverseRecordCreator
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainNameById')->willReturn('1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa');

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $provider = $this->createMock(DnssecProviderInterface::class);
        $provider->expects($enabled ? $this->exactly(2) : $this->never())
            ->method('rectifyZone')
            ->with('1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa')
            ->willReturn(true);

        $builds = 0;
        $closure = function () use ($provider, &$builds): DnssecProviderInterface {
            $builds++;
            return $provider;
        };

        return $this->createService($domainRepository, $recordManager, $this->dnssecConfig($enabled), $recordRepo, $closure);
    }

    public function testRectifiesEachPtrThroughOneLazilyBuiltProviderWhenDnssecEnabled(): void
    {
        $builds = 0;
        $service = $this->createDnssecService(true, $builds);

        $result = $service->createIPv6Network('2001:db8:1:1', 'host-', 'example.com', '1', 3600, 0, '', '', 3, false);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $builds, 'two PTRs (the network address is skipped) share one provider build');
    }

    public function testNeverBuildsProviderWhenDnssecDisabled(): void
    {
        $builds = 0;
        $service = $this->createDnssecService(false, $builds);

        $result = $service->createIPv6Network('2001:db8:1:1', 'host-', 'example.com', '1', 3600, 0, '', '', 3, false);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $builds);
    }

    public function testCreateIPv6NetworkGeneratesCorrectPtrNames(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);

        $createdRecords = [];

        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$createdRecords) {
                $createdRecords[] = ['name' => $name, 'content' => $content];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturn(-1);

        $service = $this->createService($domainRepository);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $addedNames = [];
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$addedNames) {
                $addedNames[] = $name;
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturn(42);

        $addCount = 0;
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function () use (&$addCount) {
                $addCount++;
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $created = [];
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$created) {
                $created[] = ['name' => $name, 'content' => $content, 'ttl' => $ttl];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        // One AAAA inside the /64, one outside - only the first should yield a PTR.
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
            ['name' => 'other.example.com', 'content' => '2001:db8:2:2::9', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $addCount = 0;
        $recordManager->method('addRecordGetId')->willReturnCallback(function () use (&$addCount) {
            $addCount++;
            return RecordWriteResult::ok(1);
        });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        // AAAA exists but lives in a different /64, so nothing matches.
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'other.example.com', 'content' => '2001:db8:2:2::9', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $ttls = [];
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$ttls) {
                $ttls[] = $ttl;
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $args = ['2001:db8:1:1', '', 'example.com', '1', 3600, 0, '', '', 256, false, null, true];

        // With an explicit matchingPtrTtl, the PTR uses it instead of the AAAA's TTL.
        $override = $service->createIPv6Network(...array_merge($args, [1800]));
        $this->assertTrue($override['success']);

        // With null, it falls back to the matched record's own TTL.
        $fallback = $service->createIPv6Network(...array_merge($args, [null]));
        $this->assertTrue($fallback['success']);

        $this->assertSame([1800, 7200], $ttls);
    }

    public function testCreateIPv6NetworkTalliesPartialFailures(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(fn($zoneId, $name) => str_starts_with($name, '2.') ? RecordWriteResult::failure('refused') : RecordWriteResult::ok(1));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv6Network('2001:db8:1:1', 'host-', 'example.com', '1', 3600, 0, '', '', 4, false);

        $this->assertSame([
            'success' => true,
            'type' => 'warning',
            'message' => 'Created 2 IPv6 PTR records successfully (1 skipped - PTR record already exists for IP address) (1 failed). Failed to create PTR record for 2001:db8:1:1::2: refused',
            'errors' => ['Failed to create PTR record for 2001:db8:1:1::2: refused'],
        ], $result);
    }

    public function testCreateIPv6NetworkFailsWhenEveryPtrFails(): void
    {
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'a.example.com', 'content' => '2001:db8:1:1::a', 'ttl' => 60, 'prio' => 0],
            ['name' => 'b.example.com', 'content' => '2001:db8:1:1::b', 'ttl' => 60, 'prio' => 0],
            ['name' => 'c.example.com', 'content' => '2001:db8:1:1::c', 'ttl' => 60, 'prio' => 0],
            ['name' => 'd.example.com', 'content' => '2001:db8:1:1::d', 'ttl' => 60, 'prio' => 0],
        ]);

        // The probe zone lookup succeeds but every per-record lookup fails, so all four are tallied as failures.
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturn(7);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturnCallback(fn(string $name) => str_starts_with($name, '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.') ? 42 : -1);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv6Network('2001:db8:1:1', '', 'example.com', '1', 3600, 0, '', '', 256, false, null, true);

        $this->assertSame([
            'success' => false,
            'type' => 'error',
            'message' => 'Failed to create any IPv6 PTR records. '
                . 'No matching reverse zone found for a.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa '
                . 'No matching reverse zone found for b.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa '
                . 'No matching reverse zone found for c.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa...',
        ], $result);
    }

    public function testCreateIPv6NetworkCreatesForwardAaaaRecordsWithSeparateTtl(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $added = [];
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$added) {
                $added[] = [$zoneId, $name, $type, $content, $ttl];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('recordExists')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv6Network('2001:db8:1:1', 'host-', 'example.com', '1', 3600, 0, '', '', 2, true, 300);

        $this->assertTrue($result['success']);
        $this->assertSame([
            [42, '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1.0.0.0.1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', 'PTR', 'host-1.example.com', 3600],
            [7, 'host-1.example.com', 'AAAA', '2001:db8:1:1::1', 300],
        ], $added);
    }

    public function testCreateIPv4NetworkRejectsWhenReverseRecordsDisabled(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);

        $result = $this->createService(null, null, $config)->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600);

        $this->assertSame(['success' => false, 'type' => 'error', 'message' => 'Reverse record creation is not allowed.'], $result);
    }

    public function testCreateIPv4NetworkRejectsCidrOutsideSupportedRange(): void
    {
        $service = $this->createService();

        foreach (['10.0.0.0/19', '10.0.0.0/31'] as $prefix) {
            $result = $service->createIPv4Network($prefix, 'host', 'example.com', '1', 3600);
            $this->assertSame('Network size must be between /20 and /30. Supported range: /20 to /30.', $result['message']);
            $this->assertFalse($result['success']);
        }
    }

    public function testCreateIPv4NetworkRejectsInvalidAddress(): void
    {
        $result = $this->createService()->createIPv4Network('300.1.1.0/24', 'host', 'example.com', '1', 3600);

        $this->assertSame([
            'success' => false,
            'type' => 'error',
            'message' => 'Invalid IPv4 address format. Expected format: 192.168.1.0/24 or 10.0.0.0/20.',
        ], $result);
    }

    public function testCreateIPv4NetworkReturnsErrorWhenNoReverseZone(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->expects($this->once())
            ->method('getBestMatchingZoneIdFromName')
            ->with('0.1.168.192.in-addr.arpa')
            ->willReturn(-1);

        $result = $this->createService($domainRepository)->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600);

        $this->assertSame([
            'success' => false,
            'type' => 'error',
            'message' => 'No matching reverse zone found for this network prefix. Please create the reverse zone first.',
        ], $result);
    }

    public function testCreateIPv4NetworkSkipsNetworkAndBroadcastAndReportsSummary(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);

        $added = [];
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$added) {
                $added[] = [$zoneId, $name, $type, $content, $ttl, $prio];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        // Three-octet shorthand defaults to /24; /30 keeps the loop small.
        $result = $service->createIPv4Network('192.168.1.4/30', 'host', 'example.com', '1', 3600, 10, 'c', 'a');

        $this->assertSame([
            'success' => true,
            'type' => 'success',
            'message' => 'Created 2 PTR records successfully (2 skipped - PTR record already exists for IP address)',
            'errors' => [],
        ], $result);
        $this->assertSame([
            [42, '5.1.168.192.in-addr.arpa', 'PTR', 'host1.example.com', 3600, 10],
            [42, '6.1.168.192.in-addr.arpa', 'PTR', 'host2.example.com', 3600, 10],
        ], $added);
    }

    public function testCreateIPv4NetworkUsesDomainAsPtrTargetWithoutHostPrefixAndThreeOctetForm(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);

        $targets = [];
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content) use (&$targets) {
                $targets[$name] = $content;
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv4Network('10.20.30', '', 'example.com', '1', 3600);

        $this->assertSame('Created 254 PTR records successfully (2 skipped - PTR record already exists for IP address)', $result['message']);
        $this->assertSame('example.com', $targets['1.30.20.10.in-addr.arpa']);
        $this->assertSame('example.com', $targets['254.30.20.10.in-addr.arpa']);
        $this->assertArrayNotHasKey('0.30.20.10.in-addr.arpa', $targets);
        $this->assertArrayNotHasKey('255.30.20.10.in-addr.arpa', $targets);
    }

    public function testCreateIPv4NetworkReportsExactDuplicateSkipsWhenDuplicatePtrAllowed(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_reverse_record') {
                return RecordWriteResult::ok(1);
            }
            if ($group === 'dns' && $key === 'prevent_duplicate_ptr') {
                return false;
            }
            return $default;
        });

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->expects($this->never())->method('hasPtrRecord');
        $recordRepo->method('recordExists')
            ->willReturnCallback(fn($zoneId, $name, $type, $content) => $name === '5.1.168.192.in-addr.arpa');

        $service = $this->createService($domainRepository, $recordManager, $config, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.4/30', 'host', 'example.com', '1', 3600);

        $this->assertSame('Created 1 PTR records successfully (3 skipped - exact PTR record already exists)', $result['message']);
    }

    public function testCreateIPv4NetworkTalliesPartialFailuresAndMissingZones(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')
            ->willReturnCallback(fn(string $name) => $name === '6.1.168.192.in-addr.arpa' ? -1 : 42);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name) {
                if ($name === '5.1.168.192.in-addr.arpa') {
                    throw new \Exception('boom');
                }
                return RecordWriteResult::failure('refused');
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.0/29', 'host', 'example.com', '1', 3600);

        $this->assertSame([
            'success' => true,
            'type' => 'warning',
            'message' => 'Created 0 PTR records successfully (2 skipped - PTR record already exists for IP address) (6 failed). '
                . 'Failed to create PTR record for 192.168.1.1: refused Failed to create PTR record for 192.168.1.2: refused Failed to create PTR record for 192.168.1.3: refused...',
            'errors' => [
                'Failed to create PTR record for 192.168.1.1: refused',
                'Failed to create PTR record for 192.168.1.2: refused',
                'Failed to create PTR record for 192.168.1.3: refused',
                'Failed to create PTR record for 192.168.1.4: refused',
                'Failed to create PTR record for 192.168.1.5: boom',
                'No matching reverse zone found for 6.1.168.192.in-addr.arpa',
            ],
        ], $result);
    }

    public function testCreateIPv4NetworkFailsWhenNothingCreatedOrSkipped(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::failure('refused'));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'a.example.com', 'content' => '192.168.1.1', 'ttl' => 60, 'prio' => 0],
            ['name' => 'b.example.com', 'content' => '192.168.1.2', 'ttl' => 60, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600, 0, '', '', false, true);

        $this->assertSame([
            'success' => false,
            'type' => 'error',
            'message' => 'Failed to create any PTR records. Failed to create PTR record for 192.168.1.1: refused Failed to create PTR record for 192.168.1.2: refused',
        ], $result);
    }

    public function testCreateIPv4NetworkCreatesForwardARecordsAndKeepsGoingWhenForwardFails(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $added = [];
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$added) {
                if ($type === 'A' && $content === '192.168.1.6') {
                    throw new \Exception('forward boom');
                }
                $added[] = [$zoneId, $name, $type, $content, $ttl];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('recordExists')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.4/30', 'host', 'example.com', '1', 3600, 0, '', '', true, false, 300);

        $this->assertSame([
            'success' => true,
            'type' => 'warning',
            'message' => 'Created 2 PTR records successfully (2 skipped - PTR record already exists for IP address). Failed to create forward A record for 192.168.1.6: forward boom',
            'errors' => ['Failed to create forward A record for 192.168.1.6: forward boom'],
        ], $result);
        $this->assertSame([
            [42, '5.1.168.192.in-addr.arpa', 'PTR', 'host1.example.com', 3600],
            [7, 'host1.example.com', 'A', '192.168.1.5', 300],
            [42, '6.1.168.192.in-addr.arpa', 'PTR', 'host2.example.com', 3600],
        ], $added);
    }

    public function testCreateIPv4NetworkReportsARefusedForwardWriteWithoutStopping(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(fn($zoneId, $name, $type, $content) => $type === 'A' && $content === '192.168.1.6' ? RecordWriteResult::forbidden('no A for you') : RecordWriteResult::ok(1));

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(false);
        $recordRepo->method('recordExists')->willReturn(false);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.4/30', 'host', 'example.com', '1', 3600, 0, '', '', true, false, 300);

        $this->assertSame([
            'success' => true,
            'type' => 'warning',
            'message' => 'Created 2 PTR records successfully (2 skipped - PTR record already exists for IP address). Failed to create forward A record for 192.168.1.6: no A for you',
            'errors' => ['Failed to create forward A record for 192.168.1.6: no A for you'],
        ], $result);
    }

    public function testCreateIPv4NetworkMatchingModeReturnsErrorWhenNoMatches(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturn(7);
        $domainRepository->expects($this->never())->method('getBestMatchingZoneIdFromName');

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'other.example.com', 'content' => '10.9.9.9', 'ttl' => 60, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, null, null, $recordRepo);

        $result = $service->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600, 0, '', '', false, true);

        $this->assertSame([
            'success' => false,
            'type' => 'error',
            'message' => "No A records found in forward zone 'example.com' that match the IP range 192.168.1.0/24.",
        ], $result);
    }

    public function testCreateIPv4NetworkMatchingModeUsesForwardRecordTtlPrioAndNameAndSkipsDuplicates(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $added = [];
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')
            ->willReturnCallback(function ($zoneId, $name, $type, $content, $ttl, $prio) use (&$added) {
                $added[] = [$zoneId, $name, $type, $content, $ttl, $prio];
                return RecordWriteResult::ok(1);
            });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')
            ->willReturnCallback(fn($zoneId, $name) => $name === '2.1.168.192.in-addr.arpa');
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'www.example.com', 'content' => '192.168.1.1', 'ttl' => 7200, 'prio' => 5],
            ['name' => 'dup.example.com', 'content' => '192.168.1.2', 'ttl' => 7200, 'prio' => 0],
            ['name' => 'other.example.com', 'content' => '10.9.9.9', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

        // No forward records are created in matching mode even when requested, and the PTR TTL comes from the A record.
        $result = $service->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600, 0, '', '', true, true);

        $this->assertSame('Created 1 PTR records successfully (1 skipped - PTR record already exists for IP address)', $result['message']);
        $this->assertSame([[42, '1.1.168.192.in-addr.arpa', 'PTR', 'www.example.com', 7200, 5]], $added);

        $added = [];
        $override = $service->createIPv4Network('192.168.1.0/24', 'host', 'example.com', '1', 3600, 0, '', '', false, true, null, 1800);
        $this->assertTrue($override['success']);
        $this->assertSame([[42, '1.1.168.192.in-addr.arpa', 'PTR', 'www.example.com', 1800, 5]], $added);
    }

    public function testCreateIPv6NetworkMatchingModeSkipsDuplicatePtr(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getBestMatchingZoneIdFromName')->willReturn(42);
        $domainRepository->method('getDomainIdByName')->willReturn(7);

        $addCount = 0;
        $recordManager->method('addRecordGetId')->willReturnCallback(function () use (&$addCount) {
            $addCount++;
            return RecordWriteResult::ok(1);
        });

        $recordRepo = $this->createMock(RecordRepositoryInterface::class);
        $recordRepo->method('hasPtrRecord')->willReturn(true); // a PTR already exists
        $recordRepo->method('getRecordsByDomainId')->willReturn([
            ['name' => 'host5.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 7200, 'prio' => 0],
        ]);

        $service = $this->createService($domainRepository, $recordManager, null, $recordRepo);

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
