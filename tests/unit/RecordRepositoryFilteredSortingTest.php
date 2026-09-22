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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Trac #460: PTR records must sort numerically by leading octet, not
 * lexicographically, including when a search filter is applied
 * (getFilteredRecords path).
 *
 * The ordering itself is exercised against real SQLite; the MySQL and
 * PostgreSQL natural-sort expressions differ per driver and no in-memory
 * database can run them, so those two branches are still pinned by their SQL.
 */
#[CoversClass(SqlRecordRepository::class)]
class RecordRepositoryFilteredSortingTest extends SqliteIntegrationTestCase
{
    private const ZONE = 1;

    private SqlRecordRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT,
            content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, auth INTEGER DEFAULT 1)");

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::ZONE . ", '0.192.in-addr.arpa', 'MASTER')");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
            (" . self::ZONE . ", '10.0.192.in-addr.arpa', 'PTR', 'ten.example.com', 3600, 0),
            (" . self::ZONE . ", '2.0.192.in-addr.arpa', 'PTR', 'two.example.com', 3600, 0),
            (" . self::ZONE . ", '1.0.192.in-addr.arpa', 'PTR', 'one.example.com', 3600, 0)");

        $this->repository = new SqlRecordRepository($this->db, $this->config);
    }

    /**
     * @param list<RecordRow> $records
     * @return list<string>
     */
    private function names(array $records): array
    {
        return array_map(static fn(RecordRow $record): string => $record->name, $records);
    }

    #[Test]
    public function aFilteredListingSortsPtrNamesByLeadingOctet(): void
    {
        $records = $this->repository->getFilteredRecords(self::ZONE, 0, 100, 'name', 'ASC', false, 'in-addr');

        $this->assertSame(
            ['1.0.192.in-addr.arpa', '2.0.192.in-addr.arpa', '10.0.192.in-addr.arpa'],
            $this->names($records)
        );
    }

    #[Test]
    public function theNumericOrderIsReversedForDescendingSorts(): void
    {
        $records = $this->repository->getFilteredRecords(self::ZONE, 0, 100, 'name', 'DESC', false, 'in-addr');

        $this->assertSame(
            ['10.0.192.in-addr.arpa', '2.0.192.in-addr.arpa', '1.0.192.in-addr.arpa'],
            $this->names($records)
        );
    }

    #[Test]
    public function otherColumnsKeepTheirPlainOrder(): void
    {
        $records = $this->repository->getFilteredRecords(self::ZONE, 0, 100, 'content', 'ASC', false, 'in-addr');

        $this->assertSame(
            ['1.0.192.in-addr.arpa', '10.0.192.in-addr.arpa', '2.0.192.in-addr.arpa'],
            $this->names($records)
        );
    }

    #[Test]
    #[DataProvider('driverSortExpressions')]
    public function eachDriverGetsItsOwnNaturalSortExpression(string $driver, string $expression): void
    {
        $sql = $this->captureFilteredRecordsSql($driver);

        $this->assertStringContainsString($expression, $sql);
    }

    /** @return array<string, array{string, string}> */
    public static function driverSortExpressions(): array
    {
        return [
            'mysql' => ['mysql', 'records.name+0'],
            'pgsql' => ['pgsql', "SUBSTRING(records.name FROM '^[0-9]+')"],
        ];
    }

    /**
     * The driver decides the natural-sort expression, so the two branches SQLite
     * cannot run are pinned by the SQL the repository prepares.
     */
    private function captureFilteredRecordsSql(string $driver): string
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('bindValue')->willReturn(true);
        $statement->method('fetch')->willReturn(false);

        $captured = '';
        $db = $this->createMock(PDO::class);
        $db->method('getAttribute')->willReturnCallback(
            fn(int $attribute): ?string => $attribute === PDO::ATTR_DRIVER_NAME ? $driver : null
        );
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($statement, &$captured): PDOStatement {
            $captured = $sql;
            return $statement;
        });

        (new SqlRecordRepository($db, $this->sqliteConfiguration()))
            ->getFilteredRecords(self::ZONE, 0, 100, 'name', 'ASC', false, 'search-term');

        return $captured;
    }
}
