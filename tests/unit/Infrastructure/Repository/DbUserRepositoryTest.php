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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\UserId;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryTest extends TestCase
{
    private DbUserRepository $repository;
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDOCommon::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->repository = new DbUserRepository($this->db, $this->config);
    }

    private function createUserDbRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'username' => 'testuser',
            'password' => '$2y$10$hashedpassword',
            'fullname' => 'Test User',
            'email' => 'test@example.com',
            'description' => 'Test description',
            'active' => 1,
            'perm_templ' => 1,
            'use_ldap' => 0
        ], $overrides);
    }

    // ========== findByUsername tests ==========

    #[Test]
    public function testFindByUsernameReturnsUserWhenFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'password' => 'hashed_pw',
            'use_ldap' => 0
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUsername('testuser');

        $this->assertInstanceOf(User::class, $result);
    }

    #[Test]
    public function testFindByUsernameReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->findByUsername('nonexistent');

        $this->assertNull($result);
    }

    // ========== getUserById tests ==========

    #[Test]
    public function testGetUserByIdReturnsUserDataWhenFound(): void
    {
        $userData = $this->createUserDbRow();

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($userData);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserById(1);

        $this->assertIsArray($result);
        $this->assertEquals('testuser', $result['username']);
    }

    #[Test]
    public function testGetUserByIdReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserById(999);

        $this->assertNull($result);
    }

    // ========== getUserByUsername tests ==========

    #[Test]
    public function testGetUserByUsernameReturnsUserDataWhenFound(): void
    {
        $userData = $this->createUserDbRow();

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($userData);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserByUsername('testuser');

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    #[Test]
    public function testGetUserByUsernameReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserByUsername('nonexistent');

        $this->assertNull($result);
    }

    // ========== getUserByEmail tests ==========

    #[Test]
    public function testGetUserByEmailReturnsUserDataWhenFound(): void
    {
        $userData = $this->createUserDbRow();

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($userData);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserByEmail('test@example.com');

        $this->assertIsArray($result);
        $this->assertEquals('test@example.com', $result['email']);
    }

    #[Test]
    public function testGetUserByEmailReturnsNullWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserByEmail('nonexistent@example.com');

        $this->assertNull($result);
    }

    // ========== updatePassword tests ==========

    #[Test]
    public function testUpdatePasswordReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE users SET password'))
            ->willReturn($stmt);

        $result = $this->repository->updatePassword(1, 'new_hashed_password');

        $this->assertTrue($result);
    }

    // ========== createUser tests ==========

    #[Test]
    public function testCreateUserReturnsIdOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('INSERT INTO users'))
            ->willReturn($stmt);

        $this->db->method('lastInsertId')->willReturn('42');

        $userData = [
            'username' => 'newuser',
            'password' => 'hashed_password',
            'fullname' => 'New User',
            'email' => 'new@example.com'
        ];

        $result = $this->repository->createUser($userData);

        $this->assertEquals(42, $result);
    }

    #[Test]
    public function testCreateUserReturnsNullOnFailure(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $userData = [
            'username' => 'newuser',
            'password' => 'hashed_password'
        ];

        $result = $this->repository->createUser($userData);

        $this->assertNull($result);
    }

    // ========== updateUser tests ==========

    #[Test]
    public function testUpdateUserReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE users SET'))
            ->willReturn($stmt);

        $result = $this->repository->updateUser(1, ['fullname' => 'Updated Name']);

        $this->assertTrue($result);
    }

    #[Test]
    public function testUpdateUserReturnsTrueWhenNoFieldsToUpdate(): void
    {
        // When no valid fields to update, should return true without DB call
        $result = $this->repository->updateUser(1, []);

        $this->assertTrue($result);
    }

    #[Test]
    public function testUpdateUserIgnoresInvalidFields(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('fullname'),
                $this->logicalNot($this->stringContains('invalid_field'))
            ))
            ->willReturn($stmt);

        $result = $this->repository->updateUser(1, [
            'fullname' => 'Updated Name',
            'invalid_field' => 'should be ignored'
        ]);

        $this->assertTrue($result);
    }

    // ========== canViewOthersContent tests ==========

    #[Test]
    public function testCanViewOthersContentReturnsTrueWhenPermissionExists(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $this->db->method('prepare')->willReturn($stmt);

        $userId = new UserId(1);
        $result = $this->repository->canViewOthersContent($userId);

        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewOthersContentReturnsFalseWhenNoPermission(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $userId = new UserId(1);
        $result = $this->repository->canViewOthersContent($userId);

        $this->assertFalse($result);
    }

    // ========== getUserPermissions tests ==========

    #[Test]
    public function testGetUserPermissionsReturnsPermissionsArray(): void
    {
        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->createUserDbRow(),
                ['permission' => 'zone_content_view_own'],
                ['permission' => 'zone_content_edit_own'],
                false
            );

        $this->db->method('prepare')->willReturn($userStmt);

        $result = $this->repository->getUserPermissions(1);

        $this->assertIsArray($result);
    }

    #[Test]
    public function testGetUserPermissionsReturnsEmptyArrayWhenUserNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserPermissions(999);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========== hasAdminPermission tests ==========

    #[Test]
    public function testHasAdminPermissionReturnsTrueForAdmin(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['permission' => 'user_is_ueberuser']);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->hasAdminPermission(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function testHasAdminPermissionReturnsFalseForNonAdmin(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->hasAdminPermission(2);

        $this->assertFalse($result);
    }

    // ========== isUberuser tests ==========

    #[Test]
    public function testIsUberuserReturnsTrueForUberuser(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->isUberuser(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function testIsUberuserReturnsFalseForNonUberuser(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->isUberuser(2);

        $this->assertFalse($result);
    }

    // ========== countUberusers tests ==========

    #[Test]
    public function testCountUberusersReturnsCorrectCount(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(3);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->repository->countUberusers();

        $this->assertEquals(3, $result);
    }

    // ========== getTotalUserCount tests ==========

    #[Test]
    public function testGetTotalUserCountReturnsCorrectCount(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(10);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->repository->getTotalUserCount();

        $this->assertEquals(10, $result);
    }

    // ========== getUserZones tests ==========

    #[Test]
    public function testGetUserZonesReturnsZonesArray(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 1, 'domain_id' => 100],
            ['id' => 2, 'domain_id' => 101]
        ]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->getUserZones(1);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['domain_id']);
    }

    // ========== transferUserZones tests ==========

    #[Test]
    public function testTransferUserZonesReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE zones SET owner'))
            ->willReturn($stmt);

        $result = $this->repository->transferUserZones(1, 2);

        $this->assertTrue($result);
    }

    // ========== assignPermissionTemplate tests ==========

    #[Test]
    public function testAssignPermissionTemplateReturnsTrueOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->with($this->stringContains('UPDATE users SET perm_templ'))
            ->willReturn($stmt);

        $result = $this->repository->assignPermissionTemplate(1, 5);

        $this->assertTrue($result);
    }

    // ========== permissionTemplateExists tests ==========

    #[Test]
    public function testPermissionTemplateExistsReturnsTrueWhenExists(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->permissionTemplateExists(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function testPermissionTemplateExistsReturnsFalseWhenNotExists(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repository->permissionTemplateExists(999);

        $this->assertFalse($result);
    }

    // ========== unassignUserZones tests ==========

    #[Test]
    public function testUnassignUserZonesReturnsFalseAsDeprecated(): void
    {
        $result = $this->repository->unassignUserZones(1);

        $this->assertFalse($result);
    }
}
