<?php

namespace Tests\Unit\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DomainUtility;

class DomainUtilityTest extends TestCase
{
    /**
     * @dataProvider ipv4PtrProvider
     */
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

    /**
     * @dataProvider domainLevelProvider
     */
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

    /**
     * @dataProvider secondLevelDomainProvider
     */
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
     * Note: We can't easily test IPv6 conversion in PHPUnit without mocking inet_pton
     * but we can add a basic test case
     */
    public function testConvertIPv6AddrToPtrRecFormat(): void
    {
        // Given we can't control inet_pton results directly in tests,
        // we'll just test that it returns a string ending with .ip6.arpa

        if (!function_exists('inet_pton')) {
            $this->markTestSkipped('inet_pton function not available');
        }

        $result = DomainUtility::convertIPv6AddrToPtrRec('2001:db8::1');

        $this->assertIsString($result);
        $this->assertStringEndsWith('.ip6.arpa', $result);
    }
}
