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
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Controller\DeletePermTemplController;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deleting a permission template needs templ_perm_edit, like editing one. The template
 * assignment permission (user_edit_templ_perm) alone must not delete a template.
 */
class DeletePermTemplPermissionTest extends TestCase
{
    public function testWebDeleteChecksTheTemplateEditPermission(): void
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
            $this->fail('run() did not check a permission');
        } catch (RuntimeException $e) {
            $this->assertSame('templ_perm_edit', $e->getMessage());
        }
    }

    public function testApiDeleteRefusesAnAssignmentOnlyDelegate(): void
    {
        $response = $this->deleteAs(['user_edit_templ_perm']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApiDeleteAllowsTheTemplateEditPermission(): void
    {
        // Past the permission gate the missing path id answers 400
        $response = $this->deleteAs(['templ_perm_edit']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @param string[] $granted
     */
    private function deleteAs(array $granted): object
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturnCallback(
            fn (int $userId, string $permission): bool => in_array($permission, $granted, true)
        );

        $controller = (new ReflectionClass(PermissionTemplatesController::class))->newInstanceWithoutConstructor();
        $this->set($controller, 'apiPermissionService', $permissions);
        $this->set($controller, 'authenticatedUserId', 5);
        $this->set($controller, 'pathParameters', []);
        $this->set($controller, 'request', Request::create('/api', 'DELETE'));

        return (new ReflectionMethod($controller, 'deletePermissionTemplate'))->invoke($controller);
    }

    private function set(object $controller, string $name, mixed $value): void
    {
        for ($class = new ReflectionClass($controller); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                (new ReflectionProperty($class->getName(), $name))->setValue($controller, $value);
                return;
            }
        }
    }
}
