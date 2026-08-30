<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Routing\SymfonyRouter;
use Symfony\Component\HttpFoundation\Request;

class SymfonyRouterTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Mock request to avoid relying on globals
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
    }

    public function testHomeRouteMatching(): void
    {
        $_SERVER['REQUEST_URI'] = '/';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\IndexController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals('home', $routeInfo['route']);
        $this->assertTrue($router->isRouteFound());
    }

    public function testLoginRouteMatching(): void
    {
        $_SERVER['REQUEST_URI'] = '/login';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\LoginController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals('login', $routeInfo['route']);
    }

    public function testUserEditRouteWithParameters(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/123/edit';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\EditUserController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);
        $this->assertEquals('user_edit', $routeInfo['route']);
    }

    public function testZoneRecordEditRouteWithMultipleParameters(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones/456/records/789/edit';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\EditRecordController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals(['zone_id' => '456', 'id' => '789'], $routeInfo['parameters']);
        $this->assertEquals('record_edit', $routeInfo['route']);
    }

    public function testApiV2ZonesRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\V2\ZonesController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']); // API routes use run method
        $this->assertEquals('api_v2_zones', $routeInfo['route']);
    }

    public function testApiV2ZoneWithIdRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/123';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\V2\ZonesController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);
        $this->assertEquals('api_v2_zone', $routeInfo['route']);
    }

    public function testApiV2ZoneRecordsRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/123/records';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\V2\ZonesRecordsController', $routeInfo['controller']);
        $this->assertEquals(['id' => '123'], $routeInfo['parameters']);
        $this->assertEquals('api_v2_zone_records', $routeInfo['route']);
    }

    public function testApiV2ZoneRecordWithIdRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/123/records/456';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\V2\ZonesRecordsController', $routeInfo['controller']);
        $this->assertEquals(['id' => '123', 'record_id' => '456'], $routeInfo['parameters']);
        $this->assertEquals('api_v2_zone_record', $routeInfo['route']);
    }

    #[DataProvider('removedApiV1PathProvider')]
    public function testRemovedApiV1PathsResolveToGoneController(string $method, string $path): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\V1GoneController', $routeInfo['controller']);
        $this->assertEquals('api_v1_gone', $routeInfo['route']);
    }

    public static function removedApiV1PathProvider(): array
    {
        return [
            'bare root' => ['GET', '/api/v1'],
            'trailing slash' => ['GET', '/api/v1/'],
            'removed collection' => ['GET', '/api/v1/zones'],
            'removed write' => ['POST', '/api/v1/zones'],
            'removed update' => ['PUT', '/api/v1/users/1'],
            'removed delete' => ['DELETE', '/api/v1/permission-templates/2'],
            'unknown deep path' => ['PATCH', '/api/v1/anything/deep'],
        ];
    }

    public function testHttpMethodMappingGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('run', $routeInfo['method']);
    }

    public function testHttpMethodMappingPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('run', $routeInfo['method']);
    }

    public function testHttpMethodMappingPut(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/123';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('run', $routeInfo['method']);
    }

    public function testHttpMethodMappingDelete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/123';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('run', $routeInfo['method']);
    }

    public function testNonExistentRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/non-existent-route';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('\Poweradmin\Application\Controller\NotFoundController', $routeInfo['controller']);
        $this->assertEquals('run', $routeInfo['method']);
        $this->assertEquals('404', $routeInfo['route']);
        $this->assertFalse($router->isRouteFound());
    }

    public function testIsApiRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $router = new SymfonyRouter();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('isApiRoute');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($router));
    }

    public function testIsNotApiRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/users';

        $router = new SymfonyRouter();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('isApiRoute');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($router));
    }

    public function testUrlGeneration(): void
    {
        $router = new SymfonyRouter();

        $url = $router->generateUrl('user_edit', ['id' => 123]);
        $this->assertEquals('/users/123/edit', $url);

        $url = $router->generateUrl('api_v2_zone_record', ['id' => 456, 'record_id' => 789]);
        $this->assertEquals('/api/v2/zones/456/records/789', $url);
    }

    public function testGetRequest(): void
    {
        $router = new SymfonyRouter();
        $request = $router->getRequest();

        $this->assertInstanceOf(Request::class, $request);
    }

    public function testGetRouteParameters(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/123/edit';

        $router = new SymfonyRouter();
        $router->match(); // Need to match first to populate parameters

        $parameters = $router->getRouteParameters();
        $this->assertArrayHasKey('id', $parameters);
        $this->assertEquals('123', $parameters['id']);
    }

    public function testParameterConstraints(): void
    {
        // Test that non-numeric ID gets 404
        $_SERVER['REQUEST_URI'] = '/users/abc/edit';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('\Poweradmin\Application\Controller\NotFoundController', $routeInfo['controller']);
        $this->assertFalse($router->isRouteFound());
    }

    public function testDnssecKeyToggleRejectsGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/zones/1/dnssec/keys/2/toggle';

        $router = new SymfonyRouter();

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(405);
        $router->match();
    }

    public function testDnssecKeyTogglePostStillMatches(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/zones/1/dnssec/keys/2/toggle';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('dnssec_key_toggle', $routeInfo['route']);
    }

    public function testDnssecEditKeyRejectsPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/zones/1/dnssec/keys/2/edit';

        $router = new SymfonyRouter();

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(405);
        $router->match();
    }

    public function testRrsetTypeRejectsNonAlphanumeric(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones/1/rrsets/www/A.json';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('404', $routeInfo['route']);
        $this->assertFalse($router->isRouteFound());
    }

    #[DataProvider('validRrsetPathProvider')]
    public function testRrsetPathsStillMatch(string $path, array $expectedParameters): void
    {
        $_SERVER['REQUEST_URI'] = $path;

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('api_v2_zone_rrset', $routeInfo['route']);
        $this->assertEquals($expectedParameters, $routeInfo['parameters']);
    }

    public static function validRrsetPathProvider(): array
    {
        return [
            'plain type' => ['/api/v2/zones/1/rrsets/www/A', ['id' => '1', 'name' => 'www', 'type' => 'A']],
            'generic type' => ['/api/v2/zones/1/rrsets/www/TYPE65534', ['id' => '1', 'name' => 'www', 'type' => 'TYPE65534']],
            'wildcard name' => ['/api/v2/zones/1/rrsets/*.example.com/A', ['id' => '1', 'name' => '*.example.com', 'type' => 'A']],
            'underscore label' => ['/api/v2/zones/1/rrsets/_dmarc.example.com/TXT', ['id' => '1', 'name' => '_dmarc.example.com', 'type' => 'TXT']],
            'punycode name' => ['/api/v2/zones/1/rrsets/xn--bcher-kva.example.com/AAAA', ['id' => '1', 'name' => 'xn--bcher-kva.example.com', 'type' => 'AAAA']],
        ];
    }

    public function testApiDocsRoutes(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/docs';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\DocsController', $routeInfo['controller']);
        $this->assertEquals('api_docs', $routeInfo['route']);
    }

    public function testApiDocsJsonRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/docs/json';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\Docs\JsonController', $routeInfo['controller']);
        $this->assertEquals('api_docs_json', $routeInfo['route']);
    }

    public function testInternalApiRoutes(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/internal/validation';

        $router = new SymfonyRouter();
        $routeInfo = $router->match();

        $this->assertEquals('Poweradmin\Application\Controller\Api\Internal\ValidationController', $routeInfo['controller']);
        $this->assertEquals('api_internal_validation', $routeInfo['route']);
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
