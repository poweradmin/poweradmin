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
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * PUT/PATCH /api/v{1,2}/users/{id} must not let a caller set another user's
 * password unless they hold user_passwd_edit_others (GHSA-h4hf-v6w5-897x).
 * Without the check a delegated user-manager (user_edit_others, no ueberuser)
 * could reset the administrator's password via the API and take over the
 * account - a full privilege escalation the web UI already blocks.
 */
class UsersControllerPasswordAuthTest extends TestCase
{
    public static function controllerProvider(): array
    {
        return [
            'v2' => [\Poweradmin\Application\Controller\Api\V2\UsersController::class],
        ];
    }

    #[DataProvider('controllerProvider')]
    public function testPasswordChangeRejectedWithoutPermission(string $controllerClass): void
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->with(100, 1)->willReturn(true);
        $permission->method('canEditUserPassword')->with(100, 1)->willReturn(false);

        $userService = $this->createMock(UserManagementService::class);
        // The password write must never be reached.
        $userService->expects($this->never())->method('updateUser');

        $controller = $this->makeController($controllerClass, 100, 1, $permission, $userService);
        $this->setProperty($controller, 'request', Request::create('/', 'PUT', [], [], [], [], '{"password": "NewAdminPass1!"}'));

        $response = $this->invokeUpdate($controller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('permission to change', (string)$response->getContent());
    }

    public function testSignInMethodChangeRejectedWithoutPasswordPermission(): void
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->willReturn(true);
        $permission->method('canEditUserPassword')->willReturn(false);

        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->never())->method('updateUser');

        $controller = $this->makeController(\Poweradmin\Application\Controller\Api\V2\UsersController::class, 100, 1, $permission, $userService);
        $this->setStoredUser($controller, ['id' => 1, 'use_ldap' => 0]);
        $this->setProperty($controller, 'request', Request::create('/', 'PUT', [], [], [], [], '{"use_ldap": true}'));

        $response = $this->invokeUpdate($controller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('sign-in method', (string)$response->getContent());
    }

    public function testUnchangedSignInMethodPassesWithoutPasswordPermission(): void
    {
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->willReturn(true);
        $permission->method('canEditUserPassword')->willReturn(false);

        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->once())->method('updateUser')
            ->willReturn(['success' => true, 'message' => 'ok', 'user_id' => 1]);

        $controller = $this->makeController(\Poweradmin\Application\Controller\Api\V2\UsersController::class, 100, 1, $permission, $userService);
        $this->setStoredUser($controller, ['id' => 1, 'use_ldap' => 1]);
        $this->setProperty($controller, 'request', Request::create('/', 'PUT', [], [], [], [], '{"use_ldap": true, "fullname": "A"}'));

        $this->assertNotSame(403, $this->invokeUpdate($controller)->getStatusCode());
    }

    public function testMalformedSignInFlagIsLeftToTheValidator(): void
    {
        // "maybe" is not a change of sign-in method; the command factory answers it with 400
        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('canEditUser')->willReturn(true);
        $permission->method('canEditUserPassword')->willReturn(false);

        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->never())->method('updateUser');

        $controller = $this->makeController(\Poweradmin\Application\Controller\Api\V2\UsersController::class, 100, 1, $permission, $userService);
        $this->setStoredUser($controller, ['id' => 1, 'use_ldap' => 1]);
        $this->setProperty($controller, 'request', Request::create('/', 'PUT', [], [], [], [], '{"use_ldap": "maybe"}'));

        $this->assertSame(400, $this->invokeUpdate($controller)->getStatusCode());
    }

    private function setStoredUser(object $controller, array $row): void
    {
        $repo = $this->createMock(\Poweradmin\Domain\Repository\UserLookupInterface::class);
        $repo->method('getUserById')->willReturn($row);
        $this->setProperty($controller, 'userRepository', $repo);
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

    private function invokeUpdate(object $controller): object
    {
        $method = new ReflectionMethod($controller, 'updateUser');

        return $method->invoke($controller);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setValue($target, $value);
    }
}
