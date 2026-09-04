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

namespace Poweradmin\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;

/**
 * Trac #460: PTR records must sort numerically by leading octet, not lexicographically,
 * including when search/type/content filters are applied (getFilteredRecords path).
 */
class RecordRepositoryFilteredSortingTest extends TestCase
{
    private function makeRepository(string $driver): array
    {
        $db = $this->createMock(PDO::class);
        $db->method('getAttribute')->willReturnCallback(
            fn($attr) => $attr === PDO::ATTR_DRIVER_NAME ? $driver : null
        );

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($section, $key, $default = null) => $default
        );

        return [$db, new SqlRecordRepository($db, $config)];
    }

    private function captureQuery(MockObject $db): \Closure
    {
        $captured = new \stdClass();
        $captured->sql = null;

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $db->method('prepare')->willReturnCallback(function ($sql) use ($stmt, $captured) {
            $captured->sql = $sql;
            return $stmt;
        });

        return fn() => $captured->sql;
    }

    public function testFilteredRecordsUseNaturalSortOnMySql(): void
    {
        [$db, $repo] = $this->makeRepository('mysql');
        $getSql = $this->captureQuery($db);

        $repo->getFilteredRecords(1, 0, 100, 'name', 'ASC', false, 'search-term');

        $sql = $getSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('records.name+0', $sql, 'MySQL natural sort expression missing');
    }

    public function testFilteredRecordsUseNaturalSortOnSqlite(): void
    {
        [$db, $repo] = $this->makeRepository('sqlite');
        $getSql = $this->captureQuery($db);

        $repo->getFilteredRecords(1, 0, 100, 'name', 'ASC', false, 'search-term');

        $sql = $getSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('records.name+0', $sql, 'SQLite natural sort expression missing');
    }

    public function testFilteredRecordsUseNaturalSortOnPgSql(): void
    {
        [$db, $repo] = $this->makeRepository('pgsql');
        $getSql = $this->captureQuery($db);

        $repo->getFilteredRecords(1, 0, 100, 'name', 'ASC', false, 'search-term');

        $sql = $getSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString("SUBSTRING(records.name FROM '^[0-9]+')", $sql, 'PgSQL natural sort expression missing');
    }

    public function testFilteredRecordsNaturalSortRespectsDescDirection(): void
    {
        [$db, $repo] = $this->makeRepository('mysql');
        $getSql = $this->captureQuery($db);

        $repo->getFilteredRecords(1, 0, 100, 'name', 'DESC', false, 'search-term');

        $sql = $getSql();
        $this->assertNotNull($sql);
        $this->assertStringContainsString('records.name+0', $sql);
        $this->assertStringContainsString('DESC', $sql);
    }

    public function testFilteredRecordsKeepPlainOrderForNonNameColumns(): void
    {
        [$db, $repo] = $this->makeRepository('mysql');
        $getSql = $this->captureQuery($db);

        $repo->getFilteredRecords(1, 0, 100, 'type', 'ASC', false, 'search-term');

        $sql = $getSql();
        $this->assertNotNull($sql);
        $this->assertStringNotContainsString('records.name+0', $sql, 'natural sort must only apply to name column');
        $this->assertStringContainsString('records.type ASC', $sql);
    }
}
