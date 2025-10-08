<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * Security tests for password updates in UserManagementService
 * Tests that OIDC, SAML, and LDAP users cannot have passwords set via updateUser
 */
class UserManagementServicePasswordTest extends TestCase
{
    private $userRepository;
    private $permissionService;
    private UserManagementService $userManagementService;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(DbUserRepository::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->userManagementService = new UserManagementService(
            $this->userRepository,
            $this->permissionService
        );
    }

    /**
     * Test that OIDC users cannot have passwords set
     */
    public function testCannotSetPasswordForOidcUser(): void
    {
        $userId = 1;
        $userData = ['password' => 'newpassword123'];

        // Mock user exists check
        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'oidc-user',
                'email' => 'oidc@example.com',
                'auth_method' => 'oidc'
            ]);

        // updateUser should never be called since validation fails
        $this->userRepository->expects($this->never())
            ->method('updateUser');

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot set password for OIDC authenticated users', $result['message']);
        $this->assertStringContainsString('OIDC', $result['message']);
    }

    /**
     * Test that SAML users cannot have passwords set
     */
    public function testCannotSetPasswordForSamlUser(): void
    {
        $userId = 2;
        $userData = ['password' => 'newpassword456'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'saml-user',
                'email' => 'saml@example.com',
                'auth_method' => 'saml'
            ]);

        $this->userRepository->expects($this->never())
            ->method('updateUser');

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot set password for SAML authenticated users', $result['message']);
        $this->assertStringContainsString('SAML', $result['message']);
    }

    /**
     * Test that LDAP users cannot have passwords set
     */
    public function testCannotSetPasswordForLdapUser(): void
    {
        $userId = 3;
        $userData = ['password' => 'newpassword789'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'ldap-user',
                'email' => 'ldap@example.com',
                'auth_method' => 'ldap'
            ]);

        $this->userRepository->expects($this->never())
            ->method('updateUser');

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot set password for LDAP authenticated users', $result['message']);
        $this->assertStringContainsString('LDAP', $result['message']);
    }

    /**
     * Test that SQL users CAN have passwords set
     */
    public function testCanSetPasswordForSqlUser(): void
    {
        $userId = 4;
        $userData = ['password' => 'sqlpassword123'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'sql-user',
                'email' => 'sql@example.com',
                'auth_method' => 'sql'
            ]);

        // Mock successful update
        $this->userRepository->expects($this->once())
            ->method('updateUser')
            ->with($userId, $this->callback(function ($data) {
                // Verify password was hashed
                return isset($data['password'])
                    && $data['password'] !== 'sqlpassword123' // Not plain text
                    && password_verify('sqlpassword123', $data['password']); // But verifies correctly
            }))
            ->willReturn(true);

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertTrue($result['success']);
        $this->assertEquals('User updated successfully', $result['message']);
    }

    /**
     * Test that users with missing auth_method (defaults to SQL) CAN have passwords set
     */
    public function testCanSetPasswordForUserWithMissingAuthMethod(): void
    {
        $userId = 5;
        $userData = ['password' => 'legacypassword123'];

        // User without auth_method field (legacy user)
        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'legacy-user',
                'email' => 'legacy@example.com'
                // auth_method is missing
            ]);

        // Should be allowed to update (defaults to SQL)
        $this->userRepository->expects($this->once())
            ->method('updateUser')
            ->willReturn(true);

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertTrue($result['success']);
    }

    /**
     * Test that non-password updates work for external auth users
     */
    public function testCanUpdateNonPasswordFieldsForExternalAuthUsers(): void
    {
        $userId = 6;
        $userData = [
            'fullname' => 'Updated Name',
            'email' => 'newemail@example.com'
            // No password field
        ];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'oidc-user',
                'email' => 'oidc@example.com',
                'auth_method' => 'oidc'
            ]);

        // Should be allowed since no password is being set
        $this->userRepository->expects($this->once())
            ->method('updateUser')
            ->with($userId, $userData)
            ->willReturn(true);

        $result = $this->userManagementService->updateUser($userId, $userData);

        $this->assertTrue($result['success']);
    }

    /**
     * Test that empty password doesn't trigger auth validation
     */
    public function testEmptyPasswordDoesNotTriggerAuthValidation(): void
    {
        $userId = 7;
        $userData = [
            'fullname' => 'Updated Name',
            'password' => '' // Empty password
        ];

        // getUserById will be called by userExists() but not for password validation
        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn([
                'id' => $userId,
                'username' => 'oidc-user',
                'email' => 'oidc@example.com',
                'auth_method' => 'oidc' // External auth user
            ]);

        // updateUser should be called since empty password skips auth method check
        $this->userRepository->expects($this->once())
            ->method('updateUser')
            ->with($userId, $userData)
            ->willReturn(true);

        $result = $this->userManagementService->updateUser($userId, $userData);

        // Should succeed even for OIDC user since no password is being set
        $this->assertTrue($result['success']);
    }
}
