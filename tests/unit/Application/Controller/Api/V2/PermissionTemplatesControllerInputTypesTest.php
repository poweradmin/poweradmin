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
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Create and update refuse a name or descr that is not a string with the missing-fields 400.
 */
#[CoversClass(PermissionTemplatesController::class)]
class PermissionTemplatesControllerInputTypesTest extends V2ControllerTestCase
{
    /** @var PermissionTemplateRepositoryInterface&MockObject */
    private PermissionTemplateRepositoryInterface $templates;

    protected function setUp(): void
    {
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('userRepository')->willReturn($this->stubUsers());

        return $factory;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nonStringFieldsProvider(): array
    {
        return [
            'array name' => [['name' => ['x'], 'descr' => 'd']],
            'array descr' => [['name' => 'n', 'descr' => ['x']]],
        ];
    }

    #[DataProvider('nonStringFieldsProvider')]
    public function testCreateWithNonStringFieldsIs400(array $body): void
    {
        $response = $this->invoke('createPermissionTemplate', 'POST', $body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Missing required fields: name, descr', json_decode((string)$response->getContent(), true)['message']);
    }

    #[DataProvider('nonStringFieldsProvider')]
    public function testUpdateWithNonStringFieldsIs400(array $body): void
    {
        $response = $this->invoke('updatePermissionTemplate', 'PUT', $body, ['id' => '7']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Missing required fields: name, descr', json_decode((string)$response->getContent(), true)['message']);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $path
     */
    private function invoke(string $handler, string $method, array $body, array $path = []): JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $controller = $this->bareController(PermissionTemplatesController::class);
        $this->injectBaseCollaborators($controller, $method, $body);
        $this->inject($controller, 'pathParameters', $path);
        $this->inject($controller, 'permissionTemplateRepository', $this->templates);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'authenticatedUserId', 5);

        return $this->callHandler($controller, $handler);
    }
}
