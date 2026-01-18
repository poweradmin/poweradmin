<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\IpHelper;

class IpHelperTest extends TestCase
{
    public function testGetProposedIPv4WithValidNameAndZone()
    {
        $name = '123';
        $zone_name = '100.51.198.in-addr.arpa';
        $suffix = '.in-addr.arpa';
        $expected = '198.51.100.123';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertEquals($expected, $result, 'Should return the proposed IPv4 address.');
    }

    public function testGetProposedIPv4WithValidComplexNameAndZone()
    {
        $name = '45.200';
        $zone_name = '100.50.in-addr.arpa';
        $suffix = '.in-addr.arpa';
        $expected = '50.100.200.45';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertEquals($expected, $result, 'Should return the proposed IPv4 address.');
    }

    public function testGetProposedIPv4NegativeCaseInvalidName()
    {
        $name = 'abc';
        $zone_name = '100.51.198.in-addr.arpa';
        $suffix = '.in-addr.arpa';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for invalid name.');
    }

    public function testGetProposedIPv4NegativeCaseInvalidZoneName()
    {
        $name = '123';
        $zone_name = 'invalid.zone.name';
        $suffix = '.in-addr.arpa';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for invalid zone name.');
    }

    public function testGetProposedIPv4CornerCaseEmptyName()
    {
        $name = '';
        $zone_name = '100.51.198.in-addr.arpa';
        $suffix = '.in-addr.arpa';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty name.');
    }

    public function testGetProposedIPv4CornerCaseEmptyZoneName()
    {
        $name = '123';
        $zone_name = '';
        $suffix = '.in-addr.arpa';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty zone name.');
    }

    public function testGetProposedIPv4CornerCaseEmptySuffix()
    {
        $name = '123';
        $zone_name = '100.51.198.in-addr.arpa';
        $suffix = '';

        $result = IpHelper::getProposedIPv4($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty suffix.');
    }

    public function testGetProposedIPv6WithValidNameAndZone()
    {
        $name = '5.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
        $zone_name = '0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $suffix = '.ip6.arpa';
        $expected = '2001:db8::5';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertEquals($expected, $result, 'Should return the proposed IPv6 address.');
    }

    public function testGetProposedIPv6NegativeCaseInvalidName()
    {
        $name = 'invalid';
        $zone_name = '0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $suffix = '.ip6.arpa';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for invalid name.');
    }

    public function testGetProposedIPv6NegativeCaseInvalidZoneName()
    {
        $name = '5.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
        $zone_name = 'invalid.zone.name';
        $suffix = '.ip6.arpa';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for invalid zone name.');
    }

    public function testGetProposedIPv6CornerCaseEmptyName()
    {
        $name = '';
        $zone_name = '0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $suffix = '.ip6.arpa';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty name.');
    }

    public function testGetProposedIPv6CornerCaseEmptyZoneName()
    {
        $name = '5.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
        $zone_name = '';
        $suffix = '.ip6.arpa';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty zone name.');
    }

    public function testGetProposedIPv6CornerCaseEmptySuffix()
    {
        $name = '5.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
        $zone_name = '0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $suffix = '';

        $result = IpHelper::getProposedIPv6($name, $zone_name, $suffix);

        $this->assertNull($result, 'Should return null for empty suffix.');
    }

    /**
     * Test getCidrBlockSize returns correct block sizes
     */
    public function testGetCidrBlockSize(): void
    {
        // Common CIDR prefixes and expected block sizes
        $this->assertEquals(256, IpHelper::getCidrBlockSize(24));  // /24 = 256 addresses
        $this->assertEquals(128, IpHelper::getCidrBlockSize(25));  // /25 = 128 addresses
        $this->assertEquals(64, IpHelper::getCidrBlockSize(26));   // /26 = 64 addresses
        $this->assertEquals(32, IpHelper::getCidrBlockSize(27));   // /27 = 32 addresses
        $this->assertEquals(16, IpHelper::getCidrBlockSize(28));   // /28 = 16 addresses
        $this->assertEquals(8, IpHelper::getCidrBlockSize(29));    // /29 = 8 addresses
        $this->assertEquals(4, IpHelper::getCidrBlockSize(30));    // /30 = 4 addresses
        $this->assertEquals(2, IpHelper::getCidrBlockSize(31));    // /31 = 2 addresses
        $this->assertEquals(1, IpHelper::getCidrBlockSize(32));    // /32 = 1 address

        // Larger networks
        $this->assertEquals(512, IpHelper::getCidrBlockSize(23));   // /23 = 512 addresses
        $this->assertEquals(1024, IpHelper::getCidrBlockSize(22));  // /22 = 1024 addresses
        $this->assertEquals(4096, IpHelper::getCidrBlockSize(20));  // /20 = 4096 addresses
        $this->assertEquals(65536, IpHelper::getCidrBlockSize(16)); // /16 = 65536 addresses
    }

    /**
     * Test getCidrNetmask returns correct netmasks
     * Tests by verifying network address calculation works correctly
     */
    public function testGetCidrNetmask(): void
    {
        // Test /24 netmask - 192.168.1.100 should become 192.168.1.0
        $ip = ip2long('192.168.1.100');
        $netmask = IpHelper::getCidrNetmask(24);
        $this->assertEquals(ip2long('192.168.1.0'), $ip & $netmask);

        // Test /25 netmask - 192.168.1.200 should become 192.168.1.128
        $ip = ip2long('192.168.1.200');
        $netmask = IpHelper::getCidrNetmask(25);
        $this->assertEquals(ip2long('192.168.1.128'), $ip & $netmask);

        // Test /26 netmask - 192.168.1.100 should become 192.168.1.64
        $ip = ip2long('192.168.1.100');
        $netmask = IpHelper::getCidrNetmask(26);
        $this->assertEquals(ip2long('192.168.1.64'), $ip & $netmask);

        // Test /16 netmask - 192.168.100.50 should become 192.168.0.0
        $ip = ip2long('192.168.100.50');
        $netmask = IpHelper::getCidrNetmask(16);
        $this->assertEquals(ip2long('192.168.0.0'), $ip & $netmask);

        // Test /8 netmask - 10.20.30.40 should become 10.0.0.0
        $ip = ip2long('10.20.30.40');
        $netmask = IpHelper::getCidrNetmask(8);
        $this->assertEquals(ip2long('10.0.0.0'), $ip & $netmask);
    }

    /**
     * Test isSubnetAligned with valid aligned subnets
     */
    public function testIsSubnetAlignedWithValidSubnets(): void
    {
        // /26 subnets (block size 64) - valid boundaries: 0, 64, 128, 192
        $this->assertTrue(IpHelper::isSubnetAligned(0, 26));
        $this->assertTrue(IpHelper::isSubnetAligned(64, 26));
        $this->assertTrue(IpHelper::isSubnetAligned(128, 26));
        $this->assertTrue(IpHelper::isSubnetAligned(192, 26));

        // /27 subnets (block size 32) - valid boundaries: 0, 32, 64, 96, 128, etc.
        $this->assertTrue(IpHelper::isSubnetAligned(0, 27));
        $this->assertTrue(IpHelper::isSubnetAligned(32, 27));
        $this->assertTrue(IpHelper::isSubnetAligned(64, 27));
        $this->assertTrue(IpHelper::isSubnetAligned(96, 27));

        // /28 subnets (block size 16)
        $this->assertTrue(IpHelper::isSubnetAligned(0, 28));
        $this->assertTrue(IpHelper::isSubnetAligned(16, 28));
        $this->assertTrue(IpHelper::isSubnetAligned(32, 28));
    }

    /**
     * Test isSubnetAligned with invalid misaligned subnets
     */
    public function testIsSubnetAlignedWithInvalidSubnets(): void
    {
        // /26 subnets - invalid (not multiples of 64)
        $this->assertFalse(IpHelper::isSubnetAligned(1, 26));
        $this->assertFalse(IpHelper::isSubnetAligned(65, 26));
        $this->assertFalse(IpHelper::isSubnetAligned(127, 26));
        $this->assertFalse(IpHelper::isSubnetAligned(63, 26));

        // /27 subnets - invalid (not multiples of 32)
        $this->assertFalse(IpHelper::isSubnetAligned(1, 27));
        $this->assertFalse(IpHelper::isSubnetAligned(3, 27));
        $this->assertFalse(IpHelper::isSubnetAligned(31, 27));
        $this->assertFalse(IpHelper::isSubnetAligned(33, 27));
    }
}
