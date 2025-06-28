<?php

namespace unit\Api;

use PHPUnit\Framework\TestCase;

class DocsControllerTest extends TestCase
{
    private TestableDocsController $controller;

    protected function setUp(): void
    {
        // Create testable controller instance
        $this->controller = new TestableDocsController();
    }

    public function testGetValidatedHostWithValidDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('example.com', $result);
    }

    public function testGetValidatedHostWithValidDomainAndPort(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com:8080';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('example.com:8080', $result);
    }

    public function testGetValidatedHostWithValidIPv4(): void
    {
        $_SERVER['HTTP_HOST'] = '192.168.1.100';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('192.168.1.100', $result);
    }

    public function testGetValidatedHostWithValidIPv4AndPort(): void
    {
        $_SERVER['HTTP_HOST'] = '192.168.1.100:3000';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('192.168.1.100:3000', $result);
    }

    public function testGetValidatedHostWithBareIPv6ShouldFallbackToLocalhost(): void
    {
        // Bare IPv6 addresses are not valid in HTTP_HOST and should fallback to localhost
        $_SERVER['HTTP_HOST'] = '2001:db8::1';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithMaliciousSingleQuote(): void
    {
        $_SERVER['HTTP_HOST'] = "evil.com'; alert('xss'); var x='";

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithMaliciousDoubleQuote(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.com"; alert("xss"); var x="';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithMaliciousScript(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.com<script>alert("xss")</script>';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithSemicolon(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.com; rm -rf /';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithExcessiveLength(): void
    {
        $_SERVER['HTTP_HOST'] = str_repeat('a', 254) . '.com';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithInvalidCharacters(): void
    {
        $_SERVER['HTTP_HOST'] = 'invalid@host.com';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithEmptyHost(): void
    {
        $_SERVER['HTTP_HOST'] = '';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithMissingHost(): void
    {
        unset($_SERVER['HTTP_HOST']);

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithLocalhostFallback(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('localhost', $result);
    }

    public function testGetValidatedHostWithSubdomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.com';

        $result = $this->controller->getValidatedHostPublic();

        $this->assertEquals('api.example.com', $result);
    }

    protected function tearDown(): void
    {
        // Clean up $_SERVER state after each test
        unset($_SERVER['HTTP_HOST']);
    }
}
