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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use ReflectionClass;
use ReflectionMethod;

/**
 * The web edit/create paths gate perm_templ the same way the API does: holding
 * user_edit_templ_perm delegates template management, it does not let the holder
 * grant superuser access or retemplate their own account.
 */
class UserManagerTemplateAssignmentTest extends TestCase
{
    private const CALLER_ID = 7;

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID]);
        parent::tearDown();
    }

    public function testUeberuserMayAssignASuperuserTemplate(): void
    {
        $error = $this->check(
            permissions: ['user_is_ueberuser' => true],
            templateIsSuperuser: true,
            targetUserId: 42
        );

        $this->assertNull($error);
    }

    public function testDelegatedManagerMayNotAssignASuperuserTemplate(): void
    {
        // The hole this closes: user_edit_templ_perm alone used to be enough to
        // put another account on the Administrator template.
        $error = $this->check(
            permissions: ['user_edit_templ_perm' => true, 'user_edit_others' => true],
            templateIsSuperuser: true,
            targetUserId: 42
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    public function testDelegatedManagerMayAssignAnOrdinaryTemplate(): void
    {
        $error = $this->check(
            permissions: ['user_edit_templ_perm' => true, 'user_edit_others' => true],
            templateIsSuperuser: false,
            targetUserId: 42
        );

        $this->assertNull($error);
    }

    public function testSelfAssignmentNeedsUserEditOthers(): void
    {
        $error = $this->check(
            permissions: ['user_edit_templ_perm' => true, 'user_edit_others' => false],
            templateIsSuperuser: false,
            targetUserId: self::CALLER_ID
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('own permission template', $error);
    }

    public function testSelfAssignmentIsAllowedWithUserEditOthers(): void
    {
        $error = $this->check(
            permissions: ['user_edit_templ_perm' => true, 'user_edit_others' => true],
            templateIsSuperuser: false,
            targetUserId: self::CALLER_ID
        );

        $this->assertNull($error);
    }

    public function testCreatePathHasNoSelfAssignmentButStillBlocksSuperuser(): void
    {
        // A create has no target account yet, so only the superuser rule applies.
        $error = $this->check(
            permissions: ['user_edit_templ_perm' => true, 'user_edit_others' => false],
            templateIsSuperuser: true,
            targetUserId: null
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    /**
     * @param array<string, bool> $permissions
     */
    private function check(array $permissions, bool $templateIsSuperuser, ?int $targetUserId): ?string
    {
        $_SESSION[SessionKeys::USERID] = self::CALLER_ID;

        // Only the two leaf lookups are stubbed, so the real policy in
        // checkPermissionTemplateAssignment() runs and this asserts the web mapping
        // against it rather than against a restated copy of the rules.
        $apiPermissionService = $this->createPartialMock(
            ApiPermissionService::class,
            ['userHasPermission', 'templateGrantsSuperuser', 'getUserPermissionTemplateId']
        );
        $apiPermissionService->method('userHasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => $permissions[$permission] ?? false);
        $apiPermissionService->method('templateGrantsSuperuser')->willReturn($templateIsSuperuser);
        // Every scenario here assigns template 3 to an account holding a different one,
        // so the unchanged-template exemption never applies.
        $apiPermissionService->method('getUserPermissionTemplateId')->willReturn(9);

        $manager = (new ReflectionClass(UserManager::class))->newInstanceWithoutConstructor();
        $this->setProperty($manager, 'apiPermissionService', $apiPermissionService);

        $method = new ReflectionMethod($manager, 'permissionTemplateAssignmentError');
        $method->setAccessible(true);

        return $method->invoke($manager, 3, $targetUserId);
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
