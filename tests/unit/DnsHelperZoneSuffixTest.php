<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DnsHelper;

class DnsHelperZoneSuffixTest extends TestCase
{
    public function testStripZoneSuffixWithApexRecord(): void
    {
        $result = DnsHelper::stripZoneSuffix('example.com', 'example.com');
        $this->assertEquals('@', $result);
    }

    public function testStripZoneSuffixWithSubdomain(): void
    {
        $result = DnsHelper::stripZoneSuffix('www.example.com', 'example.com');
        $this->assertEquals('www', $result);
    }

    public function testStripZoneSuffixWithMultiLevelSubdomain(): void
    {
        $result = DnsHelper::stripZoneSuffix('api.v2.example.com', 'example.com');
        $this->assertEquals('api.v2', $result);
    }

    public function testStripZoneSuffixWithMismatchedZone(): void
    {
        // Should return as-is if zone doesn't match
        $result = DnsHelper::stripZoneSuffix('www.other.com', 'example.com');
        $this->assertEquals('www.other.com', $result);
    }

    public function testRestoreZoneSuffixWithApexSymbol(): void
    {
        $result = DnsHelper::restoreZoneSuffix('@', 'example.com');
        $this->assertEquals('example.com', $result);
    }

    public function testRestoreZoneSuffixWithEmptyString(): void
    {
        $result = DnsHelper::restoreZoneSuffix('', 'example.com');
        $this->assertEquals('example.com', $result);
    }

    public function testRestoreZoneSuffixWithHostname(): void
    {
        $result = DnsHelper::restoreZoneSuffix('www', 'example.com');
        $this->assertEquals('www.example.com', $result);
    }

    public function testRestoreZoneSuffixWithMultiLevelHostname(): void
    {
        $result = DnsHelper::restoreZoneSuffix('api.v2', 'example.com');
        $this->assertEquals('api.v2.example.com', $result);
    }

    public function testRestoreZoneSuffixWithAlreadyFullyQualified(): void
    {
        // Should return as-is if already contains zone name
        $result = DnsHelper::restoreZoneSuffix('www.example.com', 'example.com');
        $this->assertEquals('www.example.com', $result);
    }

    public function testRestoreZoneSuffixWithZoneNameOnly(): void
    {
        // Should return as-is if hostname equals zone name
        $result = DnsHelper::restoreZoneSuffix('example.com', 'example.com');
        $this->assertEquals('example.com', $result);
    }

    public function testRoundTripConversion(): void
    {
        $zoneName = 'example.com';
        $testCases = [
            'example.com' => '@',
            'www.example.com' => 'www',
            'mail.example.com' => 'mail',
            'api.v2.example.com' => 'api.v2',
            'subdomain.test.example.com' => 'subdomain.test'
        ];

        foreach ($testCases as $fqdn => $expectedHostname) {
            // Test stripping
            $strippedHostname = DnsHelper::stripZoneSuffix($fqdn, $zoneName);
            $this->assertEquals($expectedHostname, $strippedHostname, "Failed to strip zone suffix from $fqdn");

            // Test restoration
            $restoredFqdn = DnsHelper::restoreZoneSuffix($strippedHostname, $zoneName);
            $this->assertEquals($fqdn, $restoredFqdn, "Failed to restore zone suffix for $strippedHostname");
        }
    }

    public function testWithReverseZones(): void
    {
        $reverseZone = '1.168.192.in-addr.arpa';

        // Test stripping
        $result = DnsHelper::stripZoneSuffix('10.1.168.192.in-addr.arpa', $reverseZone);
        $this->assertEquals('10', $result);

        // Test restoration
        $result = DnsHelper::restoreZoneSuffix('10', $reverseZone);
        $this->assertEquals('10.1.168.192.in-addr.arpa', $result);
    }

    public function testWithIPv6ReverseZones(): void
    {
        $reverseZone = '8.b.d.0.1.0.0.2.ip6.arpa';

        // Test stripping
        $result = DnsHelper::stripZoneSuffix('1.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $reverseZone);
        $this->assertEquals('1.0.0.0.0.0.0.0', $result);

        // Test restoration
        $result = DnsHelper::restoreZoneSuffix('1.0.0.0.0.0.0.0', $reverseZone);
        $this->assertEquals('1.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $result);
    }
}
