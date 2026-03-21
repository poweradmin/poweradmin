<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class DomainRecordCreatorTest extends TestCase
{
    private function createCreator(array $domainMap, ?string $reverseZoneName = '2.0.192.in-addr.arpa', bool $addRecordResult = true): DomainRecordCreator
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_domain_record') {
                return true;
            }
            if ($group === 'dns' && $key === 'ttl') {
                return 3600;
            }
            return $default;
        });

        $logger = $this->createMock(LegacyLogger::class);

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturnCallback(function ($name) use ($domainMap) {
            return $domainMap[$name] ?? null;
        });
        $dnsRecord->method('getDomainNameById')->willReturnCallback(function ($id) use ($domainMap, $reverseZoneName) {
            // Reverse lookup: find zone name by ID
            foreach ($domainMap as $name => $zoneId) {
                if ($zoneId === $id) {
                    return $name;
                }
            }
            return $reverseZoneName;
        });
        $dnsRecord->method('addRecord')->willReturn($addRecordResult);

        return new DomainRecordCreator($config, $logger, $dnsRecord);
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
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_domain_record') {
                return false;  // Feature disabled
            }
            return $default;
        });

        $logger = $this->createMock(LegacyLogger::class);
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturn(1);

        $creator = new DomainRecordCreator($config, $logger, $dnsRecord);

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
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'example.com' ? 1 : null;
        });
        $dnsRecord->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com';
        });
        $dnsRecord->method('addRecord')->willReturnCallback(function ($domainId, $name, $type, $content) use (&$addedIP) {
            $addedIP = $content;
            return true;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_domain_record') {
                return true;
            }
            return $default ?? 3600;
        });

        $creator = new DomainRecordCreator($config, $this->createMock(LegacyLogger::class), $dnsRecord);
        $creator->addDomainRecord('55', 'PTR', 'host.example.com', 5);

        $this->assertSame('192.0.2.55', $addedIP);
    }

    public function testDerivesCorrectHostnameForSubdomainZone(): void
    {
        // For content "test.manager-zone.example.com" and zone "manager-zone.example.com",
        // the record name should be "test" (not "test.manager-zone")
        $addedName = null;
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'manager-zone.example.com' ? 2 : null;
        });
        $dnsRecord->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'manager-zone.example.com';
        });
        $dnsRecord->method('addRecord')->willReturnCallback(function ($domainId, $name) use (&$addedName) {
            $addedName = $name;
            return true;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_domain_record') {
                return true;
            }
            return $default ?? 3600;
        });

        $creator = new DomainRecordCreator($config, $this->createMock(LegacyLogger::class), $dnsRecord);
        $creator->addDomainRecord('55', 'PTR', 'test.manager-zone.example.com', 5);

        $this->assertSame('test', $addedName);
    }

    public function testHandlesTrailingDotInContent(): void
    {
        $addedName = null;
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturnCallback(function ($name) {
            return $name === 'example.com' ? 1 : null;
        });
        $dnsRecord->method('getDomainNameById')->willReturnCallback(function ($id) {
            return $id === 5 ? '2.0.192.in-addr.arpa' : 'example.com';
        });
        $dnsRecord->method('addRecord')->willReturnCallback(function ($domainId, $name) use (&$addedName) {
            $addedName = $name;
            return true;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) {
            if ($group === 'interface' && $key === 'add_domain_record') {
                return true;
            }
            return $default ?? 3600;
        });

        $creator = new DomainRecordCreator($config, $this->createMock(LegacyLogger::class), $dnsRecord);
        $creator->addDomainRecord('55', 'PTR', 'host.example.com.', 5);

        $this->assertSame('host', $addedName);
    }
}
