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

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The web edit/create paths gate perm_templ the same way the bulk update path
 * does: holding user_edit_templ_perm delegates template management, it does not
 * let the holder grant superuser access or retemplate their own account.
 */
class UserManagerTemplateAssignmentTest extends TestCase
{
    private const CALLER_ID = 7;

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
        parent::tearDown();
    }

    public function testUeberuserMayAssignASuperuserTemplate(): void
    {
        $error = $this->check(
            callerIsSuperuser: true,
            callerMayEditOthers: true,
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
            callerIsSuperuser: false,
            callerMayEditOthers: true,
            templateIsSuperuser: true,
            targetUserId: 42
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    public function testDelegatedManagerMayAssignAnOrdinaryTemplate(): void
    {
        $error = $this->check(
            callerIsSuperuser: false,
            callerMayEditOthers: true,
            templateIsSuperuser: false,
            targetUserId: 42
        );

        $this->assertNull($error);
    }

    public function testSelfAssignmentNeedsUserEditOthers(): void
    {
        $error = $this->check(
            callerIsSuperuser: false,
            callerMayEditOthers: false,
            templateIsSuperuser: false,
            targetUserId: self::CALLER_ID
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('own permission template', $error);
    }

    public function testSelfAssignmentIsAllowedWithUserEditOthers(): void
    {
        $error = $this->check(
            callerIsSuperuser: false,
            callerMayEditOthers: true,
            templateIsSuperuser: false,
            targetUserId: self::CALLER_ID
        );

        $this->assertNull($error);
    }

    public function testCreatePathHasNoSelfAssignmentButStillBlocksSuperuser(): void
    {
        // A create has no target account yet, so only the superuser rule applies.
        $error = $this->check(
            callerIsSuperuser: false,
            callerMayEditOthers: false,
            templateIsSuperuser: true,
            targetUserId: null
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    private function check(
        bool $callerIsSuperuser,
        bool $callerMayEditOthers,
        bool $templateIsSuperuser,
        ?int $targetUserId
    ): ?string {
        $_SESSION['userid'] = self::CALLER_ID;

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($templateIsSuperuser ? 1 : 0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);

        $manager = (new ReflectionClass(UserManager::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty($manager, 'db');
        $property->setAccessible(true);
        $property->setValue($manager, $db);

        $method = new ReflectionMethod($manager, 'permissionTemplateAssignmentError');
        $method->setAccessible(true);

        return $method->invoke($manager, 3, $targetUserId, $callerIsSuperuser, $callerMayEditOthers);
    }
}
