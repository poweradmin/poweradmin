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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * Sorting the paginated zone list by owner or record count on PostgreSQL must select
 * the name column it orders the DISTINCT id subquery by (issue #1618).
 */
class SqlDomainRepositoryComplexSortTest extends TestCase
{
    public static function complexSorts(): array
    {
        return [
            'owner, mysql' => ['owner', 'mysql'],
            'record count, mysql' => ['count_records', 'mysql'],
            'owner, pgsql' => ['owner', 'pgsql'],
            'record count, pgsql' => ['count_records', 'pgsql'],
        ];
    }

    #[DataProvider('complexSorts')]
    public function testDistinctIdSubquerySelectsTheNameItOrdersBy(string $sortBy, string $dbType): void
    {
        $queries = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($stmt, &$queries) {
            $queries[] = $sql;
            return $stmt;
        });
        $db->method('query')->willReturnCallback(function (string $sql) use ($stmt, &$queries) {
            $queries[] = $sql;
            return $stmt;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => match ("$group.$key") {
            'database.type' => $dbType,
            'dnssec.enabled', 'interface.show_zone_comments' => false,
            default => $default,
        });

        // A letter filter with a short page takes the paginated DISTINCT id subquery path
        (new SqlDomainRepository($db, $config))->getZones('all', 0, 'a', 0, 10, $sortBy, 'ASC', true);

        $this->assertMatchesRegularExpression('/SELECT DISTINCT domains\.id, domains\.name\s+FROM domains/', implode("\n", $queries));
    }
}
