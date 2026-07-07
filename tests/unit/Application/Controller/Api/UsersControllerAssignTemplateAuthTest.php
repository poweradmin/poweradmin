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
 */

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /api/v{1,2}/users/{id} (assignPermissionTemplate) must authorize against
 * the target user, not just the generic "can edit permission templates" capability.
 * Without the per-target check a delegated admin holding user_edit_templ_perm (no
 * ueberuser) could retemplate a ueberuser account - e.g. strip the last super-admin
 * - via PATCH, a hole the PUT path (updateUser) already blocks with canEditUser.
 */
class UsersControllerAssignTemplateAuthTest extends TestCase
{
    public static function controllerProvider(): array
    {
        return [
            'v2' => [\Poweradmin\Application\Controller\Api\V2\UsersController::class],
            'v1' => [\Poweradmin\Application\Controller\Api\V1\UsersController::class],
        ];
    }

    #[DataProvider('controllerProvider')]
    public function testPatchRejectedWhenCallerCannotEditTarget(string $controllerClass): void
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->with(100, 1)->willReturn(false);
        // Short-circuits before the capability check ever runs.
        $permission->expects($this->never())->method('canEditPermissionTemplates');

        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->never())->method('assignPermissionTemplate');

        $controller = $this->makeController($controllerClass, 100, 1, $permission, $userService);

        $response = $this->invokeAssign($controller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('permission to edit this user', (string)$response->getContent());
    }

    #[DataProvider('controllerProvider')]
    public function testPatchAllowedForAuthorizedCaller(string $controllerClass): void
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->with(1, 5)->willReturn(true);
        $permission->method('canEditPermissionTemplates')->with(1)->willReturn(true);

        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->once())
            ->method('assignPermissionTemplate')
            ->with(5, 4)
            ->willReturn(['success' => true, 'message' => 'Permission template assigned successfully']);

        $controller = $this->makeController($controllerClass, 1, 5, $permission, $userService);
        $this->setProperty($controller, 'request', Request::create('/', 'PATCH', [], [], [], [], '{"perm_templ": 4}'));

        $response = $this->invokeAssign($controller);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function makeController(
        string $controllerClass,
        int $callerId,
        int $targetId,
        ApiPermissionService $permission,
        UserManagementService $userService
    ): object {
        $controller = (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'authenticatedUserId', $callerId);
        $this->setProperty($controller, 'pathParameters', ['id' => $targetId]);
        $this->setProperty($controller, 'apiPermissionService', $permission);
        $this->setProperty($controller, 'userManagementService', $userService);

        return $controller;
    }

    private function invokeAssign(object $controller): object
    {
        $method = new ReflectionMethod($controller, 'assignPermissionTemplate');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
