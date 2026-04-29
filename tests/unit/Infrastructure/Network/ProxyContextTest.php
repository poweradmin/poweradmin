<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Network;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Network\ProxyContext;

class ProxyContextTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const PROXY_VARS = [
        'HTTPS_PROXY',
        'https_proxy',
        'HTTP_PROXY',
        'http_proxy',
        'NO_PROXY',
        'no_proxy',
    ];

    protected function setUp(): void
    {
        foreach (self::PROXY_VARS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::PROXY_VARS as $var) {
            $value = $this->savedEnv[$var] ?? false;
            if ($value === false) {
                putenv($var);
            } else {
                putenv($var . '=' . $value);
            }
        }
    }

    public function testReturnsEmptyWhenNoProxyConfigured(): void
    {
        $this->assertSame([], ProxyContext::httpOptionsFor('https://idp.example.com/.well-known/openid-configuration'));
    }

    public function testHttpsProxyHonoredForHttpsUrl(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');

        $opts = ProxyContext::httpOptionsFor('https://idp.example.com/.well-known/openid-configuration');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
        $this->assertTrue($opts['request_fulluri']);
    }

    public function testLowercaseHttpsProxyAlsoHonored(): void
    {
        putenv('https_proxy=http://proxy.internal:3128');

        $opts = ProxyContext::httpOptionsFor('https://idp.example.com');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
    }

    public function testUppercaseHttpProxyIgnoredForHttpUrl(): void
    {
        // CVE-2016-5385 (httpoxy): uppercase HTTP_PROXY is unsafe in CGI/FastCGI.
        putenv('HTTP_PROXY=http://attacker.example:8080');

        $this->assertSame([], ProxyContext::httpOptionsFor('http://api.internal/zones'));
    }

    public function testLowercaseHttpProxyHonoredForHttpUrl(): void
    {
        putenv('http_proxy=http://proxy.internal:3128');

        $opts = ProxyContext::httpOptionsFor('http://api.internal/zones');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
        $this->assertTrue($opts['request_fulluri']);
    }

    public function testBareHostPortProxyValueIsNormalized(): void
    {
        putenv('HTTPS_PROXY=proxy.internal:3128');

        $opts = ProxyContext::httpOptionsFor('https://idp.example.com');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
    }

    public function testProxyDefaultsToPort80WhenSchemeIsHttp(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal');

        $opts = ProxyContext::httpOptionsFor('https://idp.example.com');

        $this->assertSame('tcp://proxy.internal:80', $opts['proxy']);
    }

    public function testProxyDefaultsToPort443WhenSchemeIsHttps(): void
    {
        putenv('HTTPS_PROXY=https://proxy.internal');

        $opts = ProxyContext::httpOptionsFor('https://idp.example.com');

        $this->assertSame('tcp://proxy.internal:443', $opts['proxy']);
    }

    public function testNoProxyExactMatchBypassesProxy(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=idp.example.com,other.example.com');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://idp.example.com'));
    }

    public function testNoProxySuffixWithLeadingDotMatchesSubdomain(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=.example.com');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://idp.example.com'));
    }

    public function testNoProxySuffixWithLeadingDotMatchesBareDomain(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=.example.com');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://example.com'));
    }

    public function testNoProxyBareEntryMatchesSubdomain(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=example.com');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://idp.example.com'));
    }

    public function testNoProxyDoesNotMatchUnrelatedHost(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=example.com');

        $opts = ProxyContext::httpOptionsFor('https://example.org');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
    }

    public function testNoProxyDoesNotMatchPartialHostname(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=ample.com');

        $opts = ProxyContext::httpOptionsFor('https://example.com');

        $this->assertSame('tcp://proxy.internal:3128', $opts['proxy']);
    }

    public function testNoProxyWildcardBypassesEverything(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=*');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://anywhere.example.com'));
    }

    public function testNoProxyWhitespaceTolerated(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY= example.com , .other.com ');

        $this->assertSame([], ProxyContext::httpOptionsFor('https://idp.example.com'));
        $this->assertSame([], ProxyContext::httpOptionsFor('https://x.other.com'));
    }

    public function testApplyToMergesIntoExistingHttpOptions(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => 'X-API-Key: secret',
                'timeout' => 10,
            ],
        ];

        $merged = ProxyContext::applyTo($options, 'https://api.example.com/zones');

        $this->assertSame('GET', $merged['http']['method']);
        $this->assertSame('X-API-Key: secret', $merged['http']['header']);
        $this->assertSame(10, $merged['http']['timeout']);
        $this->assertSame('tcp://proxy.internal:3128', $merged['http']['proxy']);
        $this->assertTrue($merged['http']['request_fulluri']);
    }

    public function testApplyToReturnsOptionsUnchangedWhenNoProxy(): void
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
            ],
        ];

        $this->assertSame($options, ProxyContext::applyTo($options, 'https://api.example.com'));
    }

    public function testApplyToCreatesHttpKeyWhenAbsent(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');

        $merged = ProxyContext::applyTo([], 'https://api.example.com');

        $this->assertSame('tcp://proxy.internal:3128', $merged['http']['proxy']);
        $this->assertTrue($merged['http']['request_fulluri']);
    }

    public function testReturnsEmptyForMalformedUrl(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');

        $this->assertSame([], ProxyContext::httpOptionsFor('not a url'));
        $this->assertSame([], ProxyContext::httpOptionsFor(''));
    }

    public function testGuzzleProxyConfigReturnsNullWhenUnset(): void
    {
        $this->assertNull(ProxyContext::guzzleProxyConfig());
    }

    public function testGuzzleProxyConfigPopulatesHttpsKey(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');

        $config = ProxyContext::guzzleProxyConfig();

        $this->assertSame(['https' => 'tcp://proxy.internal:3128'], $config);
    }

    public function testGuzzleProxyConfigPopulatesHttpKeyOnlyFromLowercase(): void
    {
        putenv('HTTP_PROXY=http://attacker.example:8080');
        putenv('http_proxy=http://proxy.internal:3128');

        $config = ProxyContext::guzzleProxyConfig();

        $this->assertSame(['http' => 'tcp://proxy.internal:3128'], $config);
    }

    public function testGuzzleProxyConfigPopulatesAllKeysWhenSet(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('http_proxy=http://proxy.internal:3128');
        putenv('NO_PROXY=localhost,.example.com');

        $config = ProxyContext::guzzleProxyConfig();

        $this->assertSame([
            'http' => 'tcp://proxy.internal:3128',
            'https' => 'tcp://proxy.internal:3128',
            'no' => ['localhost', '.example.com'],
        ], $config);
    }

    public function testGuzzleProxyConfigStripsEmptyNoProxyEntries(): void
    {
        putenv('HTTPS_PROXY=http://proxy.internal:3128');
        putenv('NO_PROXY=,example.com,,other.com,');

        $config = ProxyContext::guzzleProxyConfig();

        $this->assertSame(['example.com', 'other.com'], $config['no']);
    }
}
