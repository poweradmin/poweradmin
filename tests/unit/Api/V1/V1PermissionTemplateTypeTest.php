<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api\V1;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1\PermissionTemplatesController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * PUT /api/v1/permission-templates/{id} keeps the stored template type.
 */
class V1PermissionTemplateTypeTest extends TestCase
{
    public function testTemplateUpdateKeepsTheStoredTemplateType(): void
    {
        $repository = $this->createMock(DbPermissionTemplateRepository::class);
        $repository->method('getPermissionTemplateDetails')->willReturn(['id' => 4, 'name' => 'G', 'descr' => 'g', 'template_type' => 'group']);
        $repository->method('getPermissionsByTemplateId')->willReturn([]);
        $repository->expects($this->once())->method('updatePermissionTemplateDetails')
            ->with($this->callback(fn(array $details): bool => ($details['template_type'] ?? null) === 'group'))
            ->willReturn(true);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $controller = (new ReflectionClass(PermissionTemplatesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'permissionTemplateRepository', $repository);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'request', $this->jsonRequest(['name' => 'G2', 'descr' => 'g2']));
        $this->inject($controller, 'pathParameters', ['id' => 4]);
        $this->inject($controller, 'authenticatedUserId', 7);

        $response = (new ReflectionClass($controller))->getMethod('updatePermissionTemplate')->invoke($controller);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
