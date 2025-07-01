<?php

namespace unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\IpAddressList;
use Poweradmin\Domain\Model\RecordType;
use InvalidArgumentException;

class IpAddressListTest extends TestCase
{
    public function testEmptyConstruction(): void
    {
        $ipList = new IpAddressList();

        $this->assertEmpty($ipList->getIpv4Addresses());
        $this->assertEmpty($ipList->getIpv6Addresses());
        $this->assertEmpty($ipList->getAllAddresses());
        $this->assertFalse($ipList->hasIpv4Addresses());
        $this->assertFalse($ipList->hasIpv6Addresses());
        $this->assertFalse($ipList->hasAnyAddresses());
        $this->assertTrue($ipList->isEmpty());
    }

    public function testValidIpv4Construction(): void
    {
        $ipv4Addresses = ['192.168.1.1', '10.0.0.1', '172.16.0.1'];
        $ipList = new IpAddressList($ipv4Addresses);

        $this->assertEquals($ipv4Addresses, $ipList->getIpv4Addresses());
        $this->assertEmpty($ipList->getIpv6Addresses());
        $this->assertEquals($ipv4Addresses, $ipList->getAllAddresses());
        $this->assertTrue($ipList->hasIpv4Addresses());
        $this->assertFalse($ipList->hasIpv6Addresses());
        $this->assertTrue($ipList->hasAnyAddresses());
        $this->assertFalse($ipList->isEmpty());
    }

    public function testValidIpv6Construction(): void
    {
        $ipv6Addresses = ['2001:db8::1', '::1', '2001:db8::2'];
        $ipList = new IpAddressList([], $ipv6Addresses);

        $this->assertEmpty($ipList->getIpv4Addresses());
        $this->assertEquals($ipv6Addresses, $ipList->getIpv6Addresses());
        $this->assertEquals($ipv6Addresses, $ipList->getAllAddresses());
        $this->assertFalse($ipList->hasIpv4Addresses());
        $this->assertTrue($ipList->hasIpv6Addresses());
        $this->assertTrue($ipList->hasAnyAddresses());
        $this->assertFalse($ipList->isEmpty());
    }

    public function testMixedAddressesConstruction(): void
    {
        $ipv4Addresses = ['192.168.1.1', '10.0.0.1'];
        $ipv6Addresses = ['2001:db8::1', '::1'];
        $ipList = new IpAddressList($ipv4Addresses, $ipv6Addresses);

        $this->assertEquals($ipv4Addresses, $ipList->getIpv4Addresses());
        $this->assertEquals($ipv6Addresses, $ipList->getIpv6Addresses());
        $this->assertEquals(['192.168.1.1', '10.0.0.1', '2001:db8::1', '::1'], $ipList->getAllAddresses());
        $this->assertTrue($ipList->hasIpv4Addresses());
        $this->assertTrue($ipList->hasIpv6Addresses());
        $this->assertTrue($ipList->hasAnyAddresses());
        $this->assertFalse($ipList->isEmpty());
    }

    public function testInvalidIpv4ThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IPv4 address: 192.168.1.256');
        new IpAddressList(['192.168.1.256']);
    }

    public function testInvalidIpv6ThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IPv6 address: gggg::1');
        new IpAddressList([], ['gggg::1']);
    }

    public function testDuplicateAddressesRemoved(): void
    {
        $ipv4Addresses = ['192.168.1.1', '192.168.1.1', '10.0.0.1'];
        $ipv6Addresses = ['2001:db8::1', '2001:db8::1', '::1'];
        $ipList = new IpAddressList($ipv4Addresses, $ipv6Addresses);

        $this->assertEquals(['192.168.1.1', '10.0.0.1'], $ipList->getIpv4Addresses());
        $this->assertEquals(['2001:db8::1', '::1'], $ipList->getIpv6Addresses());
    }

    public function testFromCommaSeparatedStrings(): void
    {
        $ipList = IpAddressList::fromCommaSeparatedStrings(
            '192.168.1.1, 10.0.0.1, 172.16.0.1',
            '2001:db8::1, ::1, 2001:db8::2'
        );

        $this->assertEquals(['192.168.1.1', '10.0.0.1', '172.16.0.1'], $ipList->getIpv4Addresses());
        $this->assertEquals(['2001:db8::1', '::1', '2001:db8::2'], $ipList->getIpv6Addresses());
    }

    public function testFromCommaSeparatedStringsWithInvalidIps(): void
    {
        $ipList = IpAddressList::fromCommaSeparatedStrings(
            '192.168.1.1, invalid.ip, 10.0.0.1',
            '2001:db8::1, invalid::ip, ::1'
        );

        $this->assertEquals(['192.168.1.1', '10.0.0.1'], $ipList->getIpv4Addresses());
        $this->assertEquals(['2001:db8::1', '::1'], $ipList->getIpv6Addresses());
    }

    public function testFromCommaSeparatedStringsWithEmptyStrings(): void
    {
        $ipList = IpAddressList::fromCommaSeparatedStrings('', '');

        $this->assertEmpty($ipList->getIpv4Addresses());
        $this->assertEmpty($ipList->getIpv6Addresses());
        $this->assertTrue($ipList->isEmpty());
    }

    public function testGetAddressesByType(): void
    {
        $ipv4Addresses = ['192.168.1.1', '10.0.0.1'];
        $ipv6Addresses = ['2001:db8::1', '::1'];
        $ipList = new IpAddressList($ipv4Addresses, $ipv6Addresses);

        $this->assertEquals($ipv4Addresses, $ipList->getAddressesByType(RecordType::A));
        $this->assertEquals($ipv6Addresses, $ipList->getAddressesByType(RecordType::AAAA));
        $this->assertEquals([], $ipList->getAddressesByType('CNAME'));
    }

    public function testGetSortedAddresses(): void
    {
        $ipv4Addresses = ['192.168.1.10', '192.168.1.1', '10.0.0.1'];
        $ipv6Addresses = ['2001:db8::10', '2001:db8::1', '::1'];
        $ipList = new IpAddressList($ipv4Addresses, $ipv6Addresses);

        $sortedIpv4 = $ipList->getSortedIpv4Addresses();
        $sortedIpv6 = $ipList->getSortedIpv6Addresses();

        $this->assertEquals(['10.0.0.1', '192.168.1.1', '192.168.1.10'], $sortedIpv4);
        $this->assertEquals(['2001:db8::1', '2001:db8::10', '::1'], $sortedIpv6);

        $this->assertEquals($ipv4Addresses, $ipList->getIpv4Addresses());
        $this->assertEquals($ipv6Addresses, $ipList->getIpv6Addresses());
    }

    public function testComplexScenarios(): void
    {
        $ipList = IpAddressList::fromCommaSeparatedStrings(
            ' 192.168.1.1 , , 10.0.0.1 , 999.999.999.999 , 172.16.0.1 ',
            ' 2001:db8::1 , , ::1 , invalid::address , 2001:db8::2 '
        );

        $this->assertEquals(['192.168.1.1', '10.0.0.1', '172.16.0.1'], $ipList->getIpv4Addresses());
        $this->assertEquals(['2001:db8::1', '::1', '2001:db8::2'], $ipList->getIpv6Addresses());
        $this->assertTrue($ipList->hasIpv4Addresses());
        $this->assertTrue($ipList->hasIpv6Addresses());
        $this->assertFalse($ipList->isEmpty());
    }
}
