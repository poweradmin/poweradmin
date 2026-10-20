<?php

namespace Poweradmin\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DomainUtility;

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

    public function testConvertIPv6AddrToPtrRecFormat(): void
    {
        $result = DomainUtility::convertIPv6AddrToPtrRec('2001:db8::1');

        $this->assertStringEndsWith('.ip6.arpa', $result);
        $this->assertEquals('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $result);
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function ptrToAddressProvider(): array
    {
        return [
            'full ip6.arpa name' => ['1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', '2001:db8::1'],
            'uppercase with trailing dot' => ['A.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.B.D.0.1.0.0.2.IP6.ARPA.', '2001:db8::a'],
            'partial ip6.arpa name' => ['8.b.d.0.1.0.0.2.ip6.arpa', null],
            'ip6.arpa name with a non-hex label' => ['g.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', null],
            'full in-addr.arpa name' => ['5.2.0.192.in-addr.arpa', '192.0.2.5'],
            'partial in-addr.arpa name' => ['2.0.192.in-addr.arpa', null],
            'classless in-addr.arpa name' => ['5.0/26.2.0.192.in-addr.arpa', null],
            'octet out of range' => ['300.2.0.192.in-addr.arpa', null],
            'forward name' => ['www.example.com', null],
        ];
    }

    #[DataProvider('ptrToAddressProvider')]
    public function testConvertPtrRecToIpAddr(string $name, ?string $expected): void
    {
        $this->assertSame($expected, DomainUtility::convertPtrRecToIpAddr($name));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function addressAsNameProvider(): array
    {
        $ip6Zone = '8.b.d.0.1.0.0.2.ip6.arpa';
        $nibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.';

        return [
            'compressed IPv6' => ['2001:db8::1', $ip6Zone, $nibbles . $ip6Zone],
            'full IPv6' => ['2001:0db8:0000:0000:0000:0000:0000:0001', $ip6Zone, $nibbles . $ip6Zone],
            'uppercase IPv6 with whitespace' => [' 2001:DB8::A ', $ip6Zone, 'a.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.' . $ip6Zone],
            'zone spelling is kept' => ['2001:db8::1', '8.B.D.0.1.0.0.2.IP6.ARPA', $nibbles . '8.B.D.0.1.0.0.2.IP6.ARPA'],
            'zone with trailing dot' => ['2001:db8::1', $ip6Zone . '.', $nibbles . $ip6Zone],
            'odd-length prefix zone' => ['2001:db8:a::1', 'a.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.a.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa'],
            'whole ip6.arpa tree' => ['::1', 'ip6.arpa', '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa'],
            'relative nibble name' => ['1.0.0.0', $ip6Zone, '1.0.0.0'],
            'absolute nibble name' => [$nibbles . $ip6Zone, $ip6Zone, $nibbles . $ip6Zone],
            'apex' => ['@', $ip6Zone, '@'],
            'IPv4 in an in-addr.arpa zone' => ['192.0.2.5', '2.0.192.in-addr.arpa', '5.2.0.192.in-addr.arpa'],
            'relative octet name' => ['5', '2.0.192.in-addr.arpa', '5'],
            'dotted quad under bare in-addr.arpa stays relative' => ['5.2.0.192', 'in-addr.arpa', '5.2.0.192'],
            'dotted quad in a classless zone stays relative' => ['192.0.2.5', '0/26.2.0.192.in-addr.arpa', '192.0.2.5'],
            'IPv4 in an ip6.arpa zone is a nibble name' => ['1.2.3.4', $ip6Zone, '1.2.3.4'],
            'forward zone' => ['2001:db8::1', 'example.com', '2001:db8::1'],
        ];
    }

    #[DataProvider('addressAsNameProvider')]
    public function testResolveAddressAsRecordName(string $name, string $zone, string $expected): void
    {
        $this->assertSame(['name' => $expected, 'error' => null], DomainUtility::resolveAddressAsRecordName($name, $zone));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function refusedAddressProvider(): array
    {
        return [
            'IPv6 outside the zone' => ['2001:db9::1', '8.b.d.0.1.0.0.2.ip6.arpa', 'The address 2001:db9::1 is outside the zone 8.b.d.0.1.0.0.2.ip6.arpa.'],
            'IPv4-mapped IPv6 outside the zone' => ['::ffff:192.0.2.1', '8.b.d.0.1.0.0.2.ip6.arpa', 'The address ::ffff:192.0.2.1 is outside the zone 8.b.d.0.1.0.0.2.ip6.arpa.'],
            'IPv4 outside the zone' => ['198.51.100.5', '2.0.192.in-addr.arpa', 'The address 198.51.100.5 is outside the zone 2.0.192.in-addr.arpa.'],
            'IPv6 in an in-addr.arpa zone' => ['2001:db8::1', '2.0.192.in-addr.arpa', 'The address 2001:db8::1 is outside the zone 2.0.192.in-addr.arpa.'],
            'malformed IPv6' => ['2001:db8:::1', '8.b.d.0.1.0.0.2.ip6.arpa', '2001:db8:::1 is not a valid IPv6 address.'],
            'IPv6 with a prefix length' => ['2001:db8::/64', '8.b.d.0.1.0.0.2.ip6.arpa', '2001:db8::/64 is not a valid IPv6 address.'],
        ];
    }

    #[DataProvider('refusedAddressProvider')]
    public function testResolveAddressAsRecordNameRefusesAddressesItCannotPlace(string $name, string $zone, string $error): void
    {
        $this->assertSame(['name' => $name, 'error' => $error], DomainUtility::resolveAddressAsRecordName($name, $zone));
    }

    public function testAnIpv4MappedAddressLandsUnderItsOwnNibbles(): void
    {
        $this->assertSame(
            ['name' => '1.0.2.0.0.0.0.c.f.f.f.f.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa', 'error' => null],
            DomainUtility::resolveAddressAsRecordName('::ffff:192.0.2.1', '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa')
        );
    }
}
