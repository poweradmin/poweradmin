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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1\PermissionTemplatesController as V1Controller;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController as V2Controller;
use Poweradmin\Application\Controller\DeletePermTemplController;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionClass;
use RuntimeException;

/** Deleting a permission template needs templ_perm_edit, like adding and editing, not the assignment permission. */
class PermissionTemplateDeleteGateTest extends TestCase
{
    public function testWebDeleteAsksForTheTemplateEditPermission(): void
    {
        $controller = new class extends DeletePermTemplController {
            public function __construct()
            {
            }

            public function checkPermission(string $permission, string $errorMessage): void
            {
                throw new RuntimeException($permission);
            }
        };

        try {
            $controller->run();
            $this->fail('Expected a permission check.');
        } catch (RuntimeException $e) {
            $this->assertSame('templ_perm_edit', $e->getMessage());
        }
    }

    public function testApiDeleteRefusesTheAssignmentPermissionOnly(): void
    {
        foreach ([V1Controller::class, V2Controller::class] as $class) {
            $response = $this->delete($class, ['user_edit_templ_perm']);
            $this->assertSame(403, $response->getStatusCode(), $class);
        }
    }

    public function testApiDeleteAllowsTheTemplateEditPermission(): void
    {
        foreach ([V1Controller::class, V2Controller::class] as $class) {
            // Past the gate; the missing id ends the request with 400 before any lookup
            $response = $this->delete($class, ['templ_perm_edit']);
            $this->assertSame(400, $response->getStatusCode(), $class);
        }
    }

    /**
     * @param string[] $granted
     */
    private function delete(string $class, array $granted): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $service = $this->createMock(ApiPermissionService::class);
        $service->method('userHasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $granted, true));

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionClass($class))->getProperty('apiPermissionService')->setValue($controller, $service);
        $base = new ReflectionClass(\Poweradmin\Application\Controller\Api\PublicApiController::class);
        $base->getProperty('authenticatedUserId')->setValue($controller, 7);
        $base->getProperty('pathParameters')->setValue($controller, []);

        return (new ReflectionClass($class))->getMethod('deletePermissionTemplate')->invoke($controller);
    }
}
