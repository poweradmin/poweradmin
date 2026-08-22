<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration test to verify routing configuration is valid and complete.
 */
class RoutingConfigurationTest extends TestCase
{
    private RouteCollection $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $configDir = __DIR__ . '/../../lib/Application/Config';
        $fileLocator = new FileLocator([$configDir]);
        $loader = new YamlFileLoader($fileLocator);

        $this->routes = $loader->load('routes.yaml');
    }

    public function testRoutesFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../lib/Application/Config/routes.yaml');
    }

    public function testRoutesFileIsValid(): void
    {
        $this->assertInstanceOf(RouteCollection::class, $this->routes);
        $this->assertGreaterThan(0, $this->routes->count());
    }

    public function testEssentialWebRoutesExist(): void
    {
        $essentialRoutes = [
            'home',
            'login',
            'logout',
            'users',
            'user_edit',
            'user_add',
            'user_delete',
            'zones_forward',
            'zones_reverse',
            'zone_edit',
            'record_add',
            'record_edit',
            'search'
        ];

        foreach ($essentialRoutes as $routeName) {
            $this->assertNotNull(
                $this->routes->get($routeName),
                "Essential route '{$routeName}' should exist"
            );
        }
    }

    public function testApiV2RoutesExist(): void
    {
        $apiRoutes = [
            'api_v2_zones',
            'api_v2_zone',
            'api_v2_zone_records',
            'api_v2_zone_record',
            'api_v2_users',
            'api_v2_user',
            'api_v2_permission_templates',
            'api_v2_permission_template'
        ];

        foreach ($apiRoutes as $routeName) {
            $this->assertNotNull(
                $this->routes->get($routeName),
                "API route '{$routeName}' should exist"
            );
        }
    }

    public function testApiV1IsServedOnlyByTheGoneHandler(): void
    {
        foreach ($this->routes->all() as $routeName => $route) {
            if (!str_starts_with($route->getPath(), '/api/v1')) {
                continue;
            }

            $this->assertEquals(
                'api_v1_gone',
                $routeName,
                "API v1 route '{$routeName}' should have been removed"
            );
        }

        $goneRoute = $this->routes->get('api_v1_gone');
        $this->assertNotNull($goneRoute);
        $this->assertEquals('/api/v1/{path}', $goneRoute->getPath());
        $this->assertEmpty($goneRoute->getMethods(), 'Every verb must reach the 410 handler');
    }

    public function testInternalApiRoutesExist(): void
    {
        $internalApiRoutes = [
            'api_internal_validation',
            'api_internal_user_preferences',
            'api_internal_zone'
        ];

        foreach ($internalApiRoutes as $routeName) {
            $this->assertNotNull(
                $this->routes->get($routeName),
                "Internal API route '{$routeName}' should exist"
            );
        }
    }

    public function testApiDocsRoutesExist(): void
    {
        $this->assertNotNull($this->routes->get('api_docs'));
        $this->assertNotNull($this->routes->get('api_docs_json'));
    }

    public function testRouteParameterConstraints(): void
    {
        // Test that ID parameters have numeric constraints
        $userEditRoute = $this->routes->get('user_edit');
        $this->assertNotNull($userEditRoute);
        $this->assertEquals('\d+', $userEditRoute->getRequirement('id'));

        $zoneRecordRoute = $this->routes->get('api_v2_zone_record');
        $this->assertNotNull($zoneRecordRoute);
        $this->assertEquals('\d+', $zoneRecordRoute->getRequirement('id'));
        // record_id accepts both numeric IDs (SQL mode) and encoded strings (API mode)
        $this->assertEquals('[\w\-=.]+', $zoneRecordRoute->getRequirement('record_id'));
    }

    /**
     * Record IDs are integers with the SQL backend and encoded composite keys
     * with the API backend. Narrowing these routes to digits makes every
     * API-mode record unreachable, which is what broke in #1415.
     */
    public function testRecordRoutesMatchBothIdFormats(): void
    {
        $matcher = new UrlMatcher($this->routes, new RequestContext());

        $encoded = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        foreach ([$encoded, '42'] as $recordId) {
            $this->assertEquals('record_edit', $matcher->match("/zones/1/records/$recordId/edit")['_route']);
            $this->assertEquals('record_delete', $matcher->match("/zones/1/records/$recordId/delete")['_route']);
            $this->assertEquals('api_v2_zone_record', $matcher->match("/api/v2/zones/1/records/$recordId")['_route']);
        }
    }

    public function testRouteMethodRestrictions(): void
    {
        // Test that certain routes have proper HTTP method restrictions
        $loginRoute = $this->routes->get('login');
        $this->assertNotNull($loginRoute);
        $this->assertEquals(['GET', 'POST'], $loginRoute->getMethods());

        $apiZonesRoute = $this->routes->get('api_v2_zones');
        $this->assertNotNull($apiZonesRoute);
        $this->assertEquals(['GET', 'POST'], $apiZonesRoute->getMethods());

        $apiZoneRoute = $this->routes->get('api_v2_zone');
        $this->assertNotNull($apiZoneRoute);
        $this->assertEquals(['GET', 'PUT', 'DELETE'], $apiZoneRoute->getMethods());
    }

    public function testControllerMappings(): void
    {
        // Test that routes map to expected controllers
        $homeRoute = $this->routes->get('home');
        $this->assertEquals(
            'Poweradmin\Application\Controller\IndexController::run',
            $homeRoute->getDefault('_controller')
        );

        $loginRoute = $this->routes->get('login');
        $this->assertEquals(
            'Poweradmin\Application\Controller\LoginController::run',
            $loginRoute->getDefault('_controller')
        );

        $apiZonesRoute = $this->routes->get('api_v2_zones');
        $this->assertEquals(
            'Poweradmin\Application\Controller\Api\V2\ZonesController::run',
            $apiZonesRoute->getDefault('_controller')
        );
    }

    public function testRoutePatterns(): void
    {
        // Test that route patterns are correct
        $userEditRoute = $this->routes->get('user_edit');
        $this->assertEquals('/users/{id}/edit', $userEditRoute->getPath());

        $recordEditRoute = $this->routes->get('record_edit');
        $this->assertEquals('/zones/{zone_id}/records/{id}/edit', $recordEditRoute->getPath());

        $apiZoneRecordRoute = $this->routes->get('api_v2_zone_record');
        $this->assertEquals('/api/v2/zones/{id}/records/{record_id}', $apiZoneRecordRoute->getPath());
    }

    public function testNoRouteConflicts(): void
    {
        // Test that there are no route pattern conflicts
        $patterns = [];

        foreach ($this->routes->all() as $name => $route) {
            $pattern = $route->getPath();

            // Skip checking for conflicts on patterns with different parameter names
            // as Symfony handles these correctly
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = $name;
            } else {
                // If same pattern exists, check if methods are different
                $existingRoute = $this->routes->get($patterns[$pattern]);
                $currentMethods = $route->getMethods();
                $existingMethods = $existingRoute->getMethods();

                // If methods overlap, it's a conflict
                $overlap = array_intersect($currentMethods ?: ['GET'], $existingMethods ?: ['GET']);
                $this->assertEmpty(
                    $overlap,
                    "Route conflict: '{$name}' and '{$patterns[$pattern]}' have same pattern '{$pattern}' and overlapping methods"
                );
            }
        }

        $this->assertNotEmpty($patterns, 'Routes should be loaded');
    }

    public function testRoutePerformance(): void
    {
        // Test that route collection compiles efficiently
        $startTime = microtime(true);

        // Simulate route matching performance
        for ($i = 0; $i < 100; $i++) {
            $this->routes->get('home');
            $this->routes->get('api_v2_zones');
            $this->routes->get('user_edit');
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Route operations should be fast (under 1 second for 300 operations)
        $this->assertLessThan(1.0, $executionTime, 'Route operations should be performant');
    }

    public function testAllControllersExist(): void
    {
        foreach ($this->routes->all() as $name => $route) {
            $controller = $route->getDefault('_controller');

            if ($controller && str_contains($controller, '::')) {
                [$className, $method] = explode('::', $controller);

                $this->assertTrue(
                    class_exists($className),
                    "Controller class '{$className}' for route '{$name}' should exist"
                );
            }
        }
    }

    /**
     * index.php skips session start for these paths before the router has run, so the
     * literals in RequestContext are a second copy of what routes.yaml declares. Renaming
     * a route without updating them would silently restore a session file per scrape.
     */
    public function testHealthProbePathsMatchTheDeclaredRoutes(): void
    {
        $routes = Yaml::parseFile(__DIR__ . '/../../lib/Application/Config/routes.yaml');

        foreach (['api_health', 'ping'] as $name) {
            $this->assertArrayHasKey($name, $routes, "Route $name is missing from routes.yaml");

            $_SERVER['REQUEST_URI'] = $routes[$name]['path'];
            $this->assertTrue(
                \Poweradmin\Application\Http\RequestContext::isHealthProbeRequest(),
                sprintf('%s is routed at %s but RequestContext does not treat it as a probe', $name, $routes[$name]['path'])
            );
        }
    }
}
