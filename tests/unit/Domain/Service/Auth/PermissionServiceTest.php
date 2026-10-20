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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use TestHelpers\PermissionServiceTestCase;

#[CoversClass(PermissionService::class)]
class PermissionServiceTest extends PermissionServiceTestCase
{

    private PermissionService $service;
    private UserRepositoryInterface&MockObject $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
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
        $this->assertTrue($this->service->hasPermission($userId, Permission::PERM_ZONE_CONTENT_VIEW_OWN));
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
            ->willReturn([Permission::PERM_ZONE_CONTENT_VIEW_OWN, Permission::PERM_ZONE_CONTENT_EDIT_OWN]);

        $this->assertTrue($this->service->hasPermission($userId, Permission::PERM_ZONE_CONTENT_VIEW_OWN));
        $this->assertTrue($this->service->hasPermission($userId, Permission::PERM_ZONE_CONTENT_EDIT_OWN));
        $this->assertFalse($this->service->hasPermission($userId, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS));
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
    public function testUserOwnsZoneDelegatesToRepository(): void
    {
        $this->userRepository->method('userOwnsZone')
            ->willReturnMap([
                [1, 100, true],
                [2, 100, false],
            ]);

        $this->assertTrue($this->service->userOwnsZone(1, 100));
        $this->assertFalse($this->service->userOwnsZone(2, 100));
    }

    #[Test]
    public function testCanPerformZoneActionShortCircuitsForAdmin(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        // Admin short-circuit must not consult ownership
        $this->userRepository->expects($this->never())->method('userOwnsZone');
        $this->assertTrue($this->service->canPerformZoneAction($userId, 100, Permission::PERM_ZONE_DELETE_OWN));
    }

    #[Test]
    public function testCanPerformZoneActionNeedsGrantFromAnySourceAndOwnership(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturn(false);
        // getUserPermissions already unions the user's template with every group template
        $this->userRepository->method('getUserPermissions')->with(5)->willReturn([Permission::PERM_ZONE_DELETE_OWN]);
        $this->userRepository->method('userOwnsZone')->willReturnMap([
            [5, 100, true],
            [5, 200, false],
        ]);
        $this->assertTrue($this->service->canPerformZoneAction(5, 100, Permission::PERM_ZONE_DELETE_OWN));
        $this->assertFalse($this->service->canPerformZoneAction(5, 200, Permission::PERM_ZONE_DELETE_OWN));
        $this->assertFalse($this->service->canPerformZoneAction(5, 100, Permission::PERM_ZONE_DNSSEC_MANAGE_OWN));
    }

    #[Test]
    public function testCanManageDnssecForZoneShortCircuitsForAdmin(): void
    {
        $userId = 1;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->userRepository->expects($this->never())->method('userOwnsZone');
        $this->assertTrue($this->service->canManageDnssecForZone($userId, 100));
    }

    #[Test]
    public function testGetUserPermissions(): void
    {
        $userId = 1;
        $permissions = [Permission::PERM_ZONE_CONTENT_VIEW_OWN, Permission::PERM_ZONE_CONTENT_EDIT_OWN];

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
            ->willReturn([Permission::PERM_ZONE_CONTENT_VIEW_OTHERS]);

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
            ->willReturn([Permission::PERM_ZONE_CONTENT_VIEW_OWN]);

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
            ->willReturn([Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]);

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
            ->willReturn([Permission::PERM_ZONE_CONTENT_EDIT_OWN]);

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
            ->willReturn([Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT]);

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
    public function testGetEditPermissionLevelForZoneReturnsAllForAdmin(): void
    {
        $userId = 10;
        $domainId = 100;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(true);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([]);

        $this->assertEquals('all', $this->service->getEditPermissionLevelForZone($userId, $domainId));
    }

    #[Test]
    public function testGetEditPermissionLevelForZoneReturnsAllForEditOthers(): void
    {
        $userId = 11;
        $domainId = 101;

        $this->userRepository->method('hasAdminPermission')
            ->with($userId)
            ->willReturn(false);

        $this->userRepository->method('getUserPermissions')
            ->with($userId)
            ->willReturn([Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]);

        $this->assertEquals('all', $this->service->getEditPermissionLevelForZone($userId, $domainId));
    }

    #[Test]
    public function testGetEditPermissionLevelForZoneNeedsOwnershipForOwnLevels(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturn(false);
        $this->userRepository->method('getUserPermissions')->willReturnMap([
            [1, [Permission::PERM_ZONE_CONTENT_EDIT_OWN]],
            [2, [Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT]],
            [3, []],
        ]);
        $this->userRepository->method('userOwnsZone')->willReturnCallback(fn(int $userId, int $domainId) => $domainId === 100);
        $this->assertSame('own', $this->service->getEditPermissionLevelForZone(1, 100));
        $this->assertSame('none', $this->service->getEditPermissionLevelForZone(1, 200));
        $this->assertSame('own_as_client', $this->service->getEditPermissionLevelForZone(2, 100));
        $this->assertSame('none', $this->service->getEditPermissionLevelForZone(3, 100));
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
                [2, [Permission::PERM_ZONE_META_EDIT_OTHERS]],
                [3, [Permission::PERM_ZONE_META_EDIT_OWN]],
                [4, []],
            ]);

        $this->assertEquals('all', $this->service->getZoneMetaEditPermissionLevel(1)); // admin
        $this->assertEquals('all', $this->service->getZoneMetaEditPermissionLevel(2)); // edit_others
        $this->assertEquals('own', $this->service->getZoneMetaEditPermissionLevel(3)); // edit_own
        $this->assertEquals('none', $this->service->getZoneMetaEditPermissionLevel(4)); // no permission
    }

    #[Test]
    public function testGetZoneMetadataViewPermissionLevel(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
                [4, false],
                [5, false],
                [6, false],
                [7, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, [Permission::PERM_ZONE_METADATA_VIEW_OTHERS]],
                [3, [Permission::PERM_ZONE_META_EDIT_OTHERS]],
                [4, [Permission::PERM_ZONE_METADATA_VIEW_OWN]],
                [5, [Permission::PERM_ZONE_META_EDIT_OWN]],
                [6, [Permission::PERM_ZONE_CONTENT_VIEW_OWN, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS]],
                [7, []],
            ]);

        $this->assertEquals('all', $this->service->getZoneMetadataViewPermissionLevel(1)); // admin
        $this->assertEquals('all', $this->service->getZoneMetadataViewPermissionLevel(2)); // view_others
        $this->assertEquals('all', $this->service->getZoneMetadataViewPermissionLevel(3)); // edit implies view
        $this->assertEquals('own', $this->service->getZoneMetadataViewPermissionLevel(4)); // view_own
        $this->assertEquals('own', $this->service->getZoneMetadataViewPermissionLevel(5)); // edit implies view
        $this->assertEquals('none', $this->service->getZoneMetadataViewPermissionLevel(6)); // content view alone no longer implies
        $this->assertEquals('none', $this->service->getZoneMetadataViewPermissionLevel(7)); // no permission
    }

    #[Test]
    public function testGetZoneOwnershipViewPermissionLevel(): void
    {
        $this->userRepository->method('hasAdminPermission')
            ->willReturnMap([
                [1, true],
                [2, false],
                [3, false],
                [4, false],
                [5, false],
                [6, false],
                [7, false],
            ]);

        $this->userRepository->method('getUserPermissions')
            ->willReturnMap([
                [1, []],
                [2, [Permission::PERM_ZONE_OWNERSHIP_VIEW_OTHERS]],
                [3, [Permission::PERM_ZONE_META_EDIT_OTHERS]],
                [4, [Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN]],
                [5, [Permission::PERM_ZONE_META_EDIT_OWN]],
                [6, [Permission::PERM_ZONE_CONTENT_VIEW_OWN, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS]],
                [7, []],
            ]);

        $this->assertEquals('all', $this->service->getZoneOwnershipViewPermissionLevel(1)); // admin
        $this->assertEquals('all', $this->service->getZoneOwnershipViewPermissionLevel(2)); // view_others
        $this->assertEquals('all', $this->service->getZoneOwnershipViewPermissionLevel(3)); // edit implies view
        $this->assertEquals('own', $this->service->getZoneOwnershipViewPermissionLevel(4)); // view_own
        $this->assertEquals('own', $this->service->getZoneOwnershipViewPermissionLevel(5)); // edit implies view
        $this->assertEquals('none', $this->service->getZoneOwnershipViewPermissionLevel(6)); // content view alone no longer implies
        $this->assertEquals('none', $this->service->getZoneOwnershipViewPermissionLevel(7)); // no permission
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
                [2, [Permission::PERM_ZONE_DELETE_OTHERS]],
                [3, [Permission::PERM_ZONE_DELETE_OWN]],
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
                [2, [Permission::PERM_USER_VIEW_OTHERS]],
                [3, []],
            ]);

        $this->assertTrue($this->service->canViewOthersContent(1)); // admin
        $this->assertTrue($this->service->canViewOthersContent(2)); // has permission
        $this->assertFalse($this->service->canViewOthersContent(3)); // no permission
    }

    #[Test]
    public function testCanViewZoneNeedsOwnershipForViewOwn(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturnMap([[1, true], [2, false], [3, false], [4, false]]);
        $this->userRepository->method('getUserPermissions')->willReturnMap([
            [1, []],
            [2, [Permission::PERM_ZONE_CONTENT_VIEW_OTHERS]],
            [3, [Permission::PERM_ZONE_CONTENT_VIEW_OWN]],
            [4, []],
        ]);
        $this->userRepository->method('userOwnsZone')->willReturnCallback(fn(int $userId, int $domainId) => $domainId === 100);

        $this->assertTrue($this->service->canViewZone(1, 200));
        $this->assertTrue($this->service->canViewZone(2, 200));
        $this->assertTrue($this->service->canViewZone(3, 100));
        $this->assertFalse($this->service->canViewZone(3, 200));
        $this->assertFalse($this->service->canViewZone(4, 100));
    }

    #[Test]
    public function testZoneLogLevelAndPermissionFlags(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturnMap([[1, true], [2, false], [3, false]]);
        $this->userRepository->method('getUserPermissions')->willReturnMap([
            [1, []],
            [2, [Permission::PERM_ZONE_LOGS_VIEW_OWN, Permission::PERM_SEARCH]],
            [3, []],
        ]);

        $this->assertSame('all', $this->service->getZoneLogPermissionLevel(1));
        $this->assertSame('own', $this->service->getZoneLogPermissionLevel(2));
        $this->assertSame('none', $this->service->getZoneLogPermissionLevel(3));

        $this->assertSame(
            [Permission::PERM_SEARCH => true, Permission::PERM_USER_IS_UEBERUSER => false],
            $this->service->getPermissionFlags(2, [Permission::PERM_SEARCH, Permission::PERM_USER_IS_UEBERUSER])
        );
        $this->assertSame([Permission::PERM_SEARCH => true], $this->service->getPermissionFlags(1, [Permission::PERM_SEARCH]));
    }

    #[Test]
    public function testCanCreateZoneNeedsTheGrantForTheKind(): void
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
                [2, [Permission::PERM_ZONE_MASTER_ADD]],
                [3, [Permission::PERM_ZONE_SLAVE_ADD]],
            ]);

        foreach (['MASTER', 'NATIVE', 'SLAVE', 'PRODUCER', 'CONSUMER'] as $kind) {
            $this->assertTrue($this->service->canCreateZone(1, $kind), "admin $kind");
        }
        $this->assertTrue($this->service->canCreateZone(2, 'MASTER'));
        $this->assertTrue($this->service->canCreateZone(2, 'native'));
        $this->assertTrue($this->service->canCreateZone(2, 'PRODUCER'));
        $this->assertFalse($this->service->canCreateZone(2, 'SLAVE'));
        $this->assertFalse($this->service->canCreateZone(2, 'CONSUMER'));
        $this->assertTrue($this->service->canCreateZone(3, 'SLAVE'));
        $this->assertTrue($this->service->canCreateZone(3, 'CONSUMER'));
        $this->assertFalse($this->service->canCreateZone(3, 'MASTER'));
        $this->assertFalse($this->service->canCreateZone(1, 'BOGUS'));
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
                [2, [Permission::PERM_ZONE_TEMPL_ADD]],
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
            ->willReturn([Permission::PERM_ZONE_DELETE_OTHERS]);

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
            ->willReturn([Permission::PERM_ZONE_DELETE_OWN]);

        $this->assertTrue($this->service->canDeleteZone($userId, true)); // is owner
        $this->assertFalse($this->service->canDeleteZone($userId, false)); // not owner
    }

    public function testCanDeleteZoneByIdLooksUpOwnershipOnlyForTheOwnLevel(): void
    {
        $service = $this->buildPermissionService(
            permissionsByUser: [7 => [Permission::PERM_ZONE_DELETE_OWN], 8 => [Permission::PERM_ZONE_DELETE_OTHERS]],
            ownedZonesByUser: [7 => [42]]
        );

        $this->assertTrue($service->canDeleteZoneById(7, 42));
        $this->assertFalse($service->canDeleteZoneById(7, 43));
        $this->assertTrue($service->canDeleteZoneById(8, 43));
        $this->assertFalse($service->canDeleteZoneById(9, 42));
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

    #[Test]
    public function testChangeRequestAndApproveLevels(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturnMap([[1, true], [2, false], [3, false], [4, false]]);
        $this->userRepository->method('getUserPermissions')->willReturnMap([
            [1, []],
            [2, [Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS, Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS]],
            [3, [Permission::PERM_ZONE_CHANGE_REQUEST_OWN, Permission::PERM_ZONE_CHANGE_APPROVE_OWN]],
            [4, [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]],
        ]);

        $this->assertSame('all', $this->service->getChangeRequestPermissionLevel(1));
        $this->assertSame('all', $this->service->getChangeRequestPermissionLevel(2));
        $this->assertSame('own', $this->service->getChangeRequestPermissionLevel(3));
        $this->assertSame('none', $this->service->getChangeRequestPermissionLevel(4));

        $this->assertSame('all', $this->service->getChangeApprovePermissionLevel(1));
        $this->assertSame('all', $this->service->getChangeApprovePermissionLevel(2));
        $this->assertSame('own', $this->service->getChangeApprovePermissionLevel(3));
        $this->assertSame('none', $this->service->getChangeApprovePermissionLevel(4));
    }

    #[Test]
    public function testChangeRequestAndApproveLevelsForZoneNeedOwnershipForOwn(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturn(false);
        $this->userRepository->method('getUserPermissions')->willReturnMap([
            [1, [Permission::PERM_ZONE_CHANGE_REQUEST_OWN, Permission::PERM_ZONE_CHANGE_APPROVE_OWN]],
            [2, [Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS, Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS]],
            [3, []],
        ]);
        $this->userRepository->method('userOwnsZone')->willReturnCallback(fn(int $userId, int $domainId) => $domainId === 100);

        $this->assertSame('own', $this->service->getChangeRequestPermissionLevelForZone(1, 100));
        $this->assertSame('none', $this->service->getChangeRequestPermissionLevelForZone(1, 200));
        $this->assertSame('own', $this->service->getChangeApprovePermissionLevelForZone(1, 100));
        $this->assertSame('none', $this->service->getChangeApprovePermissionLevelForZone(1, 200));

        $this->assertSame('all', $this->service->getChangeRequestPermissionLevelForZone(2, 200));
        $this->assertSame('all', $this->service->getChangeApprovePermissionLevelForZone(2, 200));

        $this->assertSame('none', $this->service->getChangeRequestPermissionLevelForZone(3, 100));
        $this->assertSame('none', $this->service->getChangeApprovePermissionLevelForZone(3, 100));
    }

    #[Test]
    public function testAnswersAreCachedUntilForgotten(): void
    {
        $this->userRepository->method('hasAdminPermission')->willReturnOnConsecutiveCalls(false, true);
        $this->userRepository->method('getUserPermissions')
            ->willReturnOnConsecutiveCalls([], [Permission::PERM_ZONE_CONTENT_VIEW_OWN]);
        $this->userRepository->method('userOwnsZone')->willReturnOnConsecutiveCalls(false, true);

        $this->assertFalse($this->service->isAdmin(7));
        $this->assertSame([], $this->service->getUserPermissions(7));
        $this->assertFalse($this->service->userOwnsZone(7, 100));
        // Repeated asks are served from the cache, not the repository.
        $this->assertFalse($this->service->isAdmin(7));
        $this->assertSame([], $this->service->getUserPermissions(7));
        $this->assertFalse($this->service->userOwnsZone(7, 100));

        $this->service->forgetUser(7);

        $this->assertTrue($this->service->isAdmin(7));
        $this->assertSame([Permission::PERM_ZONE_CONTENT_VIEW_OWN], $this->service->getUserPermissions(7));
        $this->assertTrue($this->service->userOwnsZone(7, 100));
    }

    #[Test]
    public function testForgetUserLeavesOtherUsersCached(): void
    {
        $this->userRepository->expects($this->exactly(3))->method('hasAdminPermission')->willReturn(false);
        $this->userRepository->expects($this->exactly(3))->method('userOwnsZone')->willReturn(false);

        $this->service->isAdmin(7);
        $this->service->isAdmin(8);
        $this->service->userOwnsZone(7, 100);
        $this->service->userOwnsZone(8, 100);

        $this->service->forgetUser(7);

        $this->service->isAdmin(7);
        $this->service->isAdmin(8);
        $this->service->userOwnsZone(7, 100);
        $this->service->userOwnsZone(8, 100);
    }

    #[Test]
    public function testForgetZoneRefreshesOwnershipForEveryUserOfThatZoneOnly(): void
    {
        $this->userRepository->method('userOwnsZone')->willReturnCallback(
            function (int $userId, int $domainId): bool {
                static $calls = 0;
                // Only the second round of lookups reports ownership.
                return ++$calls > 3;
            }
        );

        $this->assertFalse($this->service->userOwnsZone(7, 100));
        $this->assertFalse($this->service->userOwnsZone(8, 100));
        $this->assertFalse($this->service->userOwnsZone(7, 200));

        $this->service->forgetZone(100);

        $this->assertTrue($this->service->userOwnsZone(7, 100));
        $this->assertTrue($this->service->userOwnsZone(8, 100));
        $this->assertFalse($this->service->userOwnsZone(7, 200), 'zone 200 stays cached');
    }

    /**
     * Every level ladder: method => [permissions that yield "all", permissions that yield "own"].
     *
     * @return iterable<string, array{string, string[], string[]}>
     */
    public static function levelLadders(): iterable
    {
        yield 'view' => ['getViewPermissionLevel', [Permission::PERM_ZONE_CONTENT_VIEW_OTHERS], [Permission::PERM_ZONE_CONTENT_VIEW_OWN]];
        yield 'edit' => ['getEditPermissionLevel', [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS], [Permission::PERM_ZONE_CONTENT_EDIT_OWN]];
        yield 'meta edit' => ['getZoneMetaEditPermissionLevel', [Permission::PERM_ZONE_META_EDIT_OTHERS], [Permission::PERM_ZONE_META_EDIT_OWN]];
        yield 'metadata view' => [
            'getZoneMetadataViewPermissionLevel',
            [Permission::PERM_ZONE_METADATA_VIEW_OTHERS, Permission::PERM_ZONE_META_EDIT_OTHERS],
            [Permission::PERM_ZONE_METADATA_VIEW_OWN, Permission::PERM_ZONE_META_EDIT_OWN],
        ];
        yield 'ownership view' => [
            'getZoneOwnershipViewPermissionLevel',
            [Permission::PERM_ZONE_OWNERSHIP_VIEW_OTHERS, Permission::PERM_ZONE_META_EDIT_OTHERS],
            [Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN, Permission::PERM_ZONE_META_EDIT_OWN],
        ];
        yield 'zone log' => ['getZoneLogPermissionLevel', [Permission::PERM_ZONE_LOGS_VIEW_OTHERS], [Permission::PERM_ZONE_LOGS_VIEW_OWN]];
        yield 'change request' => ['getChangeRequestPermissionLevel', [Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS], [Permission::PERM_ZONE_CHANGE_REQUEST_OWN]];
        yield 'change approve' => ['getChangeApprovePermissionLevel', [Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS], [Permission::PERM_ZONE_CHANGE_APPROVE_OWN]];
        yield 'delete' => ['getDeletePermissionLevel', [Permission::PERM_ZONE_DELETE_OTHERS], [Permission::PERM_ZONE_DELETE_OWN]];
    }

    /**
     * @param string[] $allGrants
     * @param string[] $ownGrants
     */
    #[Test]
    #[DataProvider('levelLadders')]
    public function testLevelLadderResolvesAllOwnAndNone(string $method, array $allGrants, array $ownGrants): void
    {
        $permissionsByUser = [1 => [], 5 => []];
        $userId = 10;
        foreach ($allGrants as $grant) {
            $permissionsByUser[$userId] = [$grant];
            $permissionsByUser[$userId + 1] = [$grant, ...$ownGrants];
            $userId += 2;
        }
        foreach ($ownGrants as $grant) {
            $permissionsByUser[$userId++] = [$grant];
        }
        $noGrantUser = $userId;
        $permissionsByUser[$noGrantUser] = [Permission::PERM_SEARCH];

        $service = $this->buildPermissionService($permissionsByUser, [1]);

        $this->assertSame('all', $service->$method(1), "$method: admin without grants");
        $this->assertSame('none', $service->$method(5), "$method: empty grant list");
        $userId = 10;
        foreach ($allGrants as $grant) {
            $this->assertSame('all', $service->$method($userId), "$method: $grant alone");
            $this->assertSame('all', $service->$method($userId + 1), "$method: $grant wins over own grants");
            $userId += 2;
        }
        foreach ($ownGrants as $grant) {
            $this->assertSame('own', $service->$method($userId++), "$method: $grant alone");
        }
        $this->assertSame('none', $service->$method($noGrantUser), "$method: unrelated grant only");
    }
}
