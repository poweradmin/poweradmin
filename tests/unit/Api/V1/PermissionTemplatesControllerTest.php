<?php

namespace unit\Api\V1;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V1\PermissionTemplatesController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\HttpFoundation\Request;
use Exception;

class PermissionTemplatesControllerTest extends TestCase
{
    private MockObject $mockRepository;
    private array $mockRequest;
    private PermissionTemplatesController $controller;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(DbPermissionTemplateRepository::class);
        $this->mockRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/permission-templates',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => 'test-api-key'
        ];

        $this->controller = new TestablePermissionTemplatesController($this->mockRequest);
        $this->controller->setRepository($this->mockRepository);
    }

    public function testListPermissionTemplatesSuccess(): void
    {
        $expectedTemplates = [
            ['id' => 1, 'name' => 'Admin', 'descr' => 'Administrator template'],
            ['id' => 2, 'name' => 'User', 'descr' => 'User template']
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('listPermissionTemplates')
            ->willReturn($expectedTemplates);

        $this->mockRequest['REQUEST_METHOD'] = 'GET';
        $controller = new TestablePermissionTemplatesController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissionTemplates();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals($expectedTemplates, $content['data']);
    }

    public function testListPermissionTemplatesException(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('listPermissionTemplates')
            ->willThrowException(new Exception('Database error'));

        $this->mockRequest['REQUEST_METHOD'] = 'GET';
        $controller = new TestablePermissionTemplatesController($this->mockRequest);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testListPermissionTemplates();

        $this->assertEquals(500, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('Failed to fetch permission templates', $content['message']);
    }

    public function testGetPermissionTemplateSuccess(): void
    {
        $templateId = 1;
        $expectedTemplate = ['id' => 1, 'name' => 'Admin', 'descr' => 'Administrator template'];
        $expectedPermissions = [
            ['id' => 1, 'name' => 'zone_content_view_own', 'descr' => 'View own zones'],
            ['id' => 2, 'name' => 'zone_content_edit_own', 'descr' => 'Edit own zones']
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn($expectedTemplate);

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionsByTemplateId')
            ->with($templateId)
            ->willReturn($expectedPermissions);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testGetPermissionTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals($templateId, $content['data']['id']);
        $this->assertEquals($expectedPermissions, $content['data']['permissions']);
    }

    public function testGetPermissionTemplateNotFound(): void
    {
        $templateId = 999;

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(false);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testGetPermissionTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Permission template not found', $content['message']);
    }

    public function testCreatePermissionTemplateSuccess(): void
    {
        $requestData = [
            'name' => 'New Template',
            'descr' => 'New template description',
            'permissions' => [1, 2, 3]
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('addPermissionTemplate')
            ->with([
                'templ_name' => 'New Template',
                'templ_descr' => 'New template description',
                'perm_id' => [1, 2, 3]
            ])
            ->willReturn(true);

        $this->mockRequest['REQUEST_METHOD'] = 'POST';
        $controller = new TestablePermissionTemplatesController($this->mockRequest);
        $controller->setRepository($this->mockRepository);
        $controller->setJsonInput($requestData);

        $response = $controller->testCreatePermissionTemplate();

        $this->assertEquals(201, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Permission template created successfully', $content['message']);
    }

    public function testCreatePermissionTemplateMissingFields(): void
    {
        $requestData = [
            'name' => 'New Template'
            // Missing 'descr' field
        ];

        $this->mockRequest['REQUEST_METHOD'] = 'POST';
        $controller = new TestablePermissionTemplatesController($this->mockRequest);
        $controller->setRepository($this->mockRepository);
        $controller->setJsonInput($requestData);

        $response = $controller->testCreatePermissionTemplate();

        $this->assertEquals(400, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Missing required fields: name, descr', $content['message']);
    }

    public function testUpdatePermissionTemplateSuccess(): void
    {
        $templateId = 1;
        $requestData = [
            'name' => 'Updated Template',
            'descr' => 'Updated description',
            'permissions' => [1, 2]
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(['id' => 1, 'name' => 'Old Template']);

        $this->mockRepository
            ->expects($this->once())
            ->method('updatePermissionTemplateDetails')
            ->with([
                'templ_id' => $templateId,
                'templ_name' => 'Updated Template',
                'templ_descr' => 'Updated description',
                'perm_id' => [1, 2]
            ])
            ->willReturn(true);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);
        $controller->setJsonInput($requestData);

        $response = $controller->testUpdatePermissionTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Permission template updated successfully', $content['message']);
    }

    public function testUpdatePermissionTemplateNotFound(): void
    {
        $templateId = 999;
        $requestData = [
            'name' => 'Updated Template',
            'descr' => 'Updated description'
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(false);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);
        $controller->setJsonInput($requestData);

        $response = $controller->testUpdatePermissionTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Permission template not found', $content['message']);
    }

    public function testDeletePermissionTemplateSuccess(): void
    {
        $templateId = 1;

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(['id' => 1, 'name' => 'Template']);

        $this->mockRepository
            ->expects($this->once())
            ->method('deletePermissionTemplate')
            ->with($templateId)
            ->willReturn(true);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testDeletePermissionTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Permission template deleted successfully', $content['message']);
    }

    public function testDeletePermissionTemplateInUse(): void
    {
        $templateId = 1;

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(['id' => 1, 'name' => 'Template']);

        $this->mockRepository
            ->expects($this->once())
            ->method('deletePermissionTemplate')
            ->with($templateId)
            ->willReturn(false);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testDeletePermissionTemplate();

        $this->assertEquals(409, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('Cannot delete permission template', $content['message']);
    }

    public function testDeletePermissionTemplateNotFound(): void
    {
        $templateId = 999;

        $this->mockRepository
            ->expects($this->once())
            ->method('getPermissionTemplateDetails')
            ->with($templateId)
            ->willReturn(false);

        $controller = new TestablePermissionTemplatesController($this->mockRequest, ['id' => $templateId]);
        $controller->setRepository($this->mockRepository);

        $response = $controller->testDeletePermissionTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Permission template not found', $content['message']);
    }
}
