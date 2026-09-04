<?php

namespace Poweradmin\Tests\Unit\Application\Routing;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Routing\HeadlessRouteFilter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext as RoutingContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Verifies the API-only surface left behind when interface.web_enabled is false.
 */
class HeadlessRouteFilterTest extends TestCase
{
    private RouteCollection $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $configDir = __DIR__ . '/../../../../lib/Application/Config';
        $loader = new YamlFileLoader(new FileLocator([$configDir]));

        $this->routes = $loader->load('routes.yaml');
    }

    public function testApiSurfaceStaysRoutable(): void
    {
        HeadlessRouteFilter::apply($this->routes);
        $matcher = new UrlMatcher($this->routes, new RoutingContext());

        $allowed = [
            '/api/v2/zones',
            '/api/v2/zones/1/records',
            '/api/v2/users',
            '/api/docs',
            '/api/docs/json',
            '/api/health',
            '/ping',
            '/api/v1/zones',
        ];

        foreach ($allowed as $path) {
            $this->assertNotEmpty($matcher->match($path), "{$path} should stay routable");
        }
    }

    public function testWebInterfaceIsRemoved(): void
    {
        HeadlessRouteFilter::apply($this->routes);
        $matcher = new UrlMatcher($this->routes, new RoutingContext());

        $blocked = [
            '/',
            '/login',
            '/logout',
            '/zones/forward',
            '/settings/api-keys',
            '/oidc/login',
            '/api/internal/validation',
            '/style/style.css',
        ];

        foreach ($blocked as $path) {
            try {
                $matcher->match($path);
                $this->fail("{$path} should not be routable with the web interface disabled");
            } catch (ResourceNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFilterOnlyRemovesRoutes(): void
    {
        $before = $this->routes->count();
        HeadlessRouteFilter::apply($this->routes);

        $this->assertLessThan($before, $this->routes->count());
    }

    /**
     * A prefix must not swallow a longer sibling: /ping is the probe, /pingback is not.
     */
    public function testPrefixesMatchOnPathSegments(): void
    {
        $this->assertTrue(HeadlessRouteFilter::isAllowed('/ping'));
        $this->assertFalse(HeadlessRouteFilter::isAllowed('/pingback'));
        $this->assertFalse(HeadlessRouteFilter::isAllowed('/api/v20/zones'));
    }

    /**
     * The probe paths are shared with RequestContext so the session skip in index.php
     * and this filter cannot drift apart.
     */
    public function testProbePathsComeFromRequestContext(): void
    {
        foreach (RequestContext::HEALTH_PROBE_PATHS as $path) {
            $this->assertTrue(
                HeadlessRouteFilter::isAllowed($path),
                "{$path} is a declared probe but is not part of the headless surface"
            );
        }
    }
}
