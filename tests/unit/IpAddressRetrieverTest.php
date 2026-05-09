<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class IpAddressRetrieverTest extends TestCase
{
    public function testGetClientIpWithHttpClientIp()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CLIENT_IP' => '192.168.1.1',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.1', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithHttpClientIp6()
    {
        $server = [
            'REMOTE_ADDR' => '::1',
            'HTTP_CLIENT_IP' => '2001:db8:85a3:8d3:1319:8a2e:370:7348',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('2001:db8:85a3:8d3:1319:8a2e:370:7348', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithMultipleIpsFirstValid()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.2, 10.0.0.1',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.2', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithMultipleIpsFirstInvalid()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'invalid_ip, 192.168.1.3',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.3', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithAllInvalidIps()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'invalid_ip1, invalid_ip2',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('127.0.0.1', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithHttpXForwardedFor()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.2',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.2', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithRemoteAddr()
    {
        $server = ['REMOTE_ADDR' => '192.168.1.3'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.3', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithInvalidIp()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CLIENT_IP' => 'invalid_ip',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('127.0.0.1', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithNoIp()
    {
        $server = [];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithSpacesAfterComma()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 192.168.1.4',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('10.0.0.1', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithSpacesOnlySecondValid()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'bad, 192.168.1.5',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.5', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithHttpXRealIp()
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.50',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('203.0.113.50', $ipRetriever->getClientIp());
    }

    public function testGetClientIpPrefersXRealIpWhenXForwardedForMatchesRemoteAddr()
    {
        $server = [
            'REMOTE_ADDR' => '172.17.0.1',
            'HTTP_X_FORWARDED_FOR' => '172.17.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.50',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('203.0.113.50', $ipRetriever->getClientIp());
    }

    public function testIgnoresForwardedHeadersWhenRemoteAddrIsPublic()
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '5.6.7.8',
            'HTTP_CLIENT_IP' => '9.10.11.12',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('203.0.113.10', $ipRetriever->getClientIp());
    }

    public function testIgnoresForwardedHeadersWhenRemoteAddrIsPublicV6()
    {
        $server = [
            'REMOTE_ADDR' => '2606:4700:4700::1111',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('2606:4700:4700::1111', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersWhenRemoteAddrIsRfc1918()
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersWhenRemoteAddrIsLoopbackV6()
    {
        $server = [
            'REMOTE_ADDR' => '::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersWhenRemoteAddrIsUniqueLocalV6()
    {
        $server = [
            'REMOTE_ADDR' => 'fc00::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testReturnsEmptyWhenRemoteAddrAbsentEvenWithHeaders()
    {
        $server = [
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }
}
