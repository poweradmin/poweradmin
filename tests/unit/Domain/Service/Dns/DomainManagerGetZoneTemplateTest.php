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

namespace Unit\Domain\Service\Dns;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DomainManager;

/**
 * Test for DomainManager::getZoneTemplate() method
 *
 * Issue #935: PHP 8.4 TypeError when zone_templ_id is NULL or row not found
 * @see https://github.com/poweradmin/poweradmin/issues/935
 */
class DomainManagerGetZoneTemplateTest extends TestCase
{
    /**
     * Test that getZoneTemplate returns the template ID when it exists
     */
    public function testGetZoneTemplateReturnsTemplateId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(5);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = DomainManager::getZoneTemplate($db, 1);

        $this->assertSame(5, $result);
    }

    /**
     * Test that getZoneTemplate returns 0 when zone has no template (zone_templ_id = 0)
     */
    public function testGetZoneTemplateReturnsZeroForNoTemplate(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = DomainManager::getZoneTemplate($db, 1);

        $this->assertSame(0, $result);
    }

    /**
     * Test that getZoneTemplate handles NULL value from database (PostgreSQL)
     *
     * Issue #935: This test exposes the bug where fetchColumn() returns null
     * but the method signature requires int return type, causing TypeError in PHP 8.4
     */
    public function testGetZoneTemplateHandlesNullValue(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(null);  // NULL in database

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        // This should return 0 instead of throwing TypeError
        $result = DomainManager::getZoneTemplate($db, 1);

        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    /**
     * Test that getZoneTemplate handles no row found (fetchColumn returns false)
     *
     * Issue #935: This test exposes the bug where fetchColumn() returns false
     * when no row is found, but the method signature requires int return type
     */
    public function testGetZoneTemplateHandlesNoRowFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);  // No row found

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        // This should return 0 instead of throwing TypeError
        $result = DomainManager::getZoneTemplate($db, 99999);

        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    /**
     * Test that getZoneTemplate returns string "0" cast to int
     * Some database drivers may return string values
     */
    public function testGetZoneTemplateHandlesStringZero(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn("0");  // String "0"

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = DomainManager::getZoneTemplate($db, 1);

        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    /**
     * Test that getZoneTemplate returns string template ID cast to int
     * Some database drivers may return string values
     */
    public function testGetZoneTemplateHandlesStringTemplateId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn("42");  // String "42"

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = DomainManager::getZoneTemplate($db, 1);

        $this->assertIsInt($result);
        $this->assertSame(42, $result);
    }
}
