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

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Database\PDOCommon;

#[CoversClass(ApiPermissionService::class)]
class ApiPermissionServiceTest extends TestCase
{
    private ApiPermissionService $service;
    private PDOCommon&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDOCommon::class);
        $this->service = new ApiPermissionService($this->db);
    }

    private function mockPermissionCheck(int $userId, string $permissionName, bool $hasPermission): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($hasPermission ? 1 : 0);

        $this->db->method('prepare')
            ->willReturn($stmt);
    }

    private function setupPermissionMock(array $permissionMap): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturnCallback(function () use (&$permissionMap) {
            static $callIndex = 0;
            return $permissionMap[$callIndex++] ?? 0;
        });

        $this->db->method('prepare')->willReturn($stmt);
    }

    #[Test]
    public function testUserHasPermissionReturnsTrue(): void
    {
        $this->mockPermissionCheck(1, 'zone_content_view_own', true);

        $result = $this->service->userHasPermission(1, 'zone_content_view_own');
        $this->assertTrue($result);
    }

    #[Test]
    public function testUserHasPermissionReturnsFalse(): void
    {
        $this->mockPermissionCheck(1, 'zone_content_view_own', false);

        $result = $this->service->userHasPermission(1, 'zone_content_view_own');
        $this->assertFalse($result);
    }

    #[Test]
    public function testUserOwnsZoneReturnsTrue(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->userOwnsZone(1, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testUserOwnsZoneReturnsFalse(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->userOwnsZone(1, 100);
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanViewZoneAsUberuser(): void
    {
        // Uberuser check returns true
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canViewZone(1, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewZoneWithViewOthersPermission(): void
    {
        // First check: user_is_ueberuser = false
        // Second check: zone_content_view_others = true
        $this->setupPermissionMock([0, 1]);

        $result = $this->service->canViewZone(2, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewZoneWithViewOwnPermissionAndOwnership(): void
    {
        // Mock for permission checks and ownership check
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $callIndex = 0;
        $stmt->method('fetchColumn')->willReturnCallback(function () use (&$callIndex): int {
            $results = [0, 0, 1, 1]; // not ueberuser, not view_others, has view_own, owns zone
            $index = $callIndex++;
            return array_key_exists($index, $results) ? $results[$index] : 0;
        });

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->canViewZone(3, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewZoneReturnsFalseWithoutPermission(): void
    {
        $this->setupPermissionMock([0, 0, 0]); // no permissions

        $result = $this->service->canViewZone(4, 100);
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanEditZoneAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canEditZone(1, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanEditZoneWithEditOthersPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has edit_others

        $result = $this->service->canEditZone(2, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanDeleteZoneAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canDeleteZone(1, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanDeleteZoneWithDeleteOthersPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has delete_others

        $result = $this->service->canDeleteZone(2, 100);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateZoneMasterAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canCreateZone(1, 'MASTER');
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateZoneMasterWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has zone_master_add

        $result = $this->service->canCreateZone(2, 'MASTER');
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateZoneNativeWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has zone_master_add

        $result = $this->service->canCreateZone(2, 'NATIVE');
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateZoneSlaveWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has zone_slave_add

        $result = $this->service->canCreateZone(2, 'SLAVE');
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateZoneReturnsFalseForUnknownType(): void
    {
        $this->setupPermissionMock([0]); // not ueberuser

        $result = $this->service->canCreateZone(2, 'UNKNOWN');
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanViewUserSelf(): void
    {
        $this->setupPermissionMock([0]); // not ueberuser, but viewing self

        $result = $this->service->canViewUser(5, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewUserAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canViewUser(1, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewUserWithViewOthersPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_view_others

        $result = $this->service->canViewUser(2, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanViewUserReturnsFalseWithoutPermission(): void
    {
        $this->setupPermissionMock([0, 0]); // no permissions

        $result = $this->service->canViewUser(3, 5);
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanEditUserSelfWithEditOwnPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_edit_own

        $result = $this->service->canEditUser(5, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanEditUserAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canEditUser(1, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanEditUserWithEditOthersPermission(): void
    {
        // For canEditUser(2, 5): not editing self, so checks:
        // 1. user_is_ueberuser -> false
        // 2. (userId === targetUserId && user_edit_own) -> skipped since userId != targetUserId
        // 3. user_edit_others -> true
        $this->setupPermissionMock([0, 1]); // not ueberuser, has edit_others

        $result = $this->service->canEditUser(2, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateUserAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canCreateUser(1);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanCreateUserWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_add_new

        $result = $this->service->canCreateUser(2);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanDeleteUserAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canDeleteUser(1, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanDeleteUserWithEditOthersPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_edit_others

        $result = $this->service->canDeleteUser(2, 5);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCannotDeleteSelfWithEditOthersPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_edit_others but deleting self

        $result = $this->service->canDeleteUser(5, 5);
        $this->assertFalse($result);
    }

    #[Test]
    public function testCanEditPermissionTemplatesAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canEditPermissionTemplates(1);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanEditPermissionTemplatesWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_edit_templ_perm

        $result = $this->service->canEditPermissionTemplates(2);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanListUsersAsUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->canListUsers(1);
        $this->assertTrue($result);
    }

    #[Test]
    public function testCanListUsersWithPermission(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has user_view_others

        $result = $this->service->canListUsers(2);
        $this->assertTrue($result);
    }

    #[Test]
    public function testGetUserVisibleZoneIdsReturnsNullForUberuser(): void
    {
        $this->setupPermissionMock([1]); // user_is_ueberuser = true

        $result = $this->service->getUserVisibleZoneIds(1);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserVisibleZoneIdsReturnsNullForViewOthers(): void
    {
        $this->setupPermissionMock([0, 1]); // not ueberuser, has zone_content_view_others

        $result = $this->service->getUserVisibleZoneIds(2);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetUserVisibleZoneIdsReturnsOwnedZones(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $callIndex = 0;
        $stmt->method('fetchColumn')->willReturnCallback(function () use (&$callIndex): int {
            $results = [0, 0, 1]; // not ueberuser, not view_others, has view_own
            $index = $callIndex++;
            return array_key_exists($index, $results) ? $results[$index] : 0;
        });

        $stmt->method('fetchAll')->willReturn([1, 2, 3]);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->service->getUserVisibleZoneIds(3);
        $this->assertEquals([1, 2, 3], $result);
    }

    #[Test]
    public function testGetUserVisibleZoneIdsReturnsEmptyArrayWithoutPermission(): void
    {
        $this->setupPermissionMock([0, 0, 0]); // no permissions

        $result = $this->service->getUserVisibleZoneIds(4);
        $this->assertEquals([], $result);
    }
}
