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

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use ReflectionMethod;

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
            caller_is_superuser: true,
            caller_may_edit_others: true,
            template_is_superuser: true,
            target_user_id: 42
        );

        $this->assertNull($error);
    }

    public function testDelegatedManagerMayNotAssignASuperuserTemplate(): void
    {
        // The hole this closes: user_edit_templ_perm alone used to be enough to
        // put another account on the Administrator template.
        $error = $this->check(
            caller_is_superuser: false,
            caller_may_edit_others: true,
            template_is_superuser: true,
            target_user_id: 42
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    public function testDelegatedManagerMayAssignAnOrdinaryTemplate(): void
    {
        $error = $this->check(
            caller_is_superuser: false,
            caller_may_edit_others: true,
            template_is_superuser: false,
            target_user_id: 42
        );

        $this->assertNull($error);
    }

    public function testSelfAssignmentNeedsUserEditOthers(): void
    {
        $error = $this->check(
            caller_is_superuser: false,
            caller_may_edit_others: false,
            template_is_superuser: false,
            target_user_id: self::CALLER_ID
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('own permission template', $error);
    }

    public function testSelfAssignmentIsAllowedWithUserEditOthers(): void
    {
        $error = $this->check(
            caller_is_superuser: false,
            caller_may_edit_others: true,
            template_is_superuser: false,
            target_user_id: self::CALLER_ID
        );

        $this->assertNull($error);
    }

    public function testCreatePathHasNoSelfAssignmentButStillBlocksSuperuser(): void
    {
        // A create has no target account yet, so only the superuser rule applies.
        $error = $this->check(
            caller_is_superuser: false,
            caller_may_edit_others: false,
            template_is_superuser: true,
            target_user_id: null
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('administrator rights', $error);
    }

    private function check(
        bool $caller_is_superuser,
        bool $caller_may_edit_others,
        bool $template_is_superuser,
        ?int $target_user_id
    ): ?string {
        $_SESSION['userid'] = self::CALLER_ID;

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value): string => (string)(int)$value);
        $db->method('queryOne')->willReturn($template_is_superuser ? 1 : 0);

        $method = new ReflectionMethod(UserManager::class, 'permission_template_assignment_error');
        $method->setAccessible(true);

        return $method->invoke(
            null,
            $db,
            3,
            $target_user_id,
            $caller_is_superuser,
            $caller_may_edit_others
        );
    }
}
