<?php

namespace unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Symfony\Component\HttpFoundation\Request;

class DynamicDnsRequestTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    public function testFromHttpRequestWithBasicAuth(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'testuser';
        $_SERVER['PHP_AUTH_PW'] = 'testpass';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'hostname' => 'test.example.com',
            'myip' => '192.168.1.1',
            'myip6' => '2001:db8::1',
            'dualstack_update' => '1'
        ]);

        $dynamicDnsRequest = DynamicDnsRequest::fromHttpRequest($request);

        $this->assertEquals('testuser', $dynamicDnsRequest->getUsername());
        $this->assertEquals('testpass', $dynamicDnsRequest->getPassword());
        $this->assertEquals('test.example.com', $dynamicDnsRequest->getHostname());
        $this->assertEquals('192.168.1.1', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
        $this->assertTrue($dynamicDnsRequest->isDualstackUpdate());
        $this->assertEquals('TestAgent/1.0', $dynamicDnsRequest->getUserAgent());
    }

    public function testFromHttpRequestWithQueryParams(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'queryuser',
            'password' => 'querypass',
            'hostname' => 'query.example.com',
            'ip' => '10.0.0.1',
            'ip6' => '::1',
        ]);

        $dynamicDnsRequest = DynamicDnsRequest::fromHttpRequest($request);

        $this->assertEquals('queryuser', $dynamicDnsRequest->getUsername());
        $this->assertEquals('querypass', $dynamicDnsRequest->getPassword());
        $this->assertEquals('query.example.com', $dynamicDnsRequest->getHostname());
        $this->assertEquals('10.0.0.1', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('::1', $dynamicDnsRequest->getIpv6());
        $this->assertFalse($dynamicDnsRequest->isDualstackUpdate());
    }

    public function testFromHttpRequestWithWhatIsMyIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => 'whatismyip',
            'myip6' => 'whatismyip'
        ]);

        $dynamicDnsRequest = DynamicDnsRequest::fromHttpRequest($request);

        $this->assertEquals('203.0.113.1', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('203.0.113.1', $dynamicDnsRequest->getIpv6());
    }

    public function testHasUsername(): void
    {
        $request = new DynamicDnsRequest('user', 'pass', 'host', '', '', false, 'agent');
        $this->assertTrue($request->hasUsername());

        $request = new DynamicDnsRequest('', 'pass', 'host', '', '', false, 'agent');
        $this->assertFalse($request->hasUsername());
    }

    public function testHasUserAgent(): void
    {
        $request = new DynamicDnsRequest('user', 'pass', 'host', '', '', false, 'agent');
        $this->assertTrue($request->hasUserAgent());

        $request = new DynamicDnsRequest('user', 'pass', 'host', '', '', false, '');
        $this->assertFalse($request->hasUserAgent());
    }

    public function testHasHostname(): void
    {
        $request = new DynamicDnsRequest('user', 'pass', 'host.example.com', '', '', false, 'agent');
        $this->assertTrue($request->hasHostname());

        $request = new DynamicDnsRequest('user', 'pass', '', '', '', false, 'agent');
        $this->assertFalse($request->hasHostname());
    }

    public function testHasIpAddresses(): void
    {
        $request = new DynamicDnsRequest('user', 'pass', 'host', '1.2.3.4', '', false, 'agent');
        $this->assertTrue($request->hasIpAddresses());

        $request = new DynamicDnsRequest('user', 'pass', 'host', '', '::1', false, 'agent');
        $this->assertTrue($request->hasIpAddresses());

        $request = new DynamicDnsRequest('user', 'pass', 'host', '1.2.3.4', '::1', false, 'agent');
        $this->assertTrue($request->hasIpAddresses());

        $request = new DynamicDnsRequest('user', 'pass', 'host', '', '', false, 'agent');
        $this->assertFalse($request->hasIpAddresses());
    }

    public function testDirectConstruction(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            'test.example.com',
            '192.168.1.1',
            '2001:db8::1',
            true,
            'TestAgent/1.0'
        );

        $this->assertEquals('testuser', $request->getUsername());
        $this->assertEquals('testpass', $request->getPassword());
        $this->assertEquals('test.example.com', $request->getHostname());
        $this->assertEquals('192.168.1.1', $request->getIpv4());
        $this->assertEquals('2001:db8::1', $request->getIpv6());
        $this->assertTrue($request->isDualstackUpdate());
        $this->assertEquals('TestAgent/1.0', $request->getUserAgent());
    }
}
