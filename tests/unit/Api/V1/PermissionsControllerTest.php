<?php

namespace unit\Api\V1;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V1\PermissionsController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Exception;

class PermissionsControllerTest extends TestCase
{
    private MockObject $mockRepository;
    private array $mockRequest;
    private PermissionsController $controller;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(DbPermissionTemplateRepository::class);
        $this->mockRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/permissions',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => 'test-api-key'
        ];

        $this->controller = new TestablePermissionsController($this->mockRequest);
        $this->controller->setRepository($this->mockRepository);
    }

    public function testListPermissionsSuccess(): void
    {
        $expectedPermissions = [
            [
                'id' => 1,
                'name' => 'zone_content_view_own',
                'descr' => 'User may view the content of zones he owns'
            ],
            [
                'id' => 2,
                'name' => 'zone_content_edit_own',
                'descr' => 'User may edit the content of zones he owns'
            ],
            [
                'id' => 3,
                'name' => 'zone_meta_edit_own',
                'descr' => 'User may edit the meta data of zones he owns'
            ]
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionsByTemplateId')
            ->with(0)
            ->willReturn($expectedPermissions);

        $this->mockRequest['REQUEST_METHOD'] = 'GET';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissions();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals($expectedPermissions, $content['data']);
        $this->assertCount(3, $content['data']);
    }

    public function testListPermissionsEmpty(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionsByTemplateId')
            ->with(0)
            ->willReturn([]);

        $this->mockRequest['REQUEST_METHOD'] = 'GET';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissions();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals([], $content['data']);
        $this->assertCount(0, $content['data']);
    }

    public function testListPermissionsException(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionsByTemplateId')
            ->with(0)
            ->willThrowException(new Exception('Database connection failed'));

        $this->mockRequest['REQUEST_METHOD'] = 'GET';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissions();

        $this->assertEquals(500, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('Failed to fetch permissions', $content['message']);
        $this->assertStringContainsString('Database connection failed', $content['message']);
    }

    public function testListPermissionsValidatesResponseStructure(): void
    {
        $expectedPermissions = [
            [
                'id' => 1,
                'name' => 'zone_content_view_own',
                'descr' => 'User may view the content of zones he owns'
            ]
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionsByTemplateId')
            ->with(0)
            ->willReturn($expectedPermissions);

        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissions();
        $content = json_decode($response->getContent(), true);

        // Validate response structure
        $this->assertArrayHasKey('success', $content);
        $this->assertArrayHasKey('data', $content);
        $this->assertIsBool($content['success']);
        $this->assertIsArray($content['data']);

        // Validate permission structure
        $permission = $content['data'][0];
        $this->assertArrayHasKey('id', $permission);
        $this->assertArrayHasKey('name', $permission);
        $this->assertArrayHasKey('descr', $permission);
        $this->assertIsInt($permission['id']);
        $this->assertIsString($permission['name']);
        $this->assertIsString($permission['descr']);
    }

    public function testMethodNotAllowed(): void
    {
        $this->mockRequest['REQUEST_METHOD'] = 'POST';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testMethodNotAllowed();

        $this->assertEquals(405, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Method not allowed', $content['message']);
    }

    public function testPutMethodNotAllowed(): void
    {
        $this->mockRequest['REQUEST_METHOD'] = 'PUT';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testMethodNotAllowed();

        $this->assertEquals(405, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Method not allowed', $content['message']);
    }

    public function testDeleteMethodNotAllowed(): void
    {
        $this->mockRequest['REQUEST_METHOD'] = 'DELETE';
        $controller = new TestablePermissionsController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testMethodNotAllowed();

        $this->assertEquals(405, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Method not allowed', $content['message']);
    }
}
