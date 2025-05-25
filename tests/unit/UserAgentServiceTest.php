<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\UserAgentService;

class UserAgentServiceTest extends TestCase
{
    /**
     * Test normal User-Agent string handling
     */
    public function testNormalUserAgent(): void
    {
        $server = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
        $service = new UserAgentService($server);

        $this->assertEquals(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            $service->getUserAgent()
        );
    }

    /**
     * Test missing User-Agent handling
     */
    public function testMissingUserAgent(): void
    {
        $server = [];
        $service = new UserAgentService($server);

        $this->assertEquals('unknown', $service->getUserAgent());
    }

    /**
     * Test User-Agent with null bytes
     */
    public function testUserAgentWithNullBytes(): void
    {
        $server = ['HTTP_USER_AGENT' => "Mozilla/5.0\0malicious\0code"];
        $service = new UserAgentService($server);

        $result = $service->getUserAgent();
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringContainsString('Mozilla/5.0', $result);
    }

    /**
     * Test User-Agent with control characters
     */
    public function testUserAgentWithControlCharacters(): void
    {
        $server = ['HTTP_USER_AGENT' => "Mozilla/5.0\r\nX-Injected-Header: malicious"];
        $service = new UserAgentService($server);

        $result = $service->getUserAgent();
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    /**
     * Test very long User-Agent truncation
     */
    public function testVeryLongUserAgent(): void
    {
        $longAgent = str_repeat('A', 600);
        $server = ['HTTP_USER_AGENT' => $longAgent];
        $service = new UserAgentService($server);

        $result = $service->getUserAgent();
        $this->assertLessThanOrEqual(512, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    /**
     * Test browser detection
     */
    public function testBrowserDetection(): void
    {
        $testCases = [
            'Chrome/120.0.0.0' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Firefox/121.0' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Safari/17.2' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Edge/120.0.0.0' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            'Unknown' => 'Some custom user agent string',
        ];

        foreach ($testCases as $expected => $userAgent) {
            $server = ['HTTP_USER_AGENT' => $userAgent];
            $service = new UserAgentService($server);
            $this->assertEquals($expected, $service->getBrowserInfo());
        }
    }

    /**
     * Test bot detection
     */
    public function testBotDetection(): void
    {
        $botAgents = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'curl/7.68.0',
            'python-requests/2.25.1',
            'PostmanRuntime/7.26.8',
        ];

        foreach ($botAgents as $agent) {
            $server = ['HTTP_USER_AGENT' => $agent];
            $service = new UserAgentService($server);
            $this->assertTrue($service->isBot(), "Failed to detect bot: $agent");
        }

        // Test non-bot
        $server = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
        $service = new UserAgentService($server);
        $this->assertFalse($service->isBot());
    }

    /**
     * Test shortened User-Agent
     */
    public function testShortenedUserAgent(): void
    {
        $longAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Very Long Additional Information';
        $server = ['HTTP_USER_AGENT' => $longAgent];
        $service = new UserAgentService($server);

        $shortened = $service->getShortUserAgent(50);
        $this->assertLessThanOrEqual(50, strlen($shortened));
        $this->assertStringEndsWith('...', $shortened);
    }

    /**
     * Test User-Agent with special characters that need escaping
     */
    public function testUserAgentWithSpecialCharacters(): void
    {
        $server = ['HTTP_USER_AGENT' => 'Mozilla/5.0 "test" \'quote\' \\backslash'];
        $service = new UserAgentService($server);

        $result = $service->getUserAgent();
        // Should be properly escaped for safe logging
        $this->assertStringContainsString('\\"', $result);
        $this->assertStringContainsString('\\\'', $result);
        $this->assertStringContainsString('\\\\', $result);
    }
}
