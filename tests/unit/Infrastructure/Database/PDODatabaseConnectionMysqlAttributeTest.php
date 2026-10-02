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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;

class PDODatabaseConnectionMysqlAttributeTest extends TestCase
{
    #[DataProvider('attributeProvider')]
    public function testResolvesToTheDriverValueOnThisPhp(string $name): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The MySQL PDO driver defines these constants');
        }

        // PHP 8.5 deprecates the old spelling, so it is only read where the new one is missing
        $expected = defined('Pdo\\Mysql::ATTR_' . $name)
            ? constant('Pdo\\Mysql::ATTR_' . $name)
            : constant('PDO::MYSQL_ATTR_' . $name);

        $this->assertSame($expected, PDODatabaseConnection::mysqlAttribute($name));
    }

    public static function attributeProvider(): array
    {
        return [['DIRECT_QUERY'], ['SSL_VERIFY_SERVER_CERT'], ['SSL_CA'], ['SSL_CERT'], ['SSL_KEY']];
    }
}
