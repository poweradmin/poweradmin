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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\UserProfileAssembler;

/**
 * Password hashing, password policy and LDAP handling in UserManagementService.
 * The API and the web UI must store credentials the same way.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceCredentialsTest extends TestCase
{
    private const HASHED = '$configured$hash';

    private UserRepositoryInterface&MockObject $userRepository;
    private PasswordPolicyService&MockObject $passwordPolicy;
    private UserAuthenticationService&MockObject $hasher;
    /** Row handed to the repository by the last captured create or update call. */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserByUsername')->willReturn(null);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->userRepository->method('permissionTemplateExists')->willReturn(true);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'auth_method' => 'sql']);

        $this->passwordPolicy = $this->createMock(PasswordPolicyService::class);
        $this->hasher = $this->createMock(UserAuthenticationService::class);
        $this->hasher->method('hashPassword')->willReturn(self::HASHED);
    }

    private function service(bool $ldapEnabled = false): UserManagementService
    {
        return new UserManagementService(
            $this->userRepository,
            $permissions = $this->createMock(PermissionService::class),
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            $this->hasher,
            $this->passwordPolicy,
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
    public function testCreateUserHashesWithConfiguredHasher(): void
    {
        $this->passwordPolicy->method('validatePassword')->willReturn([]);
        $this->hasher->expects($this->once())->method('hashPassword')->with('Secret123!');
        $this->captureWrite('createUser');

        $result = $this->service()->createUser([
            'username' => 'newuser',
            'password' => 'Secret123!',
            'perm_templ' => 3,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(self::HASHED, $this->stored['password']);
    }

    #[Test]
    public function testCreateUserRejectsPasswordFailingPolicy(): void
    {
        $this->passwordPolicy->method('validatePassword')
            ->with('short')
            ->willReturn(['Password must be at least 8 characters long', 'Password must contain at least one number']);
        $this->userRepository->expects($this->never())->method('createUser');

        $result = $this->service()->createUser([
            'username' => 'newuser',
            'password' => 'short',
            'perm_templ' => 3,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Password must be at least 8 characters long', $result['message']);
    }

    #[Test]
    public function testCreateUserRejectsLdapWhenLdapDisabled(): void
    {
        $this->userRepository->expects($this->never())->method('createUser');

        $result = $this->service(false)->createUser([
            'username' => 'ldapuser',
            'use_ldap' => true,
            'perm_templ' => 3,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('LDAP authentication is not enabled', $result['message']);
    }

    #[Test]
    public function testCreateUserTreatsStringFalseAsSqlAccount(): void
    {
        $this->passwordPolicy->method('validatePassword')->willReturn([]);
        $this->captureWrite('createUser');

        $result = $this->service(true)->createUser([
            'username' => 'newuser',
            'use_ldap' => 'false',
            'password' => 'Secret123!',
            'perm_templ' => 3,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->stored['use_ldap']);
        $this->assertSame(self::HASHED, $this->stored['password']);
    }

    #[Test]
    public function testCreateUserRejectsNonBooleanUseLdap(): void
    {
        $this->userRepository->expects($this->never())->method('createUser');

        $result = $this->service(true)->createUser([
            'username' => 'newuser',
            'use_ldap' => 'maybe',
            'password' => 'Secret123!',
            'perm_templ' => 3,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('use_ldap must be a boolean', $result['message']);
    }

    public static function ldapCreatePayloads(): array
    {
        return [
            'no password' => [[]],
            'password supplied' => [['password' => 'Secret123!']],
        ];
    }

    #[Test]
    #[DataProvider('ldapCreatePayloads')]
    public function testCreateLdapUserStoresPlaceholderWithoutHashingOrPolicy(array $extra): void
    {
        $this->passwordPolicy->expects($this->never())->method('validatePassword');
        $this->hasher->expects($this->never())->method('hashPassword');
        $this->captureWrite('createUser');

        $result = $this->service(true)->createUser(['username' => 'ldapuser', 'use_ldap' => 1, 'perm_templ' => 3] + $extra);

        $this->assertTrue($result['success']);
        $this->assertSame(AuthMethod::LDAP_PASSWORD_PLACEHOLDER, $this->stored['password']);
        $this->assertSame(1, $this->stored['use_ldap']);
    }

    #[Test]
    public function testUpdateUserHashesWithConfiguredHasher(): void
    {
        $this->passwordPolicy->method('validatePassword')->willReturn([]);
        $this->hasher->expects($this->once())->method('hashPassword')->with('Another1!');
        $this->captureWrite('updateUser');

        $result = $this->service()->updateUser(7, ['password' => 'Another1!']);

        $this->assertTrue($result['success']);
        $this->assertSame(self::HASHED, $this->stored['password']);
    }

    #[Test]
    public function testUpdateUserRejectsPasswordFailingPolicyBeforeLookups(): void
    {
        $this->passwordPolicy->method('validatePassword')->willReturn(['Password must contain at least one uppercase letter']);
        $this->userRepository->expects($this->never())->method('getUserByUsername');
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service()->updateUser(7, ['username' => 'renamed', 'password' => 'lowercase1!']);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Password must contain at least one uppercase letter', $result['message']);
    }

    #[Test]
    public function testUpdateUserRejectsPasswordWhenSwitchingToLdap(): void
    {
        $this->passwordPolicy->expects($this->never())->method('validatePassword');
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service(true)->updateUser(7, ['use_ldap' => 1, 'password' => 'Secret123!']);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('LDAP', $result['message']);
    }

    #[Test]
    public function testUpdateUserRejectsLdapWhenLdapDisabled(): void
    {
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service(false)->updateUser(7, ['use_ldap' => 1]);

        $this->assertFalse($result['success']);
        $this->assertSame('LDAP authentication is not enabled', $result['message']);
    }

    #[Test]
    public function testUpdateUserAllowsPasswordWhenSwitchingLdapUserBackToSql(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'auth_method' => 'ldap']);
        $this->passwordPolicy->method('validatePassword')->willReturn([]);
        $this->captureWrite('updateUser');

        $result = $this->service(true)->updateUser(7, ['use_ldap' => 0, 'password' => 'Secret123!']);

        $this->assertTrue($result['success']);
        $this->assertSame(self::HASHED, $this->stored['password']);
    }

    #[Test]
    public function testUpdateUserEnteringLdapStoresPlaceholder(): void
    {
        $this->hasher->expects($this->never())->method('hashPassword');
        $this->captureWrite('updateUser');

        $result = $this->service(true)->updateUser(7, ['use_ldap' => true]);

        $this->assertTrue($result['success']);
        $this->assertSame(AuthMethod::LDAP_PASSWORD_PLACEHOLDER, $this->stored['password']);
        $this->assertSame(1, $this->stored['use_ldap']);
    }

    #[Test]
    public function testUpdateUserLeavingLdapRequiresPassword(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'auth_method' => 'ldap']);
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service(true)->updateUser(7, ['use_ldap' => false]);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
        $this->assertSame('Password is required when disabling LDAP authentication', $result['message']);
    }

    #[Test]
    public function testUpdateUserOfLdapAccountWithoutFlagChangeKeepsPassword(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'auth_method' => 'ldap']);
        $this->captureWrite('updateUser');

        $result = $this->service(true)->updateUser(7, ['fullname' => 'Renamed']);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('password', $this->stored);
    }

    #[Test]
    public function testUpdateUserTreatsZeroAsAPassword(): void
    {
        $this->passwordPolicy->method('validatePassword')->with('0')->willReturn(['Password must be at least 8 characters long']);
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service()->updateUser(7, ['password' => '0']);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status']);
    }

    #[Test]
    public function testUpdateUserWithoutPasswordSkipsPolicyAndHasher(): void
    {
        $this->passwordPolicy->expects($this->never())->method('validatePassword');
        $this->hasher->expects($this->never())->method('hashPassword');
        $this->userRepository->method('updateUser')->willReturn(true);

        $result = $this->service()->updateUser(7, ['fullname' => 'New Name']);

        $this->assertTrue($result['success']);
    }
}
