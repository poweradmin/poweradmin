<?php

namespace integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Routing\SymfonyRouter;

/**
 * Comprehensive integration test for SymfonyRouter to ensure it handles
 * all routing scenarios correctly and provides complete functionality.
 */
class SymfonyRouterIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up minimal $_SERVER variables for SymfonyRouter
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
    }

    private function createRouter(): SymfonyRouter
    {
        return new SymfonyRouter();
    }

    public function testHomePageRouting(): void
    {
        $_SERVER['REQUEST_URI'] = '/';

        $router = $this->createRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\IndexController', $routeInfo['controller']);
        $this->assertEquals('home', $routeInfo['route']);
        $this->assertTrue($router->isRouteFound());
    }

    public function testLoginPageRouting(): void
    {
        $_SERVER['REQUEST_URI'] = '/login';
        $router = $this->createRouter();

        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\LoginController', $routeInfo['controller']);
        $this->assertEquals('login', $routeInfo['route']);
        $this->assertTrue($router->isRouteFound());
    }

    public function testLogoutPageRouting(): void
    {
        $_SERVER['REQUEST_URI'] = '/logout';

        $router = $this->createRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\LogoutController', $routeInfo['controller']);
        $this->assertEquals('logout', $routeInfo['route']);
        $this->assertTrue($router->isRouteFound());
    }

    public function testUserManagementRoutes(): void
    {
        // Users list
        $_SERVER['REQUEST_URI'] = '/users';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\UsersController', $routeInfo['controller']);
        $this->assertEquals('users', $routeInfo['route']);

        // Add user
        $_SERVER['REQUEST_URI'] = '/users/add';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\AddUserController', $routeInfo['controller']);
        $this->assertEquals('user_add', $routeInfo['route']);

        // Edit user with ID parameter
        $_SERVER['REQUEST_URI'] = '/users/123/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\EditUserController', $routeInfo['controller']);
        $this->assertEquals('user_edit', $routeInfo['route']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);

        // Delete user with ID parameter
        $_SERVER['REQUEST_URI'] = '/users/456/delete';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\DeleteUserController', $routeInfo['controller']);
        $this->assertEquals('user_delete', $routeInfo['route']);
        $this->assertEquals(['id' => '456'], $routeInfo['parameters']);
    }

    public function testZoneManagementRoutes(): void
    {
        // Forward zones
        $_SERVER['REQUEST_URI'] = '/zones/forward';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\ListForwardZonesController', $routeInfo['controller']);
        $this->assertEquals('zones_forward', $routeInfo['route']);

        // Reverse zones
        $_SERVER['REQUEST_URI'] = '/zones/reverse';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\ListReverseZonesController', $routeInfo['controller']);
        $this->assertEquals('zones_reverse', $routeInfo['route']);

        // Add master zone
        $_SERVER['REQUEST_URI'] = '/zones/add/master';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\AddZoneMasterController', $routeInfo['controller']);
        $this->assertEquals('zone_add_master', $routeInfo['route']);

        // Add slave zone
        $_SERVER['REQUEST_URI'] = '/zones/add/slave';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\AddZoneSlaveController', $routeInfo['controller']);
        $this->assertEquals('zone_add_slave', $routeInfo['route']);

        // Edit zone with ID
        $_SERVER['REQUEST_URI'] = '/zones/789/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\EditController', $routeInfo['controller']);
        $this->assertEquals('zone_edit', $routeInfo['route']);
        $this->assertEquals(['id' => '789'], $routeInfo['parameters']);
    }

    public function testMfaRoutes(): void
    {
        // MFA setup
        $_SERVER['REQUEST_URI'] = '/mfa/setup';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\MfaSetupController', $routeInfo['controller']);
        $this->assertEquals('mfa_setup', $routeInfo['route']);

        // MFA verify
        $_SERVER['REQUEST_URI'] = '/mfa/verify';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\MfaVerifyController', $routeInfo['controller']);
        $this->assertEquals('mfa_verify', $routeInfo['route']);
    }

    public function testApiV1Routes(): void
    {
        // API zones collection
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\Api\V1\ZonesController', $routeInfo['controller']);
        $this->assertEquals('api_v1_zones', $routeInfo['route']);

        // API specific zone
        $_SERVER['REQUEST_URI'] = '/api/v1/zones/123';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\Api\V1\ZonesController', $routeInfo['controller']);
        $this->assertEquals('api_v1_zone', $routeInfo['route']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);

        // API zone records collection
        $_SERVER['REQUEST_URI'] = '/api/v1/zones/456/records';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\Api\V1\ZonesRecordsController', $routeInfo['controller']);
        $this->assertEquals('api_v1_zone_records', $routeInfo['route']);
        $this->assertEquals(['id' => '456'], $routeInfo['parameters']);

        // API specific zone record
        $_SERVER['REQUEST_URI'] = '/api/v1/zones/123/records/789';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\Api\V1\ZonesRecordsController', $routeInfo['controller']);
        $this->assertEquals('api_v1_zone_record', $routeInfo['route']);
        $this->assertEquals([
            'zone_id' => '123',
            'record_id' => '789'
        ], $routeInfo['parameters']);
    }

    public function test404Handling(): void
    {
        $_SERVER['REQUEST_URI'] = '/nonexistent-page';
        $router = $this->createRouter();
        $routeInfo = $router->match();

        $this->assertEquals('\Poweradmin\Application\Controller\NotFoundController', $routeInfo['controller']);
        $this->assertFalse($router->isRouteFound());
    }

    public function testHttpMethodHandling(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        // GET request (index/list)
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('run', $routeInfo['method']);

        // POST request (create)
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('run', $routeInfo['method']);

        // Test specific resource with PUT/DELETE
        $_SERVER['REQUEST_URI'] = '/api/v1/zones/123';

        // PUT request (update)
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('run', $routeInfo['method']);

        // DELETE request (delete)
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('run', $routeInfo['method']);
    }

    public function testUrlGeneration(): void
    {
        $router = $this->createRouter();

        // Test user edit URL generation
        $url = $router->generateUrl('user_edit', ['id' => 123]);
        $this->assertEquals('/users/123/edit', $url);

        // Test API zone record URL generation
        $url = $router->generateUrl('api_v1_zone_record', ['zone_id' => 456, 'record_id' => 789]);
        $this->assertEquals('/api/v1/zones/456/records/789', $url);

        // Test simple route without parameters
        $url = $router->generateUrl('login');
        $this->assertEquals('/login', $url);

        // Test zone edit URL
        $url = $router->generateUrl('zone_edit', ['id' => 999]);
        $this->assertEquals('/zones/999/edit', $url);
    }

    public function testRouteParameterConstraints(): void
    {
        // Valid numeric ID should work
        $_SERVER['REQUEST_URI'] = '/users/123/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertTrue($router->isRouteFound());
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);

        // Invalid non-numeric ID should return 404
        $_SERVER['REQUEST_URI'] = '/users/abc/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertFalse($router->isRouteFound());
    }

    public function testRecordManagementRoutes(): void
    {
        // Add record to zone
        $_SERVER['REQUEST_URI'] = '/zones/123/records/add';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\AddRecordController', $routeInfo['controller']);
        $this->assertEquals('record_add', $routeInfo['route']);
        $this->assertEquals(['zone_id' => '123'], $routeInfo['parameters']);

        // Edit specific record
        $_SERVER['REQUEST_URI'] = '/zones/456/records/789/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\EditRecordController', $routeInfo['controller']);
        $this->assertEquals('record_edit', $routeInfo['route']);
        $this->assertEquals([
            'zone_id' => '456',
            'id' => '789'
        ], $routeInfo['parameters']);

        // Delete specific record
        $_SERVER['REQUEST_URI'] = '/zones/111/records/222/delete';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\DeleteRecordController', $routeInfo['controller']);
        $this->assertEquals('record_delete', $routeInfo['route']);
        $this->assertEquals([
            'zone_id' => '111',
            'id' => '222'
        ], $routeInfo['parameters']);
    }

    public function testDnssecRoutes(): void
    {
        // DNSSEC management for zone
        $_SERVER['REQUEST_URI'] = '/zones/123/dnssec';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\DnssecController', $routeInfo['controller']);
        $this->assertEquals('dnssec', $routeInfo['route']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);

        // Add DNSSEC key
        $_SERVER['REQUEST_URI'] = '/zones/456/dnssec/keys/add';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\DnssecAddKeyController', $routeInfo['controller']);
        $this->assertEquals('dnssec_add_key', $routeInfo['route']);
        $this->assertEquals(['id' => '456'], $routeInfo['parameters']);

        // Edit DNSSEC key
        $_SERVER['REQUEST_URI'] = '/zones/789/dnssec/keys/111/edit';
        $router = $this->createRouter();
        $routeInfo = $router->match();
        $this->assertEquals('Poweradmin\Application\Controller\DnssecEditKeyController', $routeInfo['controller']);
        $this->assertEquals('dnssec_edit_key', $routeInfo['route']);
        $this->assertEquals([
            'zone_id' => '789',
            'key_id' => '111'
        ], $routeInfo['parameters']);
    }

    public function testPerformance(): void
    {
        // Simple performance test to ensure reasonable performance
        $iterations = 100;

        $_SERVER['REQUEST_URI'] = '/';
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $router = new SymfonyRouter();
            $router->match();
        }

        $executionTime = microtime(true) - $startTime;

        // Should complete 100 iterations in reasonable time (under 1 second)
        $this->assertLessThan(1.0, $executionTime, "SymfonyRouter should handle $iterations iterations in under 1 second");
    }

    public function testControllerMethodExecution(): void
    {
        $_SERVER['REQUEST_URI'] = '/';

        $router = $this->createRouter();
        $routeInfo = $router->match();

        // Should have all required information to execute controller
        $this->assertArrayHasKey('controller', $routeInfo);
        $this->assertArrayHasKey('method', $routeInfo);
        $this->assertArrayHasKey('route', $routeInfo);
        $this->assertArrayHasKey('parameters', $routeInfo);

        // Controller should be a valid class name
        $this->assertStringContainsString('Controller', $routeInfo['controller']);

        // Method should be 'run' for non-API routes
        $this->assertEquals('run', $routeInfo['method']);
    }

    protected function tearDown(): void
    {
        // Clean up $_SERVER variables
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['REQUEST_URI']);
        unset($_SERVER['SERVER_NAME']);
        unset($_SERVER['SERVER_PORT']);
        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }
}
