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
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SelfEditFieldGuard;
use TestHelpers\PermissionServiceTestCase;

class SelfEditFieldGuardTest extends PermissionServiceTestCase
{

    // Callers in these cases are user 4 (editing 7) or user 7 (editing themselves).
    private const CALLERS = [4, 7];

    private const STORED_USER = [
        'id' => 7,
        'username' => 'jdoe',
        'use_ldap' => 1,
        'active' => 1,
    ];

    private function permissionService(bool $isUeberuser = false, bool $canEditOthers = false): PermissionService
    {
        $grants = $canEditOthers ? [Permission::PERM_USER_EDIT_OTHERS] : [];

        return $this->buildPermissionService(
            permissionsByUser: array_fill_keys(self::CALLERS, $grants),
            adminUserIds: $isUeberuser ? self::CALLERS : []
        );
    }

    public function testEditingAnotherUserIsNotGated(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            4,
            7,
            self::STORED_USER,
            ['username' => 'renamed', 'use_ldap' => false]
        );

        $this->assertNull($error, 'Different target - the canEditUser check owns that decision');
    }

    public function testUeberuserMayChangeOwnAuthFields(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(isUeberuser: true),
            7,
            7,
            self::STORED_USER,
            ['username' => 'renamed', 'use_ldap' => false, 'active' => false]
        );

        $this->assertNull($error);
    }

    public function testEditOthersHolderMayChangeOwnAuthFields(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(canEditOthers: true),
            7,
            7,
            self::STORED_USER,
            ['username' => 'renamed']
        );

        $this->assertNull($error);
    }

    public function testSelfEditUsernameChangeIsRejected(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['username' => 'admin2']
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('username', $error);
    }

    public function testSelfEditLdapDisableIsRejected(): void
    {
        // The core of #1327: disabling use_ldap escapes centralized auth.
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['use_ldap' => false, 'password' => 'newlocalpass']
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('use_ldap', $error);
    }

    public function testSelfEditActiveChangeIsRejected(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['active' => false]
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('active', $error);
    }

    public function testSelfEditContactFieldsPass(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['fullname' => 'John Doe', 'email' => 'jdoe@example.com', 'description' => 'x', 'password' => 'y']
        );

        $this->assertNull($error);
    }

    public function testUnchangedValuesPassForRoundTrippingClients(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['username' => 'jdoe', 'use_ldap' => true, 'active' => true, 'email' => 'new@example.com']
        );

        $this->assertNull($error);
    }

    public function testBooleanishRepresentationsAreComparedByValue(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['use_ldap' => '1', 'active' => true]
        );

        $this->assertNull($error);
    }

    public function testStringTrueThatWouldPersistAsZeroIsRejected(): void
    {
        // (int)"true" persists as 0 - a disable attempt in disguise.
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['use_ldap' => 'true']
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('use_ldap', $error);
    }

    public function testNullValuesAreRejectedAsAttemptedChanges(): void
    {
        // null persists as 0, so it must count as a change attempt.
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['use_ldap' => null]
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('use_ldap', $error);
    }

    public function testAllOffendingFieldsAreNamed(): void
    {
        $error = SelfEditFieldGuard::apply(
            $this->permissionService(),
            7,
            7,
            self::STORED_USER,
            ['username' => 'other', 'use_ldap' => false, 'active' => false]
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('username', $error);
        $this->assertStringContainsString('use_ldap', $error);
        $this->assertStringContainsString('active', $error);
    }
}
