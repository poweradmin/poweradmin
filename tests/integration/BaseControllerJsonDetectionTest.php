<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;

/**
 * Integration tests for BaseController JSON detection logic
 *
 * These tests verify the expectsJson() method works correctly with various
 * HTTP request scenarios. This is critical for proper API vs web response handling.
 *
 * Tests cover:
 * - API route detection
 * - Accept header parsing
 * - AJAX request detection
 * - Edge cases and security concerns
 */
class BaseControllerJsonDetectionTest extends TestCase
{
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    /**
     * Set $_SERVER variables for testing
     */
    private function setServerEnvironment(array $vars): void
    {
        // Clear relevant $_SERVER vars first
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        // Set test values
        foreach ($vars as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Test API route detection - core functionality
     */
    public function testDetectsApiRoutes(): void
    {
        $apiRoutes = [
            '/api/v1/zones',
            '/api/v1/records',
            '/api/internal/stats',
            '/api/docs',
            '/api/',
            '/some/path/api/endpoint'
        ];

        foreach ($apiRoutes as $route) {
            $this->setServerEnvironment([
                'REQUEST_URI' => $route,
                'HTTP_ACCEPT' => 'text/html'  // Even with HTML accept, API routes should return JSON
            ]);

            $this->assertTrue(
                BaseController::expectsJson(),
                "Failed to detect API route: {$route}"
            );
        }

        // Test edge case: /api without trailing slash should NOT be detected
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api',
            'HTTP_ACCEPT' => 'text/html'
        ]);
        $this->assertFalse(
            BaseController::expectsJson(),
            "Should not detect '/api' without trailing slash as API route"
        );
    }

    /**
     * Test non-API routes don't trigger JSON mode
     */
    public function testDoesNotDetectNonApiRoutes(): void
    {
        $webRoutes = [
            '/',
            '/dashboard',
            '/zones/edit',
            '/users/list',
            '/apidocs', // Similar but not /api/
            '/documentation',
            '/admin/api_settings' // Contains 'api' but not /api/ path
        ];

        foreach ($webRoutes as $route) {
            $this->setServerEnvironment([
                'REQUEST_URI' => $route,
                'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            ]);

            $this->assertFalse(
                BaseController::expectsJson(),
                "Incorrectly detected web route as API: {$route}"
            );
        }
    }

    /**
     * Test Accept header parsing - JSON preference
     */
    public function testDetectsJsonAcceptHeader(): void
    {
        $jsonAcceptHeaders = [
            'application/json',
            'application/json;charset=UTF-8',
            'application/json, text/plain, */*',
        ];

        foreach ($jsonAcceptHeaders as $acceptHeader) {
            $this->setServerEnvironment([
                'REQUEST_URI' => '/dashboard',
                'HTTP_ACCEPT' => $acceptHeader
            ]);

            $this->assertTrue(
                BaseController::expectsJson(),
                "Failed to detect JSON Accept header: {$acceptHeader}"
            );
        }
    }

    /**
     * Test Accept header parsing - HTML preference over JSON
     */
    public function testPrefersHtmlOverJsonInMixedAccept(): void
    {
        $mixedAcceptHeaders = [
            'application/json,text/html;q=0.9',
            'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
            'text/html, application/json',
        ];

        foreach ($mixedAcceptHeaders as $acceptHeader) {
            $this->setServerEnvironment([
                'REQUEST_URI' => '/dashboard',
                'HTTP_ACCEPT' => $acceptHeader
            ]);

            $this->assertFalse(
                BaseController::expectsJson(),
                "Should prefer HTML for mixed Accept header: {$acceptHeader}"
            );
        }
    }

    /**
     * Test AJAX request detection (XMLHttpRequest)
     */
    public function testDetectsAjaxRequests(): void
    {
        $ajaxHeaders = [
            'XMLHttpRequest',
            'xmlhttprequest', // Case insensitive
            'XmlHttpRequest',
        ];

        foreach ($ajaxHeaders as $ajaxHeader) {
            $this->setServerEnvironment([
                'REQUEST_URI' => '/zones/list',
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_X_REQUESTED_WITH' => $ajaxHeader
            ]);

            $this->assertTrue(
                BaseController::expectsJson(),
                "Failed to detect AJAX request: {$ajaxHeader}"
            );
        }
    }

    /**
     * Test edge cases and malformed inputs
     */
    public function testHandlesEdgeCases(): void
    {
        // Empty REQUEST_URI
        $this->setServerEnvironment(['REQUEST_URI' => '']);
        $this->assertFalse(BaseController::expectsJson());

        // Missing REQUEST_URI
        $this->setServerEnvironment([]);
        $this->assertFalse(BaseController::expectsJson());

        // Empty Accept header
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_ACCEPT' => ''
        ]);
        $this->assertFalse(BaseController::expectsJson());

        // Malformed Accept header
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_ACCEPT' => 'invalid-mime-type'
        ]);
        $this->assertFalse(BaseController::expectsJson());

        // Very long REQUEST_URI
        $longUri = '/test/' . str_repeat('a', 2000);
        $this->setServerEnvironment(['REQUEST_URI' => $longUri]);
        $this->assertFalse(BaseController::expectsJson());
    }

    /**
     * Test security concerns - path traversal attempts
     */
    public function testHandlesPathTraversalAttempts(): void
    {
        $maliciousUris = [
            '/api/../admin/users',
            '/api/../../etc/passwd',
            '/api/%2e%2e/admin',
            '/../api/test',
            '/api/./test',
        ];

        foreach ($maliciousUris as $uri) {
            $this->setServerEnvironment(['REQUEST_URI' => $uri]);

            // Should still detect /api/ in the path (this is by design)
            // Security should be handled at routing/authorization level
            $expectsJson = str_contains($uri, '/api/');
            $this->assertEquals(
                $expectsJson,
                BaseController::expectsJson(),
                "Unexpected result for potentially malicious URI: {$uri}"
            );
        }
    }

    /**
     * Test real-world PowerAdmin scenarios
     */
    public function testPowerAdminSpecificScenarios(): void
    {
        // Dynamic DNS API endpoints
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/v1/zones/example.com/records',
            'HTTP_ACCEPT' => 'application/json'
        ]);
        $this->assertTrue(BaseController::expectsJson());

        // Web interface zone editing
        $this->setServerEnvironment([
            'REQUEST_URI' => '/edit.php?id=123',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml'
        ]);
        $this->assertFalse(BaseController::expectsJson());

        // AJAX calls from web interface
        $this->setServerEnvironment([
            'REQUEST_URI' => '/search_zones.php',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
        ]);
        $this->assertTrue(BaseController::expectsJson());

        // PowerDNS API proxy endpoints
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/internal/pdns/servers/localhost/zones',
            'HTTP_ACCEPT' => 'application/json'
        ]);
        $this->assertTrue(BaseController::expectsJson());
    }

    /**
     * Test that API routes override Accept headers
     */
    public function testApiRoutesOverrideAcceptHeaders(): void
    {
        // Even with HTML accept header, /api/ routes should return JSON
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/v1/zones',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ]);

        $this->assertTrue(BaseController::expectsJson());

        // Verify this doesn't apply to non-API routes
        $this->setServerEnvironment([
            'REQUEST_URI' => '/zones/list',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ]);

        $this->assertFalse(BaseController::expectsJson());
    }

    /**
     * Test case sensitivity
     */
    public function testCaseSensitivity(): void
    {
        // URI paths are case sensitive
        $this->setServerEnvironment(['REQUEST_URI' => '/API/test']);
        $this->assertFalse(BaseController::expectsJson(), '/API/ should not match (case sensitive)');

        $this->setServerEnvironment(['REQUEST_URI' => '/Api/test']);
        $this->assertFalse(BaseController::expectsJson(), '/Api/ should not match (case sensitive)');

        // X-Requested-With is case insensitive
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_X_REQUESTED_WITH' => 'XMLHTTPREQUEST'
        ]);
        $this->assertTrue(BaseController::expectsJson(), 'X-Requested-With should be case insensitive');
    }

    /**
     * Test performance with various input sizes
     */
    public function testPerformanceWithVariousInputSizes(): void
    {
        $testCases = [
            ['REQUEST_URI' => '/' . str_repeat('a', 10)],
            ['REQUEST_URI' => '/' . str_repeat('a', 100)],
            ['REQUEST_URI' => '/' . str_repeat('a', 1000)],
            ['HTTP_ACCEPT' => str_repeat('application/json,', 100)],
        ];

        foreach ($testCases as $testCase) {
            $startTime = microtime(true);
            $this->setServerEnvironment($testCase);
            BaseController::expectsJson();
            $duration = microtime(true) - $startTime;

            $this->assertLessThan(0.01, $duration, 'expectsJson() should be fast even with large inputs');
        }
    }
}
