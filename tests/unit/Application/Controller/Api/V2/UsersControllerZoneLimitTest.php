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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * max_zones on POST and PUT /api/v2/users (#72): only superusers may send it, so a
 * delegated user manager cannot lift the limit of the users they manage.
 */
class UsersControllerZoneLimitTest extends V2ControllerTestCase
{
    private const CALLER = 100;
    private const TARGET = 7;

    /** @var ZoneOwnershipLimit&MockObject */
    private ZoneOwnershipLimit $limit;

    /** @var UserManagementService&MockObject */
    private UserManagementService $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limit = $this->createMock(ZoneOwnershipLimit::class);
        $this->users = $this->createMock(UserManagementService::class);
        $this->users->method('createUser')->willReturn(['success' => true, 'user_id' => self::TARGET, 'message' => 'User created successfully']);
        $this->users->method('updateUser')->willReturn(['success' => true, 'user_id' => self::TARGET, 'message' => 'User updated successfully']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writes(): array
    {
        return [
            'create' => ['createUser', 'POST'],
            'update' => ['updateUser', 'PUT'],
        ];
    }

    #[DataProvider('writes')]
    public function testANonSuperuserSendingMaxZonesIsForbiddenAndNothingIsWritten(string $handler, string $method): void
    {
        $this->limit->method('maySetLimits')->with(self::CALLER)->willReturn(false);
        $this->limit->expects($this->never())->method('setUserLimit');
        $this->users->expects($this->never())->method($handler);

        $response = $this->send($handler, $method, ['max_zones' => null]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Only superusers may change zone limits', $this->messageOf($response));
    }

    #[DataProvider('writes')]
    public function testAnInvalidValueIsRefusedBeforeAnyWrite(string $handler, string $method): void
    {
        $this->limit->method('maySetLimits')->willReturn(true);
        $this->limit->expects($this->never())->method('setUserLimit');
        $this->users->expects($this->never())->method($handler);

        $response = $this->send($handler, $method, ['max_zones' => -3]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringStartsWith('max_zones must be null or a whole number', $this->messageOf($response));
    }

    public function testASuperuserCreatesTheUserWithALimit(): void
    {
        $this->limit->method('maySetLimits')->willReturn(true);
        $this->limit->expects($this->once())->method('setUserLimit')->with(self::CALLER, self::TARGET, 10)->willReturn(['success' => true]);

        $response = $this->send('createUser', 'POST', ['max_zones' => 10]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCreateWithANullLimitStoresNothing(): void
    {
        $this->limit->method('maySetLimits')->willReturn(true);
        $this->limit->expects($this->never())->method('setUserLimit');

        $this->assertSame(201, $this->send('createUser', 'POST', ['max_zones' => null])->getStatusCode());
    }

    public function testASuperuserClearsALimitOnUpdate(): void
    {
        $this->limit->method('maySetLimits')->willReturn(true);
        $this->limit->expects($this->once())->method('setUserLimit')->with(self::CALLER, self::TARGET, null)->willReturn(['success' => true]);

        $this->assertSame(200, $this->send('updateUser', 'PUT', ['max_zones' => null])->getStatusCode());
    }

    #[DataProvider('writes')]
    public function testWithoutTheFieldTheLimitIsUntouched(string $handler, string $method): void
    {
        $this->limit->expects($this->never())->method('maySetLimits');
        $this->limit->expects($this->never())->method('setUserLimit');

        $this->assertLessThan(300, $this->send($handler, $method, [])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function send(string $handler, string $method, array $extra): JsonResponse
    {
        $body = ['username' => 'carol', 'password' => 'Secret-pass-123', 'email' => 'carol@example.com', 'perm_templ' => 2] + $extra;
        if ($handler === 'updateUser') {
            unset($body['password']);
        }

        $controller = $this->bareController(UsersController::class);
        $this->injectBaseCollaborators($controller, $method, $body);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canCreateUser')->willReturn(true);
        $permissions->method('canEditUser')->willReturn(true);
        $permissions->method('permissions')->willReturn($this->createMock(PermissionService::class));

        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->method('getUserById')->willReturn(['id' => self::TARGET, 'username' => 'carol']);

        $templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $templates->method('getMinimalPermissionTemplateId')->willReturn(2);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($templates);
        $factory->method('zoneOwnershipLimit')->willReturn($this->limit);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'userManagementService', $this->users);
        $this->inject($controller, 'userRepository', $lookup);
        $this->inject($controller, 'pathParameters', ['id' => self::TARGET]);
        $this->inject($controller, 'authenticatedUserId', self::CALLER);
        $this->inject($controller, 'serviceFactory', $factory);

        return $this->callHandler($controller, $handler);
    }
}
