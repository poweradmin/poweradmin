<?php

namespace Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Utility\NetworkUtility;

class DomainUtilityTest extends TestCase
{
    #[DataProvider('ipv4PtrProvider')]
    public function testConvertIPv4AddrToPtrRec(string $ip, string $expected): void
    {
        $this->assertEquals($expected, DomainUtility::convertIPv4AddrToPtrRec($ip));
    }

    public static function ipv4PtrProvider(): array
    {
        return [
            'Regular IPv4' => ['192.168.1.1', '1.1.168.192.in-addr.arpa'],
            'IPv4 zeros' => ['10.0.0.1', '1.0.0.10.in-addr.arpa'],
            'IPv4 all parts' => ['8.8.4.4', '4.4.8.8.in-addr.arpa'],
        ];
    }

    #[DataProvider('domainLevelProvider')]
    public function testGetDomainLevel(string $domain, int $expected): void
    {
        $this->assertEquals($expected, DomainUtility::getDomainLevel($domain));
    }

    public static function domainLevelProvider(): array
    {
        return [
            'TLD' => ['com', 1],
            'Second level' => ['example.com', 2],
            'Third level' => ['www.example.com', 3],
            'Fourth level' => ['test.www.example.com', 4],
        ];
    }

    #[DataProvider('secondLevelDomainProvider')]
    public function testGetSecondLevelDomain(string $domain, string $expected): void
    {
        $this->assertEquals($expected, DomainUtility::getSecondLevelDomain($domain));
    }

    public static function secondLevelDomainProvider(): array
    {
        return [
            'Second level' => ['example.com', 'example.com'],
            'Third level' => ['www.example.com', 'example.com'],
            'Fourth level' => ['test.www.example.com', 'example.com'],
            'Fifth level' => ['dev.test.www.example.com', 'example.com'],
        ];
    }

    /**
     * Test conversion of IPv6 address to PTR format using a mock
     * We'll test the functionality with a known binary value
     */
    public function testConvertIPv6AddrToPtrRecFormat(): void
    {
        // Skip if the function doesn't exist, just to be safe
        if (!function_exists('inet_pton')) {
            $this->markTestSkipped('inet_pton function not available');
            return;
        }

        // Create a temporary TestNetworkUtility class that overrides the inetPton method
        // for the duration of this test
        $tempNetworkUtilityClass = new class extends NetworkUtility {
            public static function inetPton(string $ip)
            {
                // Return a fixed binary representation for '2001:db8::1'
                if ($ip === '2001:db8::1') {
                    return hex2bin('20010db8000000000000000000000001');
                }
                return parent::inetPton($ip);
            }
        };

        // Backup the original class
        $originalClass = NetworkUtility::class;

        // Use reflection to set our test class
        $refProperty = new \ReflectionProperty(NetworkUtility::class, 'instance');
        $refProperty->setAccessible(true);
        $refProperty->setValue(null, $tempNetworkUtilityClass);

        // Run the test
        $result = DomainUtility::convertIPv6AddrToPtrRec('2001:db8::1');

        // Reset to original
        $refProperty->setValue(null, null);

        // Verify the result
        $this->assertStringEndsWith('.ip6.arpa', $result);
        $this->assertEquals('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $result);
    }

    // Provider is no longer needed - we're using a different approach
    // for testing the IPv6 conversion
}
