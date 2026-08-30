<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 */

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RequestContext;

/**
 * The API-route detectors must key off the real routed path, not requestData['page']
 * (which the router never sets for API routes and which ?page= could spoof) - audit M18.
 * Also covers expectsJson() response negotiation (API routes, Accept header, AJAX).
 */
class RequestContextTest extends TestCase
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

    private function setServerEnvironment(array $vars): void
    {
        unset(
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_ACCEPT'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['HTTP_CONTENT_TYPE']
        );

        foreach ($vars as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    public function testInternalApiRouteDetected(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/internal/zones';
        $this->assertTrue(RequestContext::isInternalApiRoute());
        $this->assertTrue(RequestContext::isApiRequest());
    }

    public function testPublicApiRouteDetected(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';
        $this->assertTrue(RequestContext::isApiRequest());

        $_SERVER['REQUEST_URI'] = '/api/v2/zones';
        $this->assertFalse(RequestContext::isInternalApiRoute());
    }

    public function testSubfolderApiRouteDetected(): void
    {
        $_SERVER['REQUEST_URI'] = '/poweradmin/api/internal/user';
        $this->assertTrue(RequestContext::isInternalApiRoute());

        $_SERVER['REQUEST_URI'] = '/poweradmin/api/v2/zones';
        $this->assertTrue(RequestContext::isApiRequest());
    }

    public function testQueryStringDoesNotFlipDetection(): void
    {
        // The router never sets 'page' for API routes; a spoofed ?page= in the query
        // string must not make a web page look like an API route.
        $_SERVER['REQUEST_URI'] = '/index.php?page=api/internal/x';
        $this->assertFalse(RequestContext::isApiRequest());

        $_SERVER['REQUEST_URI'] = '/zones?return=/api/internal/x';
        $this->assertFalse(RequestContext::isInternalApiRoute());
    }

    public function testHtmlPageContainingApiSegmentIsNotApi(): void
    {
        $_SERVER['REQUEST_URI'] = '/settings/api/logs';
        $this->assertFalse(RequestContext::isApiRequest());
        $this->assertFalse(RequestContext::isInternalApiRoute());

        // Without this the API Logs page answered its permission denial as raw
        // JSON, because any path merely containing "/api/" negotiated as an API.
        $this->assertFalse(RequestContext::isApiPath());
        $this->assertFalse(RequestContext::expectsJson());
    }

    /**
     * isApiPath() drives JSON negotiation, so it has to cover every family under
     * /api/ - including docs and health, which isApiRequest() deliberately omits.
     */
    public function testIsApiPathCoversEveryApiFamily(): void
    {
        $apiPaths = [
            '/api/v1/zones',
            '/api/v2/zones/1',
            '/api/internal/zone',
            '/api/docs',
            '/api/docs/json',
            '/api/health',
            '/poweradmin/api/v2/zones', // base_url_prefix deployment
        ];

        foreach ($apiPaths as $path) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertTrue(RequestContext::isApiPath(), "should be an API path: {$path}");
        }

        $webPaths = [
            '/settings/api/logs',
            '/settings/api-keys',
            '/zones/forward',
            '/apidocs',
            '/api/logs',
        ];

        foreach ($webPaths as $path) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertFalse(RequestContext::isApiPath(), "should not be an API path: {$path}");
        }
    }

    public function testV2ApiRequestDetected(): void
    {
        $this->assertV2('/api/v2/zones');
        $this->assertV2('/api/v2/zones/1/records');
        $this->assertV2('/poweradmin/api/v2/zones');
        // Bare collection root with no trailing slash still counts as v2.
        $this->assertV2('/api/v2');
    }

    public function testV2ApiRequestRejectsNonV2AndSpoofedQuery(): void
    {
        $this->assertNotV2('/api/v1/zones');
        $this->assertNotV2('/api/internal/zones');
        $this->assertNotV2('/zones');
        // A query string that merely mentions the v2 path must not flip detection.
        $this->assertNotV2('/index.php?return=/api/v2/zones');
        $this->assertNotV2('/settings/api/v2things');
    }

    private function assertV2(string $requestUri): void
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        $this->assertTrue(RequestContext::isV2ApiRequest(), "Expected v2 for $requestUri");
    }

    private function assertNotV2(string $requestUri): void
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        $this->assertFalse(RequestContext::isV2ApiRequest(), "Expected non-v2 for $requestUri");
    }

    public function testExpectsJsonDetectsApiRoutes(): void
    {
        $apiRoutes = [
            '/api/v1/zones',
            '/api/v1/records',
            '/api/v2/zones',
            '/api/internal/stats',
            '/api/docs',
            '/api/health'
        ];

        foreach ($apiRoutes as $route) {
            $this->setServerEnvironment([
                'REQUEST_URI' => $route,
                'HTTP_ACCEPT' => 'text/html'  // Even with HTML accept, API routes should return JSON
            ]);

            $this->assertTrue(
                RequestContext::expectsJson(),
                "Failed to detect API route: {$route}"
            );
        }

        // An /api/ segment that names no API family routes nowhere, so it must not
        // negotiate as JSON: the real instance of this shape is the web page
        // /settings/api/logs, which used to answer its permission denial as JSON.
        foreach (['/api/', '/some/path/api/endpoint'] as $notAnApiRoute) {
            $this->setServerEnvironment([
                'REQUEST_URI' => $notAnApiRoute,
                'HTTP_ACCEPT' => 'text/html'
            ]);

            $this->assertFalse(
                RequestContext::expectsJson(),
                "Should not detect as API route: {$notAnApiRoute}"
            );
        }

        // Test edge case: /api without trailing slash should NOT be detected
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api',
            'HTTP_ACCEPT' => 'text/html'
        ]);
        $this->assertFalse(
            RequestContext::expectsJson(),
            "Should not detect '/api' without trailing slash as API route"
        );
    }

    public function testExpectsJsonDoesNotDetectNonApiRoutes(): void
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
                RequestContext::expectsJson(),
                "Incorrectly detected web route as API: {$route}"
            );
        }
    }

    public function testExpectsJsonDetectsJsonAcceptHeader(): void
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
                RequestContext::expectsJson(),
                "Failed to detect JSON Accept header: {$acceptHeader}"
            );
        }
    }

    public function testExpectsJsonPrefersHtmlOverJsonInMixedAccept(): void
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
                RequestContext::expectsJson(),
                "Should prefer HTML for mixed Accept header: {$acceptHeader}"
            );
        }
    }

    public function testExpectsJsonDetectsAjaxRequests(): void
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
                RequestContext::expectsJson(),
                "Failed to detect AJAX request: {$ajaxHeader}"
            );
        }
    }

    public function testExpectsJsonHandlesEdgeCases(): void
    {
        // Empty REQUEST_URI
        $this->setServerEnvironment(['REQUEST_URI' => '']);
        $this->assertFalse(RequestContext::expectsJson());

        // Missing REQUEST_URI
        $this->setServerEnvironment([]);
        $this->assertFalse(RequestContext::expectsJson());

        // Empty Accept header
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_ACCEPT' => ''
        ]);
        $this->assertFalse(RequestContext::expectsJson());

        // Malformed Accept header
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_ACCEPT' => 'invalid-mime-type'
        ]);
        $this->assertFalse(RequestContext::expectsJson());

        // Very long REQUEST_URI
        $longUri = '/test/' . str_repeat('a', 2000);
        $this->setServerEnvironment(['REQUEST_URI' => $longUri]);
        $this->assertFalse(RequestContext::expectsJson());
    }

    public function testExpectsJsonHandlesPathTraversalAttempts(): void
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

            // None of these name an API family, so they negotiate as HTML. They route
            // nowhere either way, so the response format is all that changes; security
            // stays a routing/authorization concern, not a negotiation one.
            $this->assertFalse(
                RequestContext::expectsJson(),
                "Unexpected result for potentially malicious URI: {$uri}"
            );
        }
    }

    public function testExpectsJsonPowerAdminSpecificScenarios(): void
    {
        // Dynamic DNS API endpoints
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/v1/zones/example.com/records',
            'HTTP_ACCEPT' => 'application/json'
        ]);
        $this->assertTrue(RequestContext::expectsJson());

        // Web interface zone editing
        $this->setServerEnvironment([
            'REQUEST_URI' => '/edit.php?id=123',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml'
        ]);
        $this->assertFalse(RequestContext::expectsJson());

        // AJAX calls from web interface
        $this->setServerEnvironment([
            'REQUEST_URI' => '/search_zones.php',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
        ]);
        $this->assertTrue(RequestContext::expectsJson());

        // PowerDNS API proxy endpoints
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/internal/pdns/servers/localhost/zones',
            'HTTP_ACCEPT' => 'application/json'
        ]);
        $this->assertTrue(RequestContext::expectsJson());
    }

    public function testExpectsJsonApiRoutesOverrideAcceptHeaders(): void
    {
        // Even with HTML accept header, /api/ routes should return JSON
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/v1/zones',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ]);

        $this->assertTrue(RequestContext::expectsJson());

        // Verify this doesn't apply to non-API routes
        $this->setServerEnvironment([
            'REQUEST_URI' => '/zones/list',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ]);

        $this->assertFalse(RequestContext::expectsJson());
    }

    public function testExpectsJsonCaseSensitivity(): void
    {
        // URI paths are case sensitive
        $this->setServerEnvironment(['REQUEST_URI' => '/API/test']);
        $this->assertFalse(RequestContext::expectsJson(), '/API/ should not match (case sensitive)');

        $this->setServerEnvironment(['REQUEST_URI' => '/Api/test']);
        $this->assertFalse(RequestContext::expectsJson(), '/Api/ should not match (case sensitive)');

        // X-Requested-With is case insensitive
        $this->setServerEnvironment([
            'REQUEST_URI' => '/test',
            'HTTP_X_REQUESTED_WITH' => 'XMLHTTPREQUEST'
        ]);
        $this->assertTrue(RequestContext::expectsJson(), 'X-Requested-With should be case insensitive');
    }

    public function testExpectsJsonPerformanceWithVariousInputSizes(): void
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
            RequestContext::expectsJson();
            $duration = microtime(true) - $startTime;

            $this->assertLessThan(0.01, $duration, 'expectsJson() should be fast even with large inputs');
        }
    }

    public function testIsApiPathIgnoresTheQueryString(): void
    {
        // A web page carrying an API URL as a parameter must still render as HTML
        $this->setServerEnvironment(['REQUEST_URI' => '/zones/1/edit?next=/api/v1/zones']);
        $this->assertFalse(RequestContext::isApiPath());
        $this->assertFalse(RequestContext::expectsJson());

        $this->setServerEnvironment(['REQUEST_URI' => '/index.php?return=/api/internal/user']);
        $this->assertFalse(RequestContext::isApiPath());
        $this->assertFalse(RequestContext::expectsJson());
    }

    public function testIsApiPathMatchesRealApiPaths(): void
    {
        $this->setServerEnvironment(['REQUEST_URI' => '/api/v1/zones?page=2']);
        $this->assertTrue(RequestContext::isApiPath());

        $this->setServerEnvironment(['REQUEST_URI' => '/poweradmin/api/internal/user']);
        $this->assertTrue(RequestContext::isApiPath());

        $this->setServerEnvironment(['REQUEST_URI' => '/apidocs']);
        $this->assertFalse(RequestContext::isApiPath());
    }

    public function testAcceptsJsonDistinguishesFromAcceptsJsonOnly(): void
    {
        $this->setServerEnvironment(['HTTP_ACCEPT' => 'application/json']);
        $this->assertTrue(RequestContext::acceptsJson());
        $this->assertTrue(RequestContext::acceptsJsonOnly());

        $this->setServerEnvironment(['HTTP_ACCEPT' => 'text/html, application/json']);
        $this->assertTrue(RequestContext::acceptsJson());
        $this->assertFalse(RequestContext::acceptsJsonOnly());

        $this->setServerEnvironment(['HTTP_ACCEPT' => 'text/html']);
        $this->assertFalse(RequestContext::acceptsJson());
        $this->assertFalse(RequestContext::acceptsJsonOnly());
    }

    public function testExpectsJsonOnErrorIgnoresTheHtmlExclusion(): void
    {
        $this->setServerEnvironment([
            'REQUEST_URI' => '/dashboard',
            'HTTP_ACCEPT' => 'text/html,application/json;q=0.8'
        ]);

        $this->assertFalse(RequestContext::expectsJson());
        $this->assertTrue(RequestContext::expectsJsonOnError());
    }

    public function testExpectsJsonOnErrorIgnoresTheJsonContentType(): void
    {
        $this->setServerEnvironment([
            'REQUEST_URI' => '/dashboard',
            'HTTP_ACCEPT' => 'text/html',
            'CONTENT_TYPE' => 'application/json'
        ]);

        $this->assertTrue(RequestContext::expectsJson());
        $this->assertFalse(RequestContext::expectsJsonOnError());
    }

    public function testExpectsJsonOnErrorDetectsApiPathAndAjax(): void
    {
        $this->setServerEnvironment([
            'REQUEST_URI' => '/api/v1/zones',
            'HTTP_ACCEPT' => 'text/html'
        ]);
        $this->assertTrue(RequestContext::expectsJsonOnError());

        $this->setServerEnvironment([
            'REQUEST_URI' => '/zones',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
        ]);
        $this->assertTrue(RequestContext::expectsJsonOnError());

        $this->setServerEnvironment([
            'REQUEST_URI' => '/zones',
            'HTTP_ACCEPT' => 'text/html'
        ]);
        $this->assertFalse(RequestContext::expectsJsonOnError());
    }

    public function testIsHealthProbeRequestMatchesOnlyTheExactPaths(): void
    {
        $this->setServerEnvironment(['REQUEST_URI' => '/ping']);
        $this->assertTrue(RequestContext::isHealthProbeRequest());

        $this->setServerEnvironment(['REQUEST_URI' => '/api/health?verbose=1']);
        $this->assertTrue(RequestContext::isHealthProbeRequest());

        $this->setServerEnvironment(['REQUEST_URI' => '/poweradmin/api/health']);
        $this->assertTrue(RequestContext::isHealthProbeRequest('/poweradmin'));

        $this->setServerEnvironment(['REQUEST_URI' => '/poweradmin/ping']);
        $this->assertTrue(RequestContext::isHealthProbeRequest('/poweradmin'));

        // A prefix configured with a trailing slash must not double it.
        $this->assertTrue(RequestContext::isHealthProbeRequest('/poweradmin/'));
    }

    /**
     * A near miss must keep its session - denying one to an ordinary page would
     * break it, so the match is exact rather than a substring.
     */
    public function testIsHealthProbeRequestIgnoresLookalikePaths(): void
    {
        foreach (['/zones/ping', '/pinger', '/api/healthcheck', '/ping/', '/api/health/x'] as $path) {
            $this->setServerEnvironment(['REQUEST_URI' => $path]);
            $this->assertFalse(
                RequestContext::isHealthProbeRequest(),
                sprintf('%s must not be treated as a probe path', $path)
            );
        }

        // Prefix deployments must not match the unprefixed path either.
        $this->setServerEnvironment(['REQUEST_URI' => '/ping']);
        $this->assertFalse(RequestContext::isHealthProbeRequest('/poweradmin'));
    }
}
