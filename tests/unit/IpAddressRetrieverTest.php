<?php

namespace Poweradmin\Tests\Unit;

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

    public function testGetClientIpWithMultipleIpsReturnsRightmostUntrusted()
    {
        // With no trusted proxies configured, the only trustworthy entry is the
        // rightmost one (recorded by the trusted peer); leftmost values are
        // client-claimed and may be spoofed.
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.2, 10.0.0.1',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('10.0.0.1', $ipRetriever->getClientIp());
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
        // Whitespace after the comma is trimmed; with no trusted proxies the
        // rightmost entry is returned.
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 192.168.1.4',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('192.168.1.4', $ipRetriever->getClientIp());
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

    public function testTrustsForwardedHeadersFromConfiguredPublicProxyExactIp()
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['203.0.113.10']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersFromConfiguredPublicProxyCidr()
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.55',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['203.0.113.0/24']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersFromConfiguredPublicProxyV6Cidr()
    {
        $server = [
            'REMOTE_ADDR' => '2001:db8:abcd:12::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['2001:db8:abcd:12::/64']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsForwardedHeadersFromConfiguredPublicProxyWildcard()
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['203.0.113.*']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testIgnoresForwardedHeadersWhenPublicPeerNotInTrustedList()
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['198.51.100.0/24']);
        $this->assertEquals('203.0.113.10', $ipRetriever->getClientIp());
    }

    public function testPrivatePeerStillTrustedWhenTrustedListConfigured()
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['203.0.113.10']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testTrustsExactIpv6ProxyRegardlessOfTextualForm()
    {
        $server = [
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ];
        // Configured in expanded form, peer reports the compressed form.
        $ipRetriever = new IpAddressRetriever($server, null, ['2001:0db8:0000:0000:0000:0000:0000:0001']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testIgnoresSpoofedLeftmostXffWhenTrustedProxyAppends()
    {
        // Client spoofs 1.2.3.4 as the leftmost value; the trusted public CDN
        // appends the real client (198.51.100.42) to the right.
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.42',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['203.0.113.10']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testWalksChainRightToLeftSkippingConfiguredProxyHops()
    {
        // The internal proxy tier is declared via a configured CIDR, so those
        // hops are peeled and the real client to their left is returned.
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 10.0.0.2, 10.0.0.3',
        ];
        $ipRetriever = new IpAddressRetriever($server, null, ['10.0.0.0/8']);
        $this->assertEquals('198.51.100.42', $ipRetriever->getClientIp());
    }

    public function testDoesNotPeelPrivateHopUnlessConfiguredAsTrusted()
    {
        // A private hop inside the chain is NOT auto-trusted: a client on the
        // internal network cannot forge a public address by prepending one.
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.5.6.7',
        ];
        $ipRetriever = new IpAddressRetriever($server);
        $this->assertEquals('10.5.6.7', $ipRetriever->getClientIp());
    }
}
