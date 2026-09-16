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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use ReflectionMethod;

/**
 * Owner pickers offer every user to callers with user_view_others and only
 * the caller to everyone else; the add-zone pickers follow the create path's
 * own rule instead, and re-offer a posted owner only when they can show it.
 */
class BaseControllerSelectableOwnersTest extends TestCase
{
    private const USERS = [
        ['id' => 1, 'username' => 'admin'],
        ['id' => 7, 'username' => 'client'],
        ['id' => 9, 'username' => 'other'],
    ];

    private function selectableOwners(bool $canViewOthers, int $currentUserId): array
    {
        $controller = $this->controller($currentUserId);
        $controller->method('hasPermission')->with(Permission::PERM_USER_VIEW_OTHERS)->willReturn($canViewOthers);

        return (new ReflectionMethod(BaseController::class, 'selectableOwners'))->invoke($controller, self::USERS);
    }

    private function assignableOwners(bool $canAssignOthers, int $currentUserId, bool $canViewOthers = true): array
    {
        $controller = $this->controller($currentUserId);
        $controller->method('canAssignOtherOwners')->willReturn($canAssignOthers);
        $controller->method('hasPermission')->with(Permission::PERM_USER_VIEW_OTHERS)->willReturn($canViewOthers);

        return (new ReflectionMethod(BaseController::class, 'assignableOwners'))->invoke($controller, self::USERS);
    }

    private function preservedOwnerChoice(mixed $ownerInput, int $currentUserId = 7): int|string
    {
        // Drivers without native int columns hand ids back as strings.
        $offered = [['id' => 7, 'username' => 'client'], ['id' => '9', 'username' => 'other']];

        return (new ReflectionMethod(BaseController::class, 'preservedOwnerChoice'))
            ->invoke($this->controller($currentUserId), $offered, $ownerInput);
    }

    private function controller(int $currentUserId): BaseController&MockObject
    {
        $controller = $this->getMockBuilder(BaseController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run', 'hasPermission', 'canAssignOtherOwners', 'getCurrentUserId'])
            ->getMock();
        $controller->method('getCurrentUserId')->willReturn($currentUserId);

        return $controller;
    }

    public function testViewOthersGrantOffersEveryUser(): void
    {
        $this->assertSame(self::USERS, $this->selectableOwners(true, 7));
    }

    public function testWithoutTheGrantOnlyTheCallerIsOffered(): void
    {
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->selectableOwners(false, 7));
    }

    public function testCallerMissingFromTheListLeavesItEmpty(): void
    {
        $this->assertSame([], $this->selectableOwners(false, 42));
    }

    public function testCallersWhoMayAssignOthersAreOfferedEveryUserForNewZones(): void
    {
        $this->assertSame(self::USERS, $this->assignableOwners(true, 7));
    }

    public function testOtherCallersAreOfferedOnlyThemselvesForNewZones(): void
    {
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->assignableOwners(false, 7));
    }

    public function testAssigningOthersDoesNotRevealUsersTheCallerMayNotSee(): void
    {
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->assignableOwners(true, 7, canViewOthers: false));
    }

    public function testAPostedOwnerIsKeptOnlyWhenThePickerOffersIt(): void
    {
        $this->assertSame(9, $this->preservedOwnerChoice('9'));
        $this->assertSame(7, $this->preservedOwnerChoice('1'));
        $this->assertSame(7, $this->preservedOwnerChoice('abc'));
        $this->assertSame(7, $this->preservedOwnerChoice(null));
        $this->assertSame(7, $this->preservedOwnerChoice(['9']));
    }

    public function testAnExplicitNoUserOwnerIsPreserved(): void
    {
        $this->assertSame('', $this->preservedOwnerChoice(''));
    }
}
