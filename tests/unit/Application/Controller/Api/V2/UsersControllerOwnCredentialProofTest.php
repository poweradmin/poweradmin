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
use Poweradmin\Application\Service\User\PasswordChangeService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * PUT /api/v2/users/{id} by an API key on its own account: a new password, email or
 * username needs current_password, as the web form does. Basic auth is exempt.
 */
class UsersControllerOwnCredentialProofTest extends V2ControllerTestCase
{
    private const SELF = 7;
    private const OTHER = 8;
    private const CURRENT = 'Current-pass-1';

    /** @var UserManagementService&MockObject */
    private UserManagementService $users;

    private string $authMethod = 'sql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = $this->createMock(UserManagementService::class);
    }

    public function testAKeyChangingItsOwnPasswordWithoutTheCurrentOneIsRefused(): void
    {
        $this->users->expects($this->never())->method('updateUser');

        $response = $this->put(['password' => 'Brand-new-pass-9']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('current_password is required to change your own password, email or username', $this->messageOf($response));
    }

    public function testTheCurrentPasswordLetsTheChangeThrough(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(200, $this->put(['password' => 'Brand-new-pass-9', 'current_password' => self::CURRENT])->getStatusCode());
    }

    public function testAWrongCurrentPasswordIsRefused(): void
    {
        $this->users->expects($this->never())->method('updateUser');

        $response = $this->put(['password' => 'Brand-new-pass-9', 'current_password' => 'guess']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You did not enter the correct current password.', $this->messageOf($response));
    }

    public function testAPasswordResentUnchangedStillNeedsTheCurrentPassword(): void
    {
        $this->users->expects($this->never())->method('updateUser');

        $this->assertSame(403, $this->put(['password' => self::CURRENT, 'description' => 'edited'])->getStatusCode());
    }

    public function testNonCredentialFieldsNeedNoCurrentPassword(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(200, $this->put(['description' => 'edited'])->getStatusCode());
    }

    public function testAnEmailChangeNeedsTheCurrentPassword(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(403, $this->put(['email' => 'new@example.com'])->getStatusCode());
        $this->assertSame(200, $this->put(['email' => 'new@example.com', 'current_password' => self::CURRENT])->getStatusCode());
    }

    public function testAnUnchangedEmailNeedsNothing(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(200, $this->put(['email' => 'carol@example.com'])->getStatusCode());
    }

    public function testAnLdapAccountCannotChangeItsEmail(): void
    {
        $this->authMethod = 'ldap';
        $this->users->expects($this->never())->method('updateUser');

        $response = $this->put(['email' => 'new@example.com', 'current_password' => self::CURRENT]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Authentication settings can only be changed by an administrator', $this->messageOf($response));
    }

    public function testAnAdminEditingAnotherUserIsUnaffected(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(200, $this->put(['password' => 'Brand-new-pass-9', 'email' => 'new@example.com'], target: self::OTHER)->getStatusCode());
    }

    public function testBasicAuthIsUnaffected(): void
    {
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true, 'user_id' => self::SELF, 'message' => 'ok']);

        $this->assertSame(200, $this->put(['password' => 'Brand-new-pass-9', 'email' => 'new@example.com'], byKey: false)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body, int $target = self::SELF, bool $byKey = true): JsonResponse
    {
        $controller = $this->bareController(UsersController::class);
        $this->injectBaseCollaborators($controller, 'PUT', $body);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditUser')->willReturn(true);
        $permissions->method('canEditUserPassword')->willReturn(true);
        $permissions->method('permissions')->willReturn($this->createMock(PermissionService::class));

        $stored = ['id' => $target, 'username' => 'carol', 'email' => 'carol@example.com', 'auth_method' => $this->authMethod];
        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->method('getUserById')->willReturn($stored);

        $passwords = $this->createMock(PasswordChangeService::class);
        $passwords->method('confirmPasswordOfUser')->willReturnCallback(
            static fn(int $id, string $password): array => $password === self::CURRENT
                ? [true, '']
                : [false, 'You did not enter the correct current password.']
        );

        $templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($templates);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'userManagementService', $this->users);
        $this->inject($controller, 'userRepository', $lookup);
        $this->inject($controller, 'passwordChangeService', $passwords);
        $this->inject($controller, 'pathParameters', ['id' => $target]);
        $this->inject($controller, 'authenticatedUserId', self::SELF);
        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'apiKeyScope', $byKey ? ApiKeyScope::unrestricted() : null);

        return $this->callHandler($controller, 'updateUser');
    }
}
