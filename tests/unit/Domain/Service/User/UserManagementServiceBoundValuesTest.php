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

namespace Poweradmin\Tests\Unit\Domain\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

/**
 * Pins the command UserManagementService hands to the repository for a create
 * and for a partial update: every field the request carried, and nothing else.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceBoundValuesTest extends TestCase
{
    private const HASHED = '$configured$hash';

    private UserRepositoryInterface&MockObject $userRepository;
    private PermissionService&MockObject $permissions;
    /** Command handed to the repository by the last captured create or update call. */
    private CreateUserCommand|UpdateUserCommand|null $stored = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserByUsername')->willReturn(null);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->userRepository->method('permissionTemplateExists')->willReturn(true);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'username' => 'old', 'email' => 'old@example.com', 'auth_method' => 'sql']);
        $this->userRepository->method('isLastUberuser')->willReturn(false);
        $this->permissions = $this->createMock(PermissionService::class);
    }

    private function service(bool $ldapEnabled = true): UserManagementService
    {
        $policy = $this->createMock(PasswordPolicyService::class);
        $policy->method('validatePassword')->willReturn([]);
        $hasher = $this->createMock(UserAuthenticationService::class);
        $hasher->method('hashPassword')->willReturn(self::HASHED);

        return new UserManagementService(
            $this->userRepository,
            $this->permissions,
            new UserProfileAssembler($this->permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            $hasher,
            $policy,
            $ldapEnabled,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class)
        );
    }

    private function captureWrite(string $method): void
    {
        $this->userRepository->method($method)
            ->willReturnCallback(function (...$args) use ($method) {
                $this->stored = end($args);
                return $method === 'createUser' ? 42 : true;
            });
    }

    #[Test]
    public function testCreateBindsEveryFieldWithTheHashedPassword(): void
    {
        $this->captureWrite('createUser');

        $result = $this->service()->createUser(
            new CreateUserCommand('newuser', 'Secret123!', 'New User', 'new@example.com', 'desc', false, 3, false)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['user_id']);
        $this->assertEquals(
            new CreateUserCommand('newuser', self::HASHED, 'New User', 'new@example.com', 'desc', false, 3, false),
            $this->stored
        );
    }

    #[Test]
    public function testCreateLdapUserBindsThePlaceholderPassword(): void
    {
        $this->captureWrite('createUser');

        $result = $this->service()->createUser(new CreateUserCommand('ldapuser', null, permissionTemplateId: 3, useLdap: true));

        $this->assertTrue($result['success']);
        $this->assertEquals(
            new CreateUserCommand('ldapuser', AuthMethod::LDAP_PASSWORD_PLACEHOLDER, permissionTemplateId: 3, useLdap: true),
            $this->stored
        );
    }

    #[Test]
    public function testUpdateBindsOnlyTheFieldsGiven(): void
    {
        $this->captureWrite('updateUser');
        $this->permissions->expects($this->never())->method('forgetUser');

        $changes = new UpdateUserCommand(fullname: 'Renamed', active: true);

        $result = $this->service()->updateUser(7, $changes);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['user_id']);
        $this->assertSame($changes, $this->stored);
    }

    #[Test]
    public function testUpdateBindsEveryFieldAndForgetsCachedPermissions(): void
    {
        $this->captureWrite('updateUser');
        $this->permissions->expects($this->once())->method('forgetUser')->with(7);

        $result = $this->service()->updateUser(
            7,
            new UpdateUserCommand('renamed', 'Secret123!', 'Full', 'renamed@example.com', 'd', false, 2, false)
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(
            new UpdateUserCommand('renamed', self::HASHED, 'Full', 'renamed@example.com', 'd', false, 2, false),
            $this->stored
        );
    }

    #[Test]
    public function testUpdateWithEmptyPasswordLeavesItOut(): void
    {
        $this->captureWrite('updateUser');

        $result = $this->service()->updateUser(7, new UpdateUserCommand(password: '', fullname: 'Renamed'));

        $this->assertTrue($result['success']);
        $this->assertEquals(new UpdateUserCommand(password: '', fullname: 'Renamed'), $this->stored);
    }
}
