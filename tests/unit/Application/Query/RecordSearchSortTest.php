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

namespace unit\Application\Query;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Verifies the ORDER BY clause RecordSearch builds for record searches.
 *
 * In grouped mode ttl/prio are selected as MIN() aggregates, so the sort must
 * reference the result column name; ordering by records.ttl is rejected by
 * PostgreSQL and by MySQL under ONLY_FULL_GROUP_BY.
 */
class RecordSearchSortTest extends TestCase
{
    private function captureQuery(bool $groupRecords, string $sortBy): string
    {
        $capturedQuery = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $query) use (&$capturedQuery, $stmt) {
            $capturedQuery = $query;
            return $stmt;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function (string $group, string $key) {
            return $group === 'database' && $key === 'type' ? 'mysql' : '';
        });

        $search = new RecordSearch($db, $config, 'mysql');
        $search->fetchRecords([], '%test%', false, '', 'all', $groupRecords, $sortBy, 'DESC', 10, false, 1);

        return $capturedQuery;
    }

    public function testGroupedSortByTtlUsesResultColumnAlias(): void
    {
        $query = $this->captureQuery(true, 'ttl');

        $this->assertStringContainsString('ORDER BY ttl DESC', $query);
        $this->assertStringNotContainsString('ORDER BY records.ttl', $query);
    }

    public function testGroupedSortByPrioUsesResultColumnAlias(): void
    {
        $query = $this->captureQuery(true, 'prio');

        $this->assertStringContainsString('ORDER BY prio DESC', $query);
        $this->assertStringNotContainsString('ORDER BY records.prio', $query);
    }

    public function testUngroupedSortQualifiesColumnToAvoidAmbiguity(): void
    {
        $query = $this->captureQuery(false, 'ttl');

        $this->assertStringContainsString('ORDER BY records.ttl DESC', $query);
    }

    public function testGroupedSortByNameStillUsesNaturalSort(): void
    {
        $query = $this->captureQuery(true, 'name');

        // The name column keeps its natural-sort expression from SortHelper
        // regardless of grouping; records.name is a grouped column so it stays valid.
        $this->assertStringContainsString('records.name+0', $query);
    }
}
