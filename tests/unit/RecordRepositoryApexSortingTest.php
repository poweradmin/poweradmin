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

use Poweradmin\Domain\Model\RecordRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Issue #1250: SOA, NS and apex records stay pinned to the top of a record
 * listing whatever column and direction the user sorts by, in both the plain
 * and the filtered listing.
 */
#[CoversClass(SqlRecordRepository::class)]
class RecordRepositoryApexSortingTest extends SqliteIntegrationTestCase
{
    private const ZONE = 1;

    private SqlRecordRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT,
            content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, auth INTEGER DEFAULT 1)");

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::ZONE . ", 'example.com', 'MASTER')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES
            (1, " . self::ZONE . ", 'www.example.com', 'A', '192.0.2.1', 3600, 0),
            (2, " . self::ZONE . ", 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1 1 1 1 1', 3600, 0),
            (3, " . self::ZONE . ", 'example.com', 'NS', 'ns1.example.com', 3600, 0),
            (4, " . self::ZONE . ", 'example.com', 'MX', 'mail.example.com', 3600, 10),
            (5, " . self::ZONE . ", 'alpha.example.com', 'CNAME', 'www.example.com', 3600, 0)");

        $this->repository = new SqlRecordRepository($this->db, $this->config);
    }

    /**
     * getRecordsFromDomainId returns read models and getFilteredRecords still
     * returns rows, so the label is read the one way both understand.
     *
     * @param list<RecordRow|array<string, mixed>> $records
     * @return list<string>
     */
    private function labels(array $records): array
    {
        return array_map(
            static fn(RecordRow|array $record): string => $record['name'] . '/' . $record['type'],
            $records
        );
    }

    #[Test]
    public function apexRecordsLeadTheListingSortedByName(): void
    {
        $records = $this->repository->getRecordsFromDomainId(self::ZONE, 0, 100, 'name', 'ASC');

        $this->assertSame(
            [
                'example.com/SOA',
                'example.com/NS',
                'example.com/MX',
                'alpha.example.com/CNAME',
                'www.example.com/A',
            ],
            $this->labels($records)
        );
    }

    #[Test]
    public function apexRecordsStayOnTopWhenSortingByAnotherColumn(): void
    {
        $records = $this->repository->getRecordsFromDomainId(self::ZONE, 0, 100, 'type', 'ASC');

        $this->assertSame(
            ['example.com/SOA', 'example.com/NS', 'example.com/MX'],
            array_slice($this->labels($records), 0, 3)
        );
    }

    #[Test]
    public function apexRecordsStayOnTopWhenSortingDescending(): void
    {
        $records = $this->repository->getRecordsFromDomainId(self::ZONE, 0, 100, 'name', 'DESC');

        $this->assertSame(
            [
                'example.com/SOA',
                'example.com/NS',
                'example.com/MX',
                'www.example.com/A',
                'alpha.example.com/CNAME',
            ],
            $this->labels($records)
        );
    }

    #[Test]
    public function apexRecordsStayOnTopOfAFilteredListing(): void
    {
        $records = $this->repository->getFilteredRecords(self::ZONE, 0, 100, 'name', 'ASC', false, 'example');

        $this->assertSame(
            [
                'example.com/SOA',
                'example.com/NS',
                'example.com/MX',
                'alpha.example.com/CNAME',
                'www.example.com/A',
            ],
            $this->labels($records)
        );
    }

    #[Test]
    public function theListingIsScopedToTheZoneAndSkipsTypelessRows(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (2, 'other.example', 'MASTER')");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
            (2, 'other.example', 'SOA', 'ns1 hostmaster 1 1 1 1 1', 3600, 0),
            (" . self::ZONE . ", 'empty.example.com', NULL, '', 3600, 0),
            (" . self::ZONE . ", 'blank.example.com', '', '', 3600, 0)");

        $records = $this->repository->getRecordsFromDomainId(self::ZONE, 0, 100, 'name', 'ASC');

        $this->assertCount(5, $records);
        $this->assertNotContains('other.example/SOA', $this->labels($records));
    }
}
