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
}
