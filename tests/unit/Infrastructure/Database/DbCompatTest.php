<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Infrastructure\Database\DbCompat;

class DbCompatTest extends TestCase
{
    /**
     * Test boolFromDb with PostgreSQL 't'/'f' strings
     */
    public function testBoolFromDbPostgreSQLTrue(): void
    {
        $this->assertSame(1, DbCompat::boolFromDb('t'));
        $this->assertSame(1, DbCompat::boolFromDb('true'));
    }

    public function testBoolFromDbPostgreSQLFalse(): void
    {
        $this->assertSame(0, DbCompat::boolFromDb('f'));
        $this->assertSame(0, DbCompat::boolFromDb('false'));
    }

    /**
     * Test boolFromDb with MySQL/SQLite integers
     */
    public function testBoolFromDbMySQLTrue(): void
    {
        $this->assertSame(1, DbCompat::boolFromDb(1));
    }

    public function testBoolFromDbMySQLFalse(): void
    {
        $this->assertSame(0, DbCompat::boolFromDb(0));
    }

    /**
     * Test boolFromDb with string integers (MySQL TINYINT(1) returns these)
     */
    public function testBoolFromDbStringIntegerTrue(): void
    {
        $this->assertSame(1, DbCompat::boolFromDb('1'));
    }

    public function testBoolFromDbStringIntegerFalse(): void
    {
        // CRITICAL: PDO often returns MySQL TINYINT(1) as string '0'
        // This must NOT be treated as truthy
        $this->assertSame(0, DbCompat::boolFromDb('0'));
    }

    public function testBoolFromDbStringNumericPositive(): void
    {
        // String numeric values > 1 should be treated as true
        $this->assertSame(1, DbCompat::boolFromDb('2'));
        $this->assertSame(1, DbCompat::boolFromDb('10'));
    }

    public function testBoolFromDbStringNumericNegative(): void
    {
        // Negative string numerics should be treated as true (non-zero)
        $this->assertSame(1, DbCompat::boolFromDb('-1'));
    }

    /**
     * Test boolFromDb with PHP booleans
     */
    public function testBoolFromDbBooleanTrue(): void
    {
        $this->assertSame(1, DbCompat::boolFromDb(true));
    }

    public function testBoolFromDbBooleanFalse(): void
    {
        $this->assertSame(0, DbCompat::boolFromDb(false));
    }

    /**
     * Test boolFromDb with null
     */
    public function testBoolFromDbNull(): void
    {
        $this->assertSame(0, DbCompat::boolFromDb(null));
    }

    /**
     * Test boolFromDb with edge cases
     */
    public function testBoolFromDbEmptyString(): void
    {
        $this->assertSame(0, DbCompat::boolFromDb(''));
    }

    public function testBoolFromDbNegativeInteger(): void
    {
        // Any non-zero integer should be treated as true
        $this->assertSame(1, DbCompat::boolFromDb(-1));
    }

    public function testBoolFromDbPositiveInteger(): void
    {
        // Any non-zero integer should be treated as true
        $this->assertSame(1, DbCompat::boolFromDb(2));
    }

    /**
     * Test boolValue method
     */
    public function testBoolValueTrue(): void
    {
        $this->assertSame(1, DbCompat::boolValue(true));
    }

    public function testBoolValueFalse(): void
    {
        $this->assertSame(0, DbCompat::boolValue(false));
    }

    /**
     * Test substr method
     */
    public function testSubstrSQLite(): void
    {
        $this->assertSame('SUBSTR', DbCompat::substr('sqlite'));
    }

    public function testSubstrMySQL(): void
    {
        $this->assertSame('SUBSTRING', DbCompat::substr('mysql'));
    }

    public function testSubstrPostgreSQL(): void
    {
        $this->assertSame('SUBSTRING', DbCompat::substr('pgsql'));
    }

    /**
     * Test regexp method
     */
    public function testRegexpMySQL(): void
    {
        $this->assertSame('REGEXP', DbCompat::regexp('mysql'));
    }

    public function testRegexpSQLite(): void
    {
        $this->assertSame('GLOB', DbCompat::regexp('sqlite'));
    }

    public function testRegexpPostgreSQL(): void
    {
        $this->assertSame('~', DbCompat::regexp('pgsql'));
    }

    /**
     * Test boolTrue method
     */
    public function testBoolTrueSQLite(): void
    {
        $this->assertSame('1', DbCompat::boolTrue('sqlite'));
    }

    public function testBoolTrueMySQL(): void
    {
        $this->assertSame('TRUE', DbCompat::boolTrue('mysql'));
    }

    /**
     * Test boolFalse method
     */
    public function testBoolFalseSQLite(): void
    {
        $this->assertSame('0', DbCompat::boolFalse('sqlite'));
    }

    public function testBoolFalseMySQL(): void
    {
        $this->assertSame('FALSE', DbCompat::boolFalse('mysql'));
    }

    /**
     * Test concat method
     */
    public function testConcatSQLite(): void
    {
        $result = DbCompat::concat('sqlite', ["'hello'", "' '", "'world'"]);
        $this->assertSame("'hello' || ' ' || 'world'", $result);
    }

    public function testConcatMySQL(): void
    {
        $result = DbCompat::concat('mysql', ["'hello'", "' '", "'world'"]);
        $this->assertSame("CONCAT('hello', ' ', 'world')", $result);
    }

    public function testConcatPostgreSQL(): void
    {
        $result = DbCompat::concat('pgsql', ["'hello'", "' '", "'world'"]);
        $this->assertSame("CONCAT('hello', ' ', 'world')", $result);
    }
}
