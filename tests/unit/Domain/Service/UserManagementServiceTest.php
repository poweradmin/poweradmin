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

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;

#[CoversClass(UserManagementService::class)]
class UserManagementServiceTest extends TestCase
{
    private UserManagementService $service;
    private UserRepository&MockObject $userRepository;
    private PermissionService&MockObject $permissionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->permissionService = $this->createMock(PermissionService::class);

        $this->service = new UserManagementService(
            $this->userRepository,
            $this->permissionService
        );
    }

    // ========== getUserById tests ==========

    #[Test]
    public function testGetUserByIdReturnsNullWhenUserNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(1)
            ->willReturn(null);

        $result = $this->service->getUserById(1);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserByIdReturnsEnrichedUserData(): void
    {
        $userId = 1;
        $userData = [
            'id' => $userId,
            'username' => 'testuser',
            'fullname' => 'Test User',
            'email' => 'test@example.com',
            'description' => 'Test description',
            'active' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-02 00:00:00'
        ];
        $permissions = ['zone_content_view_own', 'zone_content_edit_own'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn($userData);

        $this->permissionService->method('getUserPermissions')
            ->with($userId)
            ->willReturn($permissions);

        $this->permissionService->method('isAdmin')
            ->with($userId)
            ->willReturn(false);

        $result = $this->service->getUserById($userId);

        $this->assertEquals($userId, $result['user_id']);
        $this->assertEquals('testuser', $result['username']);
        $this->assertEquals('Test User', $result['fullname']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertTrue($result['active']);
        $this->assertFalse($result['is_admin']);
        $this->assertEquals($permissions, $result['permissions']);
    }

    // ========== getUsersList tests ==========

    #[Test]
    public function testGetUsersListReturnsEmptyArray(): void
    {
        $pagination = new Pagination(1, 10, 0);

        $this->userRepository->method('getUsersList')
            ->willReturn([]);

        $this->userRepository->method('getTotalUserCount')
            ->willReturn(0);

        $result = $this->service->getUsersList($pagination);

        $this->assertEquals([], $result['data']);
        $this->assertEquals(0, $result['total_count']);
    }

    #[Test]
    public function testGetUsersListReturnsEnrichedUsers(): void
    {
        $pagination = new Pagination(1, 10, 2);

        $users = [
            ['id' => 1, 'username' => 'admin', 'fullname' => 'Admin', 'email' => 'admin@test.com', 'active' => 1, 'zone_count' => 5],
            ['id' => 2, 'username' => 'user', 'fullname' => 'User', 'email' => 'user@test.com', 'active' => 1, 'zone_count' => 2]
        ];

        $this->userRepository->method('getUsersList')->willReturn($users);
        $this->userRepository->method('getTotalUserCount')->willReturn(2);

        $this->permissionService->method('isAdmin')
            ->willReturnMap([
                [1, true],
                [2, false]
            ]);

        $result = $this->service->getUsersList($pagination);

        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['total_count']);
        $this->assertTrue($result['data'][0]['is_admin']);
        $this->assertFalse($result['data'][1]['is_admin']);
        $this->assertEquals(5, $result['data'][0]['zone_count']);
    }

    // ========== userExists tests ==========

    #[Test]
    public function testUserExistsReturnsTrueWhenUserFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(1)
            ->willReturn(['id' => 1]);

        $this->assertTrue($this->service->userExists(1));
    }

    #[Test]
    public function testUserExistsReturnsFalseWhenUserNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(999)
            ->willReturn(null);

        $this->assertFalse($this->service->userExists(999));
    }

    // ========== userExistsByUsername tests ==========

    #[Test]
    public function testUserExistsByUsernameReturnsTrueWhenFound(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->with('testuser')
            ->willReturn(['id' => 1, 'username' => 'testuser']);

        $this->assertTrue($this->service->userExistsByUsername('testuser'));
    }

    #[Test]
    public function testUserExistsByUsernameReturnsFalseWhenNotFound(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->with('nonexistent')
            ->willReturn(null);

        $this->assertFalse($this->service->userExistsByUsername('nonexistent'));
    }

    // ========== userExistsByEmail tests ==========

    #[Test]
    public function testUserExistsByEmailReturnsTrueWhenFound(): void
    {
        $this->userRepository->method('getUserByEmail')
            ->with('test@example.com')
            ->willReturn(['id' => 1, 'email' => 'test@example.com']);

        $this->assertTrue($this->service->userExistsByEmail('test@example.com'));
    }

    #[Test]
    public function testUserExistsByEmailReturnsFalseWhenNotFound(): void
    {
        $this->userRepository->method('getUserByEmail')
            ->with('nonexistent@example.com')
            ->willReturn(null);

        $this->assertFalse($this->service->userExistsByEmail('nonexistent@example.com'));
    }

    // ========== getUserByUsername tests ==========

    #[Test]
    public function testGetUserByUsernameReturnsNullWhenNotFound(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->service->getUserByUsername('nonexistent');
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserByUsernameReturnsEnrichedData(): void
    {
        $userData = ['id' => 1, 'username' => 'testuser', 'fullname' => 'Test', 'email' => 'test@test.com', 'active' => 1];

        $this->userRepository->method('getUserByUsername')
            ->with('testuser')
            ->willReturn($userData);

        $this->permissionService->method('isAdmin')
            ->with(1)
            ->willReturn(false);

        $result = $this->service->getUserByUsername('testuser');

        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals('testuser', $result['username']);
        $this->assertFalse($result['is_admin']);
    }

    // ========== getUserByEmail tests ==========

    #[Test]
    public function testGetUserByEmailReturnsNullWhenNotFound(): void
    {
        $this->userRepository->method('getUserByEmail')
            ->with('nonexistent@test.com')
            ->willReturn(null);

        $result = $this->service->getUserByEmail('nonexistent@test.com');
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserByEmailReturnsEnrichedData(): void
    {
        $userData = ['id' => 1, 'username' => 'testuser', 'fullname' => 'Test', 'email' => 'test@test.com', 'active' => 1];

        $this->userRepository->method('getUserByEmail')
            ->with('test@test.com')
            ->willReturn($userData);

        $this->permissionService->method('isAdmin')
            ->with(1)
            ->willReturn(true);

        $result = $this->service->getUserByEmail('test@test.com');

        $this->assertEquals(1, $result['user_id']);
        $this->assertTrue($result['is_admin']);
    }

    // ========== getUserForVerification tests ==========

    #[Test]
    public function testGetUserForVerificationReturnsNullWhenNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->getUserForVerification(999);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserForVerificationReturnsPermissionFlags(): void
    {
        $userId = 1;
        $userData = ['id' => $userId, 'username' => 'testuser'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn($userData);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_master_add', 'zone_content_view_others']);

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $result = $this->service->getUserForVerification($userId);

        $this->assertEquals($userId, $result['user_id']);
        $this->assertFalse($result['is_admin']);
        $this->assertTrue($result['permissions']['zone_creation_allowed']);
        $this->assertTrue($result['permissions']['zone_management_allowed']);
    }

    #[Test]
    public function testGetUserForVerificationAdminHasAllPermissions(): void
    {
        $userId = 1;
        $userData = ['id' => $userId, 'username' => 'admin'];

        $this->userRepository->method('getUserById')
            ->with($userId)
            ->willReturn($userData);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $result = $this->service->getUserForVerification($userId);

        $this->assertTrue($result['is_admin']);
        $this->assertTrue($result['permissions']['zone_creation_allowed']);
        $this->assertTrue($result['permissions']['zone_management_allowed']);
    }

    // ========== createUser tests ==========

    #[Test]
    public function testCreateUserFailsWithMissingUsername(): void
    {
        $result = $this->service->createUser(['password' => 'test123']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Username is required', $result['message']);
    }

    #[Test]
    public function testCreateUserFailsWithMissingPassword(): void
    {
        $result = $this->service->createUser(['username' => 'testuser']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Password is required', $result['message']);
    }

    #[Test]
    public function testCreateUserFailsWhenUsernameExists(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->with('existinguser')
            ->willReturn(['id' => 1]);

        $result = $this->service->createUser([
            'username' => 'existinguser',
            'password' => 'test123'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Username already exists', $result['message']);
    }

    #[Test]
    public function testCreateUserFailsWhenEmailExists(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->willReturn(null);

        $this->userRepository->method('getUserByEmail')
            ->with('existing@test.com')
            ->willReturn(['id' => 1]);

        $result = $this->service->createUser([
            'username' => 'newuser',
            'password' => 'test123',
            'email' => 'existing@test.com'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Email already exists', $result['message']);
    }

    #[Test]
    public function testCreateUserSucceeds(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->willReturn(null);

        $this->userRepository->method('getUserByEmail')
            ->willReturn(null);

        $this->userRepository->method('createUser')
            ->willReturn(42);

        $result = $this->service->createUser([
            'username' => 'newuser',
            'password' => 'test123',
            'email' => 'new@test.com'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(42, $result['user_id']);
    }

    #[Test]
    public function testCreateUserFailsOnRepositoryError(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->willReturn(null);

        $this->userRepository->method('createUser')
            ->willReturn(null);

        $result = $this->service->createUser([
            'username' => 'newuser',
            'password' => 'test123'
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to create user', $result['message']);
    }

    #[Test]
    public function testCreateUserHandlesException(): void
    {
        $this->userRepository->method('getUserByUsername')
            ->willReturn(null);

        $this->userRepository->method('createUser')
            ->willThrowException(new Exception('Database error'));

        $result = $this->service->createUser([
            'username' => 'newuser',
            'password' => 'test123'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Database error', $result['message']);
    }

    // ========== updateUser tests ==========

    #[Test]
    public function testUpdateUserFailsWhenUserNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->updateUser(999, ['fullname' => 'New Name']);

        $this->assertFalse($result['success']);
        $this->assertEquals('User not found', $result['message']);
    }

    #[Test]
    public function testUpdateUserFailsWhenUsernameExistsForOtherUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('getUserByUsername')
            ->with('existinguser')
            ->willReturn(['id' => 2]); // Different user

        $result = $this->service->updateUser(1, ['username' => 'existinguser']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Username already exists', $result['message']);
    }

    #[Test]
    public function testUpdateUserAllowsSameUsernameForSameUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'sql']);

        $this->userRepository->method('getUserByUsername')
            ->with('sameuser')
            ->willReturn(['id' => 1]); // Same user

        $this->userRepository->method('updateUser')
            ->willReturn(true);

        $result = $this->service->updateUser(1, ['username' => 'sameuser']);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function testUpdateUserFailsWhenDisablingLastUberuser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('isUberuser')
            ->with(1)
            ->willReturn(true);

        $this->userRepository->method('countUberusers')
            ->willReturn(1);

        $result = $this->service->updateUser(1, ['active' => false]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot disable the last remaining super admin', $result['message']);
    }

    #[Test]
    public function testUpdateUserAllowsDisablingNonLastUberuser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'sql']);

        $this->userRepository->method('isUberuser')
            ->with(1)
            ->willReturn(true);

        $this->userRepository->method('countUberusers')
            ->willReturn(2);

        $this->userRepository->method('updateUser')
            ->willReturn(true);

        $result = $this->service->updateUser(1, ['active' => false]);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function testUpdateUserPreventsPasswordForOidcUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'oidc']);

        $result = $this->service->updateUser(1, ['password' => 'newpassword']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OIDC', $result['message']);
    }

    #[Test]
    public function testUpdateUserPreventsPasswordForSamlUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'saml']);

        $result = $this->service->updateUser(1, ['password' => 'newpassword']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SAML', $result['message']);
    }

    #[Test]
    public function testUpdateUserPreventsPasswordForLdapUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'ldap']);

        $result = $this->service->updateUser(1, ['password' => 'newpassword']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('LDAP', $result['message']);
    }

    #[Test]
    public function testUpdateUserAllowsPasswordForSqlUser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1, 'auth_method' => 'sql']);

        $this->userRepository->method('updateUser')
            ->willReturn(true);

        $result = $this->service->updateUser(1, ['password' => 'newpassword']);

        $this->assertTrue($result['success']);
    }

    // ========== deleteUser tests ==========

    #[Test]
    public function testDeleteUserFailsWhenUserNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->deleteUser(999);

        $this->assertFalse($result['success']);
        $this->assertEquals('User not found', $result['message']);
    }

    #[Test]
    public function testDeleteUserFailsWhenDeletingLastUberuser(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('isUberuser')
            ->with(1)
            ->willReturn(true);

        $this->userRepository->method('countUberusers')
            ->willReturn(1);

        $result = $this->service->deleteUser(1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot delete the last remaining super admin', $result['message']);
    }

    #[Test]
    public function testDeleteUserWithZonesRequiresTransferTarget(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('isUberuser')
            ->willReturn(false);

        $this->userRepository->method('getUserZones')
            ->with(1)
            ->willReturn([['id' => 1], ['id' => 2]]);

        $result = $this->service->deleteUser(1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('transfer_to_user_id', $result['message']);
    }

    #[Test]
    public function testDeleteUserFailsWhenTransferTargetNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturnMap([
                [1, ['id' => 1]],
                [999, null]
            ]);

        $this->userRepository->method('isUberuser')
            ->willReturn(false);

        $this->userRepository->method('getUserZones')
            ->with(1)
            ->willReturn([['id' => 1]]);

        $result = $this->service->deleteUser(1, 999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Transfer target user not found', $result['message']);
    }

    #[Test]
    public function testDeleteUserWithZonesTransfersSuccessfully(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturnMap([
                [1, ['id' => 1]],
                [2, ['id' => 2]]
            ]);

        $this->userRepository->method('isUberuser')
            ->willReturn(false);

        $this->userRepository->method('getUserZones')
            ->with(1)
            ->willReturn([['id' => 1], ['id' => 2]]);

        $this->userRepository->method('transferUserZones')
            ->with(1, 2)
            ->willReturn(true);

        $this->userRepository->method('deleteUser')
            ->with(1)
            ->willReturn(true);

        $result = $this->service->deleteUser(1, 2);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('2 zones transferred', $result['message']);
        $this->assertEquals(2, $result['zones_affected']);
    }

    #[Test]
    public function testDeleteUserWithoutZonesSucceeds(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('isUberuser')
            ->willReturn(false);

        $this->userRepository->method('getUserZones')
            ->with(1)
            ->willReturn([]);

        $this->userRepository->method('deleteUser')
            ->with(1)
            ->willReturn(true);

        $result = $this->service->deleteUser(1);

        $this->assertTrue($result['success']);
        $this->assertEquals('User deleted successfully', $result['message']);
        $this->assertEquals(0, $result['zones_affected']);
    }

    // ========== assignPermissionTemplate tests ==========

    #[Test]
    public function testAssignPermissionTemplateFailsWhenUserNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->assignPermissionTemplate(999, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('User not found', $result['message']);
    }

    #[Test]
    public function testAssignPermissionTemplateFailsWhenTemplateNotFound(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('permissionTemplateExists')
            ->with(999)
            ->willReturn(false);

        $result = $this->service->assignPermissionTemplate(1, 999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Permission template not found', $result['message']);
    }

    #[Test]
    public function testAssignPermissionTemplateSucceeds(): void
    {
        $this->userRepository->method('getUserById')
            ->willReturn(['id' => 1]);

        $this->userRepository->method('permissionTemplateExists')
            ->with(2)
            ->willReturn(true);

        $this->userRepository->method('assignPermissionTemplate')
            ->with(1, 2)
            ->willReturn(true);

        $result = $this->service->assignPermissionTemplate(1, 2);

        $this->assertTrue($result['success']);
        $this->assertEquals('Permission template assigned successfully', $result['message']);
    }
}
