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

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /api/v2/users by a delegate without user_edit_others: the new account may not get a
 * template granting more than the caller holds, but a template the app fills in when none is sent is not checked.
 */
class UsersControllerTemplateSubsetTest extends V2ControllerTestCase
{
    private const CALLER = 100;
    private const MINIMAL_TEMPLATE = 2;
    private const RICHER_TEMPLATE = 5;

    /** @var UserManagementService&MockObject */
    private UserManagementService $users;

    public function testAnOmittedTemplateCreatesTheUserOnTheMinimalOne(): void
    {
        $this->users->expects($this->once())->method('createUser')
            ->with($this->callback(static fn($command): bool => $command->permissionTemplateId === self::MINIMAL_TEMPLATE))
            ->willReturn(['success' => true, 'user_id' => 7, 'message' => 'User created successfully']);

        $this->assertSame(201, $this->create(null)->getStatusCode());
    }

    public function testAnExplicitMinimalTemplateIsRefusedWhenItGrantsMoreThanTheCallerHolds(): void
    {
        $this->users->expects($this->never())->method('createUser');

        $response = $this->create(self::MINIMAL_TEMPLATE);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(PermissionService::TEMPLATE_NOT_SUBSET_DENIED, $this->messageOf($response));
    }

    public function testARicherTemplateIsRefused(): void
    {
        $this->users->expects($this->never())->method('createUser');
        $response = $this->create(self::RICHER_TEMPLATE);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(PermissionService::TEMPLATE_NOT_SUBSET_DENIED, $this->messageOf($response));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = $this->createMock(UserManagementService::class);
    }

    private function create(?int $template): JsonResponse
    {
        $body = ['username' => 'carol', 'password' => 'Secret-pass-123', 'email' => 'carol@example.com'];
        if ($template !== null) {
            $body['perm_templ'] = $template;
        }

        $controller = $this->bareController(UsersController::class);
        $this->injectBaseCollaborators($controller, 'POST', $body);

        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserPermissions')->willReturn([Permission::PERM_USER_ADD_NEW, Permission::PERM_USER_EDIT_TEMPL_PERM]);
        $repository->method('getTemplatePermissionNames')->willReturnMap([
            [self::MINIMAL_TEMPLATE, [Permission::PERM_ZONE_CONTENT_VIEW_OWN]],
            [self::RICHER_TEMPLATE, [Permission::PERM_USER_EDIT_OTHERS]],
        ]);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canCreateUser')->willReturn(true);
        $permissions->method('permissions')->willReturn(new PermissionService($repository));

        $templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $templates->method('getMinimalPermissionTemplateId')->willReturn(self::MINIMAL_TEMPLATE);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($templates);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'userManagementService', $this->users);
        $this->inject($controller, 'userRepository', $this->createMock(UserLookupInterface::class));
        $this->inject($controller, 'authenticatedUserId', self::CALLER);
        $this->inject($controller, 'serviceFactory', $factory);

        return $this->callHandler($controller, 'createUser');
    }
}
