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

class UserManagerEmailExistsTest extends TestCase
{
    public function testReturnsTrueWhenEmailIsTaken(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value) => "'" . $value . "'");
        // Case-insensitive comparison so different casing still counts as a duplicate.
        $db->expects($this->once())
            ->method('queryOne')
            ->with($this->stringContains('LOWER(email) = LOWER('))
            ->willReturn(5);

        $this->assertTrue(UserManager::email_exists($db, 'taken@example.com'));
    }

    public function testReturnsFalseWhenEmailIsFree(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value) => "'" . $value . "'");
        $db->method('queryOne')->willReturn(false);

        $this->assertFalse(UserManager::email_exists($db, 'free@example.com'));
    }

    public function testExcludesTheEditedUser(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value) => (string)$value);
        // The edit case must scope the lookup so the user's own row is ignored.
        $db->expects($this->once())
            ->method('queryOne')
            ->with($this->stringContains('id != '))
            ->willReturn(false);

        $this->assertFalse(UserManager::email_exists($db, 'self@example.com', 42));
    }
}
