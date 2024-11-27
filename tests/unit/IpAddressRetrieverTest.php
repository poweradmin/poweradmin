<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class IpAddressRetrieverTest extends TestCase
{
    public function testGetClientIpWithHttpClientIp()
    {
        $server = ['HTTP_CLIENT_IP' => '192.168.1.1'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.1', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithHttpClientIp6()
    {
        $server = ['HTTP_CLIENT_IP' => '2001:db8:85a3:8d3:1319:8a2e:370:7348'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('2001:db8:85a3:8d3:1319:8a2e:370:7348', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithMultipleIpsFirstValid()
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '192.168.1.2, 10.0.0.1'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.2', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithMultipleIpsFirstInvalid()
    {
        $server = ['HTTP_X_FORWARDED_FOR' => 'invalid_ip, 192.168.1.3'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithAllInvalidIps()
    {
        $server = ['HTTP_X_FORWARDED_FOR' => 'invalid_ip1, invalid_ip2'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithHttpXForwardedFor()
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '192.168.1.2'];
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
        $server = ['HTTP_CLIENT_IP' => 'invalid_ip'];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }

    public function testGetClientIpWithNoIp()
    {
        $server = [];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('', $ipRetriever->getClientIp());
    }
}
