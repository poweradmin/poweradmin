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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionService;

#[CoversClass(PermissionService::class)]
class PermissionServiceTest extends TestCase
{
    private PermissionService $service;
    private UserRepository&MockObject $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->service = new PermissionService($this->userRepository);
    }

    #[Test]
    public function testAdminHasAllPermissions(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->assertTrue($this->service->hasPermission($userId, 'any_permission'));
        $this->assertTrue($this->service->hasPermission($userId, 'zone_content_view_own'));
        $this->assertTrue($this->service->hasPermission($userId, 'nonexistent_permission'));
    }

    #[Test]
    public function testNonAdminHasSpecificPermission(): void
    {
        $userId = 2;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_view_own', 'zone_content_edit_own']);

        $this->assertTrue($this->service->hasPermission($userId, 'zone_content_view_own'));
        $this->assertTrue($this->service->hasPermission($userId, 'zone_content_edit_own'));
        $this->assertFalse($this->service->hasPermission($userId, 'zone_content_view_others'));
    }

    #[Test]
    public function testNonAdminWithoutPermission(): void
    {
        $userId = 3;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertFalse($this->service->hasPermission($userId, 'any_permission'));
    }

    #[Test]
    public function testIsAdmin(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
            ]);

        $this->assertTrue($this->service->isAdmin(1));
        $this->assertFalse($this->service->isAdmin(2));
    }

    #[Test]
    public function testGetUserPermissions(): void
    {
        $userId = 1;
        $permissions = ['zone_content_view_own', 'zone_content_edit_own'];

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn($permissions);

        $result = $this->service->getUserPermissions($userId);
        $this->assertEquals($permissions, $result);
    }

    #[Test]
    public function testGetViewPermissionLevelReturnsAllForAdmin(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertEquals('all', $this->service->getViewPermissionLevel($userId));
    }

    #[Test]
    public function testGetViewPermissionLevelReturnsAllForViewOthers(): void
    {
        $userId = 2;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_view_others']);

        $this->assertEquals('all', $this->service->getViewPermissionLevel($userId));
    }

    #[Test]
    public function testGetViewPermissionLevelReturnsOwnForViewOwn(): void
    {
        $userId = 3;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_view_own']);

        $this->assertEquals('own', $this->service->getViewPermissionLevel($userId));
    }

    #[Test]
    public function testGetViewPermissionLevelReturnsNoneWithoutPermissions(): void
    {
        $userId = 4;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertEquals('none', $this->service->getViewPermissionLevel($userId));
    }

    #[Test]
    public function testGetEditPermissionLevelReturnsAllForAdmin(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertEquals('all', $this->service->getEditPermissionLevel($userId));
    }

    #[Test]
    public function testGetEditPermissionLevelReturnsAllForEditOthers(): void
    {
        $userId = 2;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_edit_others']);

        $this->assertEquals('all', $this->service->getEditPermissionLevel($userId));
    }

    #[Test]
    public function testGetEditPermissionLevelReturnsOwnForEditOwn(): void
    {
        $userId = 3;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_edit_own']);

        $this->assertEquals('own', $this->service->getEditPermissionLevel($userId));
    }

    #[Test]
    public function testGetEditPermissionLevelReturnsOwnAsClientForEditOwnAsClient(): void
    {
        $userId = 4;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_content_edit_own_as_client']);

        $this->assertEquals('own_as_client', $this->service->getEditPermissionLevel($userId));
    }

    #[Test]
    public function testGetEditPermissionLevelReturnsNoneWithoutPermissions(): void
    {
        $userId = 5;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertEquals('none', $this->service->getEditPermissionLevel($userId));
    }

    #[Test]
    public function testGetZoneMetaEditPermissionLevel(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
                [4, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, ['zone_meta_edit_others']],
                [3, ['zone_meta_edit_own']],
                [4, []],
            ]);

        $this->assertEquals('all', $this->service->getZoneMetaEditPermissionLevel(1)); // admin
        $this->assertEquals('all', $this->service->getZoneMetaEditPermissionLevel(2)); // edit_others
        $this->assertEquals('own', $this->service->getZoneMetaEditPermissionLevel(3)); // edit_own
        $this->assertEquals('none', $this->service->getZoneMetaEditPermissionLevel(4)); // no permission
    }

    #[Test]
    public function testGetDeletePermissionLevel(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
                [4, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, ['zone_delete_others']],
                [3, ['zone_delete_own']],
                [4, []],
            ]);

        $this->assertEquals('all', $this->service->getDeletePermissionLevel(1)); // admin
        $this->assertEquals('all', $this->service->getDeletePermissionLevel(2)); // delete_others
        $this->assertEquals('own', $this->service->getDeletePermissionLevel(3)); // delete_own
        $this->assertEquals('none', $this->service->getDeletePermissionLevel(4)); // no permission
    }

    #[Test]
    public function testCanViewOthersContent(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, ['user_view_others']],
                [3, []],
            ]);

        $this->assertTrue($this->service->canViewOthersContent(1)); // admin
        $this->assertTrue($this->service->canViewOthersContent(2)); // has permission
        $this->assertFalse($this->service->canViewOthersContent(3)); // no permission
    }

    #[Test]
    public function testCanAddZones(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, ['zone_master_add']],
                [3, []],
            ]);

        $this->assertTrue($this->service->canAddZones(1)); // admin
        $this->assertTrue($this->service->canAddZones(2)); // has permission
        $this->assertFalse($this->service->canAddZones(3)); // no permission
    }

    #[Test]
    public function testCanAddZoneTemplates(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, ['zone_templ_add']],
                [3, []],
            ]);

        $this->assertTrue($this->service->canAddZoneTemplates(1)); // admin
        $this->assertTrue($this->service->canAddZoneTemplates(2)); // has permission
        $this->assertFalse($this->service->canAddZoneTemplates(3)); // no permission
    }

    #[Test]
    public function testCanDeleteZoneAsAdmin(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->assertTrue($this->service->canDeleteZone($userId, true));
        $this->assertTrue($this->service->canDeleteZone($userId, false));
    }

    #[Test]
    public function testCanDeleteZoneWithDeleteOthersPermission(): void
    {
        $userId = 2;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_delete_others']);

        $this->assertTrue($this->service->canDeleteZone($userId, true));
        $this->assertTrue($this->service->canDeleteZone($userId, false));
    }

    #[Test]
    public function testCanDeleteZoneWithDeleteOwnPermission(): void
    {
        $userId = 3;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn(['zone_delete_own']);

        $this->assertTrue($this->service->canDeleteZone($userId, true)); // is owner
        $this->assertFalse($this->service->canDeleteZone($userId, false)); // not owner
    }

    #[Test]
    public function testCannotDeleteZoneWithoutPermission(): void
    {
        $userId = 4;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertFalse($this->service->canDeleteZone($userId, true));
        $this->assertFalse($this->service->canDeleteZone($userId, false));
    }
}
