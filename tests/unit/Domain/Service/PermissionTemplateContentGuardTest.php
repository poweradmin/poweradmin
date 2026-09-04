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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionTemplateContentGuard;

/**
 * A template's permission list is itself an authority grant: ticking user_is_ueberuser
 * on the template you already hold makes you a superuser with no assignment step.
 */
class PermissionTemplateContentGuardTest extends TestCase
{
    private const CALLER_ID = 7;
    private const UBERUSER_PERM_ID = 53;

    public function testSuperuserMaySubmitTheUberuserPermission(): void
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: true),
            self::CALLER_ID,
            null,
            [self::UBERUSER_PERM_ID]
        );

        $this->assertNull($error);
    }

    public function testSuperuserMayEditASuperuserTemplate(): void
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: true, templateGrantsUberuser: true),
            self::CALLER_ID,
            1,
            null
        );

        $this->assertNull($error);
    }

    public function testDelegatedEditorMayNotAddTheUberuserPermission(): void
    {
        // The escalation this closes: templ_perm_edit alone was enough to tick the box.
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false),
            self::CALLER_ID,
            9,
            [4, self::UBERUSER_PERM_ID]
        );

        $this->assertSame(PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED, $error);
    }

    public function testPermissionIdsArrivingAsStringsAreStillCaught(): void
    {
        // POST arrays are strings, so a strict comparison would have missed this.
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false),
            self::CALLER_ID,
            9,
            ['4', (string)self::UBERUSER_PERM_ID]
        );

        $this->assertSame(PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED, $error);
    }

    public function testDelegatedEditorMayNotTouchASuperuserTemplateAtAll(): void
    {
        // Also the lockout defence: they cannot strip the Administrator template bare.
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false, templateGrantsUberuser: true),
            self::CALLER_ID,
            1,
            null
        );

        $this->assertSame(PermissionTemplateContentGuard::EDIT_SUPERUSER_DENIED, $error);
    }

    public function testDelegatedEditorMayStillEditAnOrdinaryTemplate(): void
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false),
            self::CALLER_ID,
            9,
            [4, 5]
        );

        $this->assertNull($error);
    }

    public function testDelegatedEditorMayClearAllPermissionsOnAnOrdinaryTemplate(): void
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false),
            self::CALLER_ID,
            9,
            []
        );

        $this->assertNull($error);
    }

    public function testTheRuleDoesNotDependOnTheSeededIdOf53(): void
    {
        // perm_items has no unique constraint and no fixed ids, so the name is resolved
        // at run time; a hardcoded 53 would fail both halves of this test.
        $repository = $this->userRepository(isSuperuser: false, uberuserPermIds: [900]);

        $this->assertSame(
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply($repository, self::CALLER_ID, 9, [900])
        );
        $this->assertNull(
            PermissionTemplateContentGuard::apply($repository, self::CALLER_ID, 9, [53])
        );
    }

    public function testEveryRowCarryingTheNameIsBlocked(): void
    {
        // perm_items has no unique constraint, and templateGrantsUberuser() matches by
        // name, so checking only the lowest id would leave the duplicate exploitable.
        $repository = $this->userRepository(isSuperuser: false, uberuserPermIds: [53, 91]);

        $this->assertSame(
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply($repository, self::CALLER_ID, 9, [91])
        );
    }

    public function testMissingUberuserPermissionAllowsTheWrite(): void
    {
        // No such row means no template can grant it, so there is nothing to deny.
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false, uberuserPermIds: []),
            self::CALLER_ID,
            9,
            [self::UBERUSER_PERM_ID]
        );

        $this->assertNull($error);
    }

    public function testNestedArraysNeverMatchByCoercion(): void
    {
        // (int)[1] is 1 in PHP, so non-scalars must be skipped rather than cast.
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false, uberuserPermIds: [1]),
            self::CALLER_ID,
            9,
            [[42]]
        );

        $this->assertNull($error);
    }

    public function testLockedTemplateIsReportedBeforeTheContentRule(): void
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository(isSuperuser: false, templateGrantsUberuser: true),
            self::CALLER_ID,
            1,
            [self::UBERUSER_PERM_ID]
        );

        $this->assertSame(PermissionTemplateContentGuard::EDIT_SUPERUSER_DENIED, $error);
    }

    public function testSuperuserCheckShortCircuitsTheLookups(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('hasAdminPermission')->willReturn(true);
        $repository->expects($this->never())->method('templateGrantsUberuser');
        $repository->expects($this->never())->method('getPermissionIdsByName');

        $this->assertNull(
            PermissionTemplateContentGuard::apply($repository, self::CALLER_ID, 1, [self::UBERUSER_PERM_ID])
        );
    }

    public function testFilterHidesTheUberuserRowFromNonSuperusers(): void
    {
        $permissions = [
            ['id' => 4, 'name' => 'zone_content_view_own'],
            ['id' => self::UBERUSER_PERM_ID, 'name' => 'user_is_ueberuser'],
        ];

        $filtered = PermissionTemplateContentGuard::filterOfferedPermissions($permissions, false);

        $this->assertCount(1, $filtered);
        $this->assertSame('zone_content_view_own', $filtered[0]['name']);
    }

    public function testFilterLeavesTheListIntactForSuperusers(): void
    {
        $permissions = [['id' => self::UBERUSER_PERM_ID, 'name' => 'user_is_ueberuser']];

        $this->assertSame($permissions, PermissionTemplateContentGuard::filterOfferedPermissions($permissions, true));
    }

    private function userRepository(
        bool $isSuperuser,
        bool $templateGrantsUberuser = false,
        ?array $uberuserPermIds = [self::UBERUSER_PERM_ID]
    ): UserRepository {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('hasAdminPermission')->willReturn($isSuperuser);
        $repository->method('templateGrantsUberuser')->willReturn($templateGrantsUberuser);
        $repository->method('getPermissionIdsByName')->willReturn($uberuserPermIds ?? []);

        return $repository;
    }
}
