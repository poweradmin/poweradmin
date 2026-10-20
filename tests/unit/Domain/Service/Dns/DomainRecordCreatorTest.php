<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use TestHelpers\FakeConfiguration;

class DomainRecordCreatorTest extends TestCase
{
    private function createCreator(array $domainMap, ?string $reverseZoneName = '2.0.192.in-addr.arpa', bool $addRecordResult = true): DomainRecordCreator
    {
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(function ($name) use ($domainMap) {
            return $domainMap[$name] ?? null;
        });
        $domainRepository->method('getDomainNameById')->willReturnCallback(function ($id) use ($domainMap, $reverseZoneName) {
            // Reverse lookup: find zone name by ID
            foreach ($domainMap as $name => $zoneId) {
                if ($zoneId === $id) {
                    return $name;
                }
            }
            return $reverseZoneName;
        });
        $recordManager->method('addRecordGetId')->willReturn($addRecordResult ? RecordWriteResult::ok(1) : RecordWriteResult::forbidden('You do not have the permission to add a record to this zone.'));

        return new DomainRecordCreator($config, $domainRepository, $recordManager);
    }

    // =========================================================================
    // PTR -> A: subdomain zones (the #1104 regression)
    // =========================================================================

    public function testCreatesDomainRecordForSubdomainZone(): void
    {
        $creator = $this->createCreator([
            'manager-zone.example.com' => 2,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55',                                       // relative PTR name
            'PTR',                                      // type
            'test.manager-zone.example.com',            // content (forward hostname)
            5,                                          // zone_id (reverse zone)
        );

        $this->assertTrue($result['success']);
    }

    public function testCreatesDomainRecordForTopLevelZone(): void
    {
        $creator = $this->createCreator([
            'example.com' => 1,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55',
            'PTR',
            'host.example.com',
            5,
        );

        $this->assertTrue($result['success']);
    }

    public function testReportsARefusedWriteAsAFailure(): void
    {
        $creator = $this->createCreator([
            'example.com' => 1,
            '2.0.192.in-addr.arpa' => 5,
        ], addRecordResult: false);

        $result = $creator->addDomainRecord('55', 'PTR', 'host.example.com', 5);

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['type']);
        $this->assertSame('You do not have the permission to add a record to this zone.', $result['message']);
    }

    public function testFailsWhenNoManagedForwardZone(): void
    {
        $creator = $this->createCreator([
            '2.0.192.in-addr.arpa' => 5,
            // No forward zone registered
        ]);

        $result = $creator->addDomainRecord(
            '55',
            'PTR',
            'host.unknown-zone.example.org',
            5,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no managed zone', $result['message']);
    }

    public function testFindsDeepestMatchingZone(): void
    {
        // Both example.com and sub.example.com exist - should find the deeper one
        $creator = $this->createCreator([
            'example.com' => 1,
            'sub.example.com' => 10,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55',
            'PTR',
            'host.sub.example.com',
            5,
        );

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // FQDN input (callers may pass either relative or FQDN names)
    // =========================================================================

    public function testAcceptsFqdnName(): void
    {
        $creator = $this->createCreator([
            'example.com' => 1,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55.2.0.192.in-addr.arpa',  // FQDN instead of relative "55"
            'PTR',
            'host.example.com',
            5,
        );

        $this->assertTrue($result['success']);
    }

    public function testAcceptsFqdnNameForSubdomainZone(): void
    {
        $creator = $this->createCreator([
            'manager-zone.example.com' => 2,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55.2.0.192.in-addr.arpa',
            'PTR',
            'test.manager-zone.example.com',
            5,
        );

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // Type and config checks
    // =========================================================================

    public function testOnlyWorksForPtrType(): void
    {
        $creator = $this->createCreator([
            'example.com' => 1,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '55',
            'A',  // not PTR
            'host.example.com',
            5,
        );

        $this->assertFalse($result['success']);
    }

    public function testFailsWithEmptyName(): void
    {
        $creator = $this->createCreator([
            'example.com' => 1,
            '2.0.192.in-addr.arpa' => 5,
        ]);

        $result = $creator->addDomainRecord(
            '',   // empty name
            'PTR',
            'host.example.com',
            5,
        );

        $this->assertFalse($result['success']);
    }

    public function testFailsWhenFeatureDisabled(): void
    {
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => false]]);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturn(1);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);

        $result = $creator->addDomainRecord('55', 'PTR', 'host.example.com', 5);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // IP derivation from PTR name
    // =========================================================================

    public function testDerivesCorrectIPFromPtrName(): void
    {
        // PTR name "55" in zone "2.0.192.in-addr.arpa" should create A record with IP 192.0.2.55
        $addedIP = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'example.com' ? 1 : null;
        });
        $domainRepository->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com';
        });
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$addedIP) {
            $addedIP = $content;
            return RecordWriteResult::ok(1);
        });

        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $creator->addDomainRecord('55', 'PTR', 'host.example.com', 5);

        $this->assertSame('192.0.2.55', $addedIP);
    }

    public function testDerivesCorrectHostnameForSubdomainZone(): void
    {
        // For content "test.manager-zone.example.com" and zone "manager-zone.example.com",
        // the record name should be "test" (not "test.manager-zone")
        $addedName = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'manager-zone.example.com' ? 2 : null;
        });
        $domainRepository->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'manager-zone.example.com';
        });
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name) use (&$addedName) {
            $addedName = $name;
            return RecordWriteResult::ok(1);
        });

        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $creator->addDomainRecord('55', 'PTR', 'test.manager-zone.example.com', 5);

        $this->assertSame('test', $addedName);
    }

    public function testHandlesTrailingDotInContent(): void
    {
        $addedName = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'example.com' ? 1 : null;
        });
        $domainRepository->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com';
        });
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name) use (&$addedName) {
            $addedName = $name;
            return RecordWriteResult::ok(1);
        });

        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $creator->addDomainRecord('55', 'PTR', 'host.example.com.', 5);

        $this->assertSame('host', $addedName);
    }

    public function testLogsTheCreatedARecordToTheZoneLog(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())->method('logRecordAdd')->with(1, 'A', 'host.example.com', '192.0.2.55', 3600, 0);
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager, null, null, $audit);
        $result = $creator->addDomainRecord('55', 'PTR', 'host.example.com.', 5);

        $this->assertTrue($result['success']);
    }

    public function testDoesNotLogARefusedWrite(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::forbidden('no'));
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->never())->method('logRecordAdd');
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager, null, null, $audit);

        $this->assertFalse($creator->addDomainRecord('55', 'PTR', 'host.example.com', 5)['success']);
    }

    public function testCreatesAnAaaaRecordFromAnIp6ArpaPtr(): void
    {
        $written = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 6 ? '8.b.d.0.1.0.0.2.ip6.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$written) {
            $written = [$domainId, $name, $type, $content];
            return RecordWriteResult::ok(1);
        });
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $result = $creator->addDomainRecord('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0', 'PTR', 'host.example.com', 6);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 'host', 'AAAA', '2001:db8::1'], $written);
    }

    public function testAcceptsUpperCaseNibblesAndAFqdnName(): void
    {
        $written = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 6 ? '8.b.d.0.1.0.0.2.ip6.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$written) {
            $written = $content;
            return RecordWriteResult::ok(1);
        });
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $result = $creator->addDomainRecord('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.A.8.B.D.0.1.0.0.2.IP6.ARPA', 'PTR', 'host.example.com', 6);

        $this->assertTrue($result['success']);
        $this->assertSame('2001:db8:a000::1', $written);
    }

    public function testRefusesAnIp6ArpaPtrThatIsNotAFullAddress(): void
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 6 ? '8.b.d.0.1.0.0.2.ip6.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->expects($this->never())->method('addRecordGetId');
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);

        $this->assertFalse($creator->addDomainRecord('1.0.0.0', 'PTR', 'host.example.com', 6)['success']);
    }

    public function testCreatesTheForwardRecordForAPtrAtTheApexOfASingleAddressZone(): void
    {
        $written = [];
        $zone128 = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => match ($id) {
            6 => $zone128,
            7 => '55.2.0.192.in-addr.arpa',
            default => 'example.com',
        });
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$written) {
            $written[] = [$type, $content];
            return RecordWriteResult::ok(1);
        });
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);
        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);

        $this->assertTrue($creator->addDomainRecord($zone128, 'PTR', 'host.example.com', 6)['success']);
        $this->assertTrue($creator->addDomainRecord('55.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 7)['success']);
        $this->assertSame([['AAAA', '2001:db8::1'], ['A', '192.0.2.55']], $written);
    }

    public function testCreatesTheForwardRecordAtTheApexOfAManagedZone(): void
    {
        $written = null;
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainIdByName')->willReturnCallback(fn($name) => $name === 'example.com' ? 1 : null);
        $domainRepository->method('getDomainNameById')->willReturnCallback(fn($id) => $id === 6 ? '8.b.d.0.1.0.0.2.ip6.arpa' : 'example.com');
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('addRecordGetId')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$written) {
            $written = [$domainId, $name, $type, $content];
            return RecordWriteResult::ok(1);
        });
        $config = new FakeConfiguration(['interface' => ['add_domain_record' => true], 'dns' => ['ttl' => 3600]]);

        $creator = new DomainRecordCreator($config, $domainRepository, $recordManager);
        $result = $creator->addDomainRecord('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0', 'PTR', 'example.com.', 6);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 'example.com', 'AAAA', '2001:db8::1'], $written);
    }
}
