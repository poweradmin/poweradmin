<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\DynamicDnsRequestFactory;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

#[CoversClass(DynamicDnsRequestFactory::class)]
class DynamicDnsRequestFactoryTest extends TestCase
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

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

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

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('queryuser', $dynamicDnsRequest->getUsername());
        $this->assertEquals('querypass', $dynamicDnsRequest->getPassword());
        $this->assertEquals('query.example.com', $dynamicDnsRequest->getHostname());
        $this->assertEquals('10.0.0.1', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('::1', $dynamicDnsRequest->getIpv6());
        $this->assertFalse($dynamicDnsRequest->isDualstackUpdate());
    }

    public function testFromHttpRequestRoutesIpv6InMyipToIpv6Slot(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        // A client that only knows the standard `myip` param sends its IPv6 there.
        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '2001:db8::1',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestKeepsExplicitMyip6WhenMyipIsIpv6(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        // An explicit myip6 stays authoritative; the v6-in-myip value is left in place.
        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '2001:db8::1',
            'myip6' => '2001:db8::2',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('2001:db8::2', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestSplitsBothFamiliesSentInMyip(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'ddclient/3.11.2';

        // ddclient 3.11+ sends both families as one comma-separated `myip` list.
        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '203.0.113.5,2001:db8::1',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.5', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestSplitsBothFamiliesWithDualstackUpdate(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'ddclient/3.11.2';

        // The IPv4 address used to be discarded here, so dualstack_update deleted the A records.
        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '203.0.113.5,2001:db8::1',
            'dualstack_update' => '1',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.5', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
        $this->assertTrue($dynamicDnsRequest->isDualstackUpdate());
    }

    public function testFromHttpRequestKeepsMultipleAddressesOfTheSameFamilyInMyip(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '203.0.113.5,203.0.113.6',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.5,203.0.113.6', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestTrimsWhitespaceAroundMyipElements(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => ' 203.0.113.5 , 2001:db8::1 ',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.5', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestDropsIpv6InMyipWhenMyip6IsExplicit(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        // An explicit myip6 stays authoritative, so the v4 element is still routed
        // but the v6 element in myip is discarded rather than merged.
        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => '203.0.113.5,2001:db8::1',
            'myip6' => '2001:db8::2',
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.5', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('2001:db8::2', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestWithWhatIsMyIpv4(): void
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

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('203.0.113.1', $dynamicDnsRequest->getIpv4());
        $this->assertEquals('', $dynamicDnsRequest->getIpv6()); // IPv6 should be empty when IPv4 is provided
    }

    public function testFromHttpRequestWithWhatIsMyIpv6(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => 'whatismyip',
            'myip6' => 'whatismyip'
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        $this->assertEquals('', $dynamicDnsRequest->getIpv4()); // IPv4 should be empty when IPv6 is provided
        $this->assertEquals('2001:db8::1', $dynamicDnsRequest->getIpv6());
    }

    public function testFromHttpRequestWithWhatIsMyIpFromProxy(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1,203.0.113.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $request = new Request([
            'username' => 'testuser',
            'password' => 'testpass',
            'hostname' => 'test.example.com',
            'myip' => 'whatismyip'
        ]);

        $dynamicDnsRequest = DynamicDnsRequestFactory::fromHttpRequest($request, new FakeConfiguration());

        // The leftmost value (198.51.100.1) is claimed by the untrusted hop
        // 203.0.113.1 and is therefore spoofable. With no trusted proxies
        // configured, the first untrusted address from the right is the real
        // client - add 203.0.113.1 to security.trusted_proxies to look further left.
        $this->assertEquals('203.0.113.1', $dynamicDnsRequest->getIpv4());
    }
}
