<?php

namespace integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Integration test to verify routing configuration is valid and complete.
 */
class RoutingConfigurationTest extends TestCase
{
    private RouteCollection $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $configDir = __DIR__ . '/../../config';
        $fileLocator = new FileLocator([$configDir]);
        $loader = new YamlFileLoader($fileLocator);

        $this->routes = $loader->load('routes.yaml');
    }

    public function testRoutesFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../config/routes.yaml');
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

    public function testApiV1RoutesExist(): void
    {
        $apiRoutes = [
            'api_v1_zones',
            'api_v1_zone',
            'api_v1_zone_records',
            'api_v1_zone_record',
            'api_v1_users',
            'api_v1_user',
            'api_v1_permission_templates',
            'api_v1_permission_template'
        ];

        foreach ($apiRoutes as $routeName) {
            $this->assertNotNull(
                $this->routes->get($routeName),
                "API route '{$routeName}' should exist"
            );
        }
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

        $zoneRecordRoute = $this->routes->get('api_v1_zone_record');
        $this->assertNotNull($zoneRecordRoute);
        $this->assertEquals('\d+', $zoneRecordRoute->getRequirement('zone_id'));
        $this->assertEquals('\d+', $zoneRecordRoute->getRequirement('record_id'));
    }

    public function testRouteMethodRestrictions(): void
    {
        // Test that certain routes have proper HTTP method restrictions
        $loginRoute = $this->routes->get('login');
        $this->assertNotNull($loginRoute);
        $this->assertEquals(['GET', 'POST'], $loginRoute->getMethods());

        $apiZonesRoute = $this->routes->get('api_v1_zones');
        $this->assertNotNull($apiZonesRoute);
        $this->assertEquals(['GET', 'POST'], $apiZonesRoute->getMethods());

        $apiZoneRoute = $this->routes->get('api_v1_zone');
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

        $apiZonesRoute = $this->routes->get('api_v1_zones');
        $this->assertEquals(
            'Poweradmin\Application\Controller\Api\V1\ZonesController::run',
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

        $apiZoneRecordRoute = $this->routes->get('api_v1_zone_record');
        $this->assertEquals('/api/v1/zones/{zone_id}/records/{record_id}', $apiZoneRecordRoute->getPath());
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
    }

    public function testRoutePerformance(): void
    {
        // Test that route collection compiles efficiently
        $startTime = microtime(true);

        // Simulate route matching performance
        for ($i = 0; $i < 100; $i++) {
            $this->routes->get('home');
            $this->routes->get('api_v1_zones');
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
}
