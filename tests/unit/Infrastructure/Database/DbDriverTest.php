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


namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DbDriver;

class DbDriverTest extends TestCase
{
    /**
     * mysql and mysqli name the same backend. Dispatch sites that check only one
     * spelling silently take the wrong branch, which is what this guards.
     */
    public function testBothMysqlSpellingsBehaveAlike(): void
    {
        $this->assertTrue(DbDriver::MYSQL->isMysqlFamily());
        $this->assertTrue(DbDriver::MYSQLI->isMysqlFamily());
        $this->assertFalse(DbDriver::PGSQL->isMysqlFamily());
        $this->assertFalse(DbDriver::SQLITE->isMysqlFamily());
    }

    public function testOnlyMysqlSupportsSeparatePdnsDb(): void
    {
        $this->assertTrue(DbDriver::MYSQL->supportsSeparatePdnsDb());
        $this->assertTrue(DbDriver::MYSQLI->supportsSeparatePdnsDb());
        $this->assertFalse(DbDriver::PGSQL->supportsSeparatePdnsDb());
        $this->assertFalse(DbDriver::SQLITE->supportsSeparatePdnsDb());
    }

    public function testValidation(): void
    {
        $this->assertSame(['mysql', 'mysqli', 'pgsql', 'sqlite'], DbDriver::values());
        $this->assertTrue(DbDriver::isValid('pgsql'));
        $this->assertFalse(DbDriver::isValid('mariadb'));
        $this->assertNull(DbDriver::tryFrom('oracle'));
    }
}
