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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserFormMessages;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;

#[CoversClass(UserFormMessages::class)]
class UserFormMessagesTest extends TestCase
{
    public function testUniquenessRefusalsGetTheFormWording(): void
    {
        $this->assertSame('Username exist already, please choose another one.', UserFormMessages::errorMessage(['message' => 'Username already exists', 'code' => UserManagementService::ERR_USERNAME_EXISTS]));
        $this->assertSame('Email address already exists, please choose another one.', UserFormMessages::errorMessage(['message' => 'Email already exists', 'code' => UserManagementService::ERR_EMAIL_EXISTS]));
    }

    public function testAWriteFailureHidesTheDriverText(): void
    {
        $result = ['message' => 'Failed to create user: SQLSTATE[23000] users_email_key', 'code' => UserManagementService::ERR_WRITE];

        $this->assertSame('The user could not be saved.', UserFormMessages::errorMessage($result));
    }

    public function testTemplateRefusalsAreWordedForThePage(): void
    {
        $this->assertStringContainsString('administrator rights', UserFormMessages::templateAssignmentError(PermissionService::TEMPLATE_SUPERUSER_DENIED));
        $this->assertStringContainsString('your own permission template', UserFormMessages::templateAssignmentError(PermissionService::TEMPLATE_SELF_ASSIGN_DENIED));
        $this->assertStringContainsString('do not have the permission', UserFormMessages::templateAssignmentError(PermissionService::TEMPLATE_ASSIGN_DENIED));
    }

    public function testPolicyMessagesPassThrough(): void
    {
        $result = ['message' => 'Password must be at least 8 characters long', 'code' => UserManagementService::ERR_PASSWORD_POLICY];

        $this->assertSame('Password must be at least 8 characters long', UserFormMessages::errorMessage($result));
    }
}
