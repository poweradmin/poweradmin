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

/**
 * The 4.6.0 PostgreSQL refill of the Zone Manager and Editor grants must leave a
 * customised or in-use template alone.
 */
class PgsqlTemplateRefillGuardTest extends TestCase
{
    private function refillBlock(): string
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 4) . '/sql/poweradmin-pgsql-update-to-4.6.0.sql');
        $start = strpos($sql, 'INSERT INTO perm_templ_items (templ_id, perm_id)');
        $this->assertNotFalse($start);
        $end = strpos($sql, ';', (int)strpos($sql, 'NOT EXISTS (SELECT 1 FROM perm_templ_items', $start));

        return substr($sql, $start, (int)$end - $start);
    }

    public function testRefillOnlyTouchesTemplatesWithTheStockDescription(): void
    {
        $block = $this->refillBlock();

        $this->assertStringContainsString("pt.descr = CASE pt.name", $block);
        $this->assertStringContainsString("'Full management of own zones including creation, editing, deletion, and templates.'", $block);
        $this->assertStringContainsString("'Edit own zone records but cannot modify SOA and NS records.'", $block);
    }

    public function testRefillSkipsTemplatesAssignedToUsersOrGroups(): void
    {
        $block = $this->refillBlock();

        $this->assertStringContainsString('FROM users u WHERE u.perm_templ = pt.id', $block);
        $this->assertStringContainsString('FROM user_groups ug WHERE ug.perm_templ = pt.id', $block);
    }
}
