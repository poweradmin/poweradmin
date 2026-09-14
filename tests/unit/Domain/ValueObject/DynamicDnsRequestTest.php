<?php

namespace Poweradmin\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;

class DynamicDnsRequestTest extends TestCase
{
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
