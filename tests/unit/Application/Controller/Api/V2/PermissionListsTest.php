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
use Poweradmin\Application\Controller\Api\V2\PermissionsController;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Filtering, sorting and paging of GET /api/v2/permissions and
 * GET /api/v2/permission-templates.
 */
#[CoversClass(PermissionsController::class)]
#[CoversClass(PermissionTemplatesController::class)]
class PermissionListsTest extends V2ControllerTestCase
{
    /**
     * @param array<string, mixed> $query
     */
    private function call(string $class, string $handler, PermissionTemplateRepositoryInterface $repository, array $query = []): JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $controller = $this->bareController($class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'permissionTemplateRepository', $repository);
        $this->inject($controller, 'authenticatedUserId', 1);

        return $this->callHandler($controller, $handler);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listPermissions(array $query = []): JsonResponse
    {
        $repository = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $repository->method('getPermissionsByTemplateId')->with(0)->willReturn([
            ['id' => 53, 'name' => 'user_is_ueberuser', 'descr' => 'User has full access'],
            ['id' => 41, 'name' => 'zone_master_add', 'descr' => 'User may add master zones'],
            ['id' => 44, 'name' => 'zone_content_view_own', 'descr' => 'User may view the content of zones he owns'],
        ]);

        return $this->call(PermissionsController::class, 'listPermissions', $repository, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listTemplates(array $query = [], ?string $expectedType = null): JsonResponse
    {
        $repository = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $repository->method('listPermissionTemplates')->with($expectedType)->willReturn([
            ['id' => 1, 'name' => 'Administrator', 'descr' => 'Full rights', 'template_type' => 'user'],
            ['id' => 6, 'name' => 'Group Editors', 'descr' => 'Edit zones of the group', 'template_type' => 'group'],
            ['id' => 2, 'name' => 'Zone Manager', 'descr' => 'Manage own zones', 'template_type' => 'user'],
        ]);

        return $this->call(PermissionTemplatesController::class, 'listPermissionTemplates', $repository, $query);
    }

    public function testThePermissionListIsUnchangedWithoutTheNewParameters(): void
    {
        $body = $this->decode($this->listPermissions());

        $this->assertSame([53, 41, 44], array_column($body['data']['permissions'], 'id'));
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testPermissionsAreFilteredSortedAndPaged(): void
    {
        $this->assertSame(['zone_master_add', 'zone_content_view_own'], array_column($this->decode($this->listPermissions(['q' => 'ZONE']))['data']['permissions'], 'name'));

        $body = $this->decode($this->listPermissions(['sort' => 'id', 'per_page' => 2, 'page' => 2]));
        $this->assertSame([53], array_column($body['data']['permissions'], 'id'));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $body['pagination']);
    }

    public function testAnUnknownPermissionSortFieldIs400(): void
    {
        $response = $this->listPermissions(['sort' => 'descr']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Invalid sort field 'descr'. Allowed fields: id, name", $this->messageOf($response));
    }

    public function testTemplatesAreFilteredSortedAndPaged(): void
    {
        $this->assertSame(['Group Editors', 'Zone Manager'], array_column($this->decode($this->listTemplates(['q' => 'zones']))['data']['templates'], 'name'));

        $body = $this->decode($this->listTemplates(['sort' => 'template_type,name:desc', 'per_page' => 2]));
        $this->assertSame(['Group Editors', 'Zone Manager'], array_column($body['data']['templates'], 'name'));
        $this->assertSame(3, $body['pagination']['total']);
    }

    public function testTheTypeFilterIsPassedToTheRepository(): void
    {
        $this->assertSame(200, $this->listTemplates(['type' => 'group'], 'group')->getStatusCode());
    }

    public function testAnInvalidTypeOrSortIs400(): void
    {
        $this->assertSame(400, $this->listTemplates(['type' => 'admins'])->getStatusCode());
        $this->assertSame(400, $this->listTemplates(['sort' => 'owner'])->getStatusCode());
    }
}
