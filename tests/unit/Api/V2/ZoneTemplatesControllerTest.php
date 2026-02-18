<?php

namespace unit\Api\V2;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Exception;

class ZoneTemplatesControllerTest extends TestCase
{
    private MockObject $mockRepository;
    private MockObject $mockPermissionService;
    private array $mockRequest;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(DbZoneTemplateRepository::class);
        $this->mockPermissionService = $this->createMock(ApiPermissionService::class);
        $this->mockRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v2/zone-templates',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => 'test-api-key'
        ];
    }

    private function createController(array $pathParameters = []): TestableZoneTemplatesController
    {
        $controller = new TestableZoneTemplatesController($this->mockRequest, $pathParameters);
        $controller->setRepository($this->mockRepository);
        $controller->setApiPermissionService($this->mockPermissionService);
        return $controller;
    }

    public function testListZoneTemplatesSuccess(): void
    {
        $expectedTemplates = [
            ['id' => 1, 'name' => 'Default', 'descr' => 'Default template', 'owner' => 0, 'zones_linked' => 3],
            ['id' => 2, 'name' => 'Custom', 'descr' => 'Custom template', 'owner' => 1, 'zones_linked' => 1],
        ];

        $this->mockPermissionService
            ->method('userHasPermission')
            ->with(1, 'user_is_ueberuser')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('listZoneTemplates')
            ->with(1, false)
            ->willReturn($expectedTemplates);

        $controller = $this->createController();
        $response = $controller->testListZoneTemplates();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertCount(2, $content['data']);
        $this->assertEquals('Default', $content['data'][0]['name']);
        $this->assertEquals('Default template', $content['data'][0]['description']);
        $this->assertTrue($content['data'][0]['is_global']);
        $this->assertFalse($content['data'][1]['is_global']);
    }

    public function testListZoneTemplatesException(): void
    {
        $this->mockPermissionService
            ->method('userHasPermission')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('listZoneTemplates')
            ->willThrowException(new Exception('Database error'));

        $controller = $this->createController();
        $response = $controller->testListZoneTemplates();

        $this->assertEquals(500, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('Failed to fetch zone templates', $content['message']);
    }

    public function testGetZoneTemplateSuccess(): void
    {
        $templateId = 1;
        $expectedTemplate = ['id' => 1, 'name' => 'Default', 'descr' => 'Default template', 'owner' => 0];
        $expectedRecords = [
            ['id' => 1, 'name' => '[ZONE]', 'type' => 'SOA', 'content' => '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400', 'ttl' => 86400, 'prio' => 0],
        ];

        $this->mockPermissionService
            ->method('userHasPermission')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with($templateId)
            ->willReturn($expectedTemplate);

        $this->mockRepository
            ->expects($this->once())
            ->method('getZoneTemplateRecords')
            ->with($templateId)
            ->willReturn($expectedRecords);

        $controller = $this->createController(['id' => $templateId]);
        $response = $controller->testGetZoneTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals(1, $content['data']['id']);
        $this->assertEquals('Default template', $content['data']['description']);
        $this->assertTrue($content['data']['is_global']);
        $this->assertCount(1, $content['data']['records']);
        $this->assertEquals('SOA', $content['data']['records'][0]['type']);
        $this->assertEquals(0, $content['data']['records'][0]['priority']);
    }

    public function testGetZoneTemplateNotFound(): void
    {
        $this->mockPermissionService
            ->method('userHasPermission')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with(999)
            ->willReturn(false);

        $controller = $this->createController(['id' => 999]);
        $response = $controller->testGetZoneTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Zone template not found', $content['message']);
    }

    public function testGetZoneTemplateForbidden(): void
    {
        $expectedTemplate = ['id' => 2, 'name' => 'Private', 'descr' => 'Other user template', 'owner' => 99];

        $this->mockPermissionService
            ->method('userHasPermission')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with(2)
            ->willReturn($expectedTemplate);

        $controller = $this->createController(['id' => 2]);
        $response = $controller->testGetZoneTemplate();

        $this->assertEquals(403, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('permission', $content['message']);
    }

    public function testCreateZoneTemplateSuccess(): void
    {
        $requestData = [
            'name' => 'New Template',
            'description' => 'New template description',
        ];

        $this->mockPermissionService
            ->method('canCreateZoneTemplate')
            ->with(1)
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateNameExists')
            ->with('New Template')
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('createZoneTemplate')
            ->with('New Template', 'New template description', 1, 1)
            ->willReturn(5);

        $controller = $this->createController();
        $controller->setJsonInput($requestData);
        $response = $controller->testCreateZoneTemplate();

        $this->assertEquals(201, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals(5, $content['data']['id']);
        $this->assertEquals('Zone template created successfully', $content['message']);
    }

    public function testCreateZoneTemplateMissingFields(): void
    {
        $requestData = [
            'name' => 'New Template',
            // Missing 'description'
        ];

        $this->mockPermissionService
            ->method('canCreateZoneTemplate')
            ->willReturn(true);

        $controller = $this->createController();
        $controller->setJsonInput($requestData);
        $response = $controller->testCreateZoneTemplate();

        $this->assertEquals(400, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Missing required fields: name, description', $content['message']);
    }

    public function testCreateZoneTemplateDuplicateName(): void
    {
        $requestData = [
            'name' => 'Existing Template',
            'description' => 'Some description',
        ];

        $this->mockPermissionService
            ->method('canCreateZoneTemplate')
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateNameExists')
            ->with('Existing Template')
            ->willReturn(true);

        $controller = $this->createController();
        $controller->setJsonInput($requestData);
        $response = $controller->testCreateZoneTemplate();

        $this->assertEquals(409, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('already exists', $content['message']);
    }

    public function testCreateZoneTemplateNoPermission(): void
    {
        $requestData = [
            'name' => 'New Template',
            'description' => 'Some description',
        ];

        $this->mockPermissionService
            ->method('canCreateZoneTemplate')
            ->with(1)
            ->willReturn(false);

        $controller = $this->createController();
        $controller->setJsonInput($requestData);
        $response = $controller->testCreateZoneTemplate();

        $this->assertEquals(403, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('permission', $content['message']);
    }

    public function testUpdateZoneTemplateSuccess(): void
    {
        $templateId = 1;
        $requestData = [
            'name' => 'Updated Template',
            'description' => 'Updated description',
        ];

        $this->mockPermissionService
            ->method('canEditZoneTemplate')
            ->with(1)
            ->willReturn(true);

        $this->mockPermissionService
            ->method('userHasPermission')
            ->with(1, 'user_is_ueberuser')
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateExists')
            ->with($templateId)
            ->willReturn(true);

        $this->mockRepository
            ->method('getOwner')
            ->with($templateId)
            ->willReturn(1);

        $this->mockRepository
            ->method('zoneTemplateNameExists')
            ->with('Updated Template', $templateId)
            ->willReturn(false);

        $this->mockRepository
            ->expects($this->once())
            ->method('updateZoneTemplate')
            ->with($templateId, 'Updated Template', 'Updated description');

        $controller = $this->createController(['id' => $templateId]);
        $controller->setJsonInput($requestData);
        $response = $controller->testUpdateZoneTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Zone template updated successfully', $content['message']);
    }

    public function testUpdateZoneTemplateNotFound(): void
    {
        $requestData = [
            'name' => 'Updated Template',
            'description' => 'Updated description',
        ];

        $this->mockPermissionService
            ->method('canEditZoneTemplate')
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateExists')
            ->with(999)
            ->willReturn(false);

        $controller = $this->createController(['id' => 999]);
        $controller->setJsonInput($requestData);
        $response = $controller->testUpdateZoneTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Zone template not found', $content['message']);
    }

    public function testDeleteZoneTemplateSuccess(): void
    {
        $templateId = 1;

        $this->mockPermissionService
            ->method('canEditZoneTemplate')
            ->with(1)
            ->willReturn(true);

        $this->mockPermissionService
            ->method('userHasPermission')
            ->with(1, 'user_is_ueberuser')
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateExists')
            ->with($templateId)
            ->willReturn(true);

        $this->mockRepository
            ->method('getOwner')
            ->with($templateId)
            ->willReturn(1);

        $this->mockRepository
            ->expects($this->once())
            ->method('deleteZoneTemplate')
            ->with($templateId)
            ->willReturn(true);

        $controller = $this->createController(['id' => $templateId]);
        $response = $controller->testDeleteZoneTemplate();

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Zone template deleted successfully', $content['message']);
    }

    public function testDeleteZoneTemplateNotFound(): void
    {
        $this->mockPermissionService
            ->method('canEditZoneTemplate')
            ->willReturn(true);

        $this->mockRepository
            ->method('zoneTemplateExists')
            ->with(999)
            ->willReturn(false);

        $controller = $this->createController(['id' => 999]);
        $response = $controller->testDeleteZoneTemplate();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertEquals('Zone template not found', $content['message']);
    }

    public function testDeleteGlobalTemplateRequiresUeberuser(): void
    {
        $templateId = 1;

        $this->mockPermissionService
            ->method('canEditZoneTemplate')
            ->with(1)
            ->willReturn(true);

        $this->mockPermissionService
            ->method('userHasPermission')
            ->with(1, 'user_is_ueberuser')
            ->willReturn(false);

        $this->mockRepository
            ->method('zoneTemplateExists')
            ->with($templateId)
            ->willReturn(true);

        $this->mockRepository
            ->method('getOwner')
            ->with($templateId)
            ->willReturn(0);

        $controller = $this->createController(['id' => $templateId]);
        $response = $controller->testDeleteZoneTemplate();

        $this->assertEquals(403, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('ueberuser', $content['message']);
    }
}
