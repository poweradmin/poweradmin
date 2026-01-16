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
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Tests for superuser permission verification
 *
 * Covers fix(permissions): prevent non-superusers from modifying superuser accounts
 *
 * Note: These tests use mock PDO connections to verify the SQL query behavior
 * without requiring a real database connection.
 */
class SuperuserPermissionTest extends TestCase
{
    /**
     * Test isUserSuperuser returns true when user has ueberuser permission
     */
    public function testIsUserSuperuserReturnsTrueForSuperuser(): void
    {
        $mockPdo = $this->createMock(PDOCommon::class);
        $mockStatement = $this->createMock(\PDOStatement::class);

        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('user_is_ueberuser'))
            ->willReturn($mockStatement);

        $mockStatement->expects($this->once())
            ->method('execute')
            ->with([':userId' => 1]);

        $mockStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 1]);

        $result = UserManager::isUserSuperuser($mockPdo, 1);

        $this->assertTrue($result);
    }

    /**
     * Test isUserSuperuser returns false when user does not have ueberuser permission
     */
    public function testIsUserSuperuserReturnsFalseForNonSuperuser(): void
    {
        $mockPdo = $this->createMock(PDOCommon::class);
        $mockStatement = $this->createMock(\PDOStatement::class);

        $mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStatement);

        $mockStatement->expects($this->once())
            ->method('execute')
            ->with([':userId' => 2]);

        $mockStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = UserManager::isUserSuperuser($mockPdo, 2);

        $this->assertFalse($result);
    }

    /**
     * Test isUserSuperuser returns false when query returns null/empty
     */
    public function testIsUserSuperuserReturnsFalseForEmptyResult(): void
    {
        $mockPdo = $this->createMock(PDOCommon::class);
        $mockStatement = $this->createMock(\PDOStatement::class);

        $mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStatement);

        $mockStatement->expects($this->once())
            ->method('execute')
            ->with([':userId' => 999]);

        $mockStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = UserManager::isUserSuperuser($mockPdo, 999);

        $this->assertFalse($result);
    }

    /**
     * Test that the SQL query joins the correct tables
     */
    public function testIsUserSuperuserQueryJoinsCorrectTables(): void
    {
        $mockPdo = $this->createMock(PDOCommon::class);
        $mockStatement = $this->createMock(\PDOStatement::class);

        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function ($query) {
                // Verify the query includes all necessary JOINs
                return str_contains($query, 'perm_templ_items')
                    && str_contains($query, 'perm_items')
                    && str_contains($query, 'perm_templ')
                    && str_contains($query, 'users')
                    && str_contains($query, 'user_is_ueberuser');
            }))
            ->willReturn($mockStatement);

        $mockStatement->method('execute');
        $mockStatement->method('fetch')->willReturn(['count' => 0]);

        UserManager::isUserSuperuser($mockPdo, 1);
    }
}
