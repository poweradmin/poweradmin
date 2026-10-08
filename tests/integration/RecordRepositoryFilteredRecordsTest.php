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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\RecordCommentFixture;

/**
 * SqlRecordRepository::getFilteredRecords() on each engine: LIMIT/OFFSET with bound
 * parameters, ORDER BY next to the comment join without ambiguous columns, the
 * apex pinning, the filters and pagination. MySQL and PostgreSQL run when the
 * devcontainer is up and are skipped visibly when it is not.
 */
class RecordRepositoryFilteredRecordsTest extends TestCase
{
    private const ZONE_ID = 1;

    private ?PDO $db = null;
    private string $engine = '';

    protected function tearDown(): void
    {
        $this->close();
    }

    private function close(): void
    {
        if ($this->db !== null) {
            RecordCommentFixture::drop($this->db, $this->engine);
            $this->db = null;
        }
    }

    private function open(string $engine): SqlRecordRepository
    {
        $this->close();
        try {
            $db = RecordCommentFixture::connect($engine);
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }
        $this->db = $db;
        $this->engine = $engine;
        RecordCommentFixture::createSchema($db, $engine);
        $this->seed($db);

        return new SqlRecordRepository($db, RecordCommentFixture::config($engine));
    }

    private function seed(PDO $db): void
    {
        $records = [
            ['example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 3600 900 604800 86400', 86400, 0],
            ['example.com', 'NS', 'ns1.example.com', 86400, 0],
            ['example.com', 'NS', 'ns2.example.com', 86400, 0],
            ['example.com', 'A', '192.0.2.1', 3600, 0],
            ['www.example.com', 'A', '192.0.2.2', 3600, 0],
            ['mail.example.com', 'A', '192.0.2.3', 3600, 0],
            ['example.com', 'MX', 'mail.example.com', 3600, 10],
            ['example.com', 'TXT', '"v=spf1 mx -all"', 3600, 0],
            ['ftp.example.com', 'CNAME', 'www.example.com', 3600, 0],
            ['api.example.com', 'A', '192.0.2.4', 3600, 0],
        ];
        foreach ($records as $index => [$name, $type, $content, $ttl, $prio]) {
            RecordCommentFixture::addRecord($db, $index + 1, $name, $type, $content, $ttl, $prio);
        }

        RecordCommentFixture::addComment($db, 1, 'example.com', 'A', 'Main website IP');
        RecordCommentFixture::addComment($db, 2, 'www.example.com', 'A', 'WWW subdomain');
        RecordCommentFixture::addComment($db, 3, 'mail.example.com', 'A', 'Mail server');
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return RecordCommentFixture::engines();
    }

    /** @return array<string, array{string}> */
    public static function otherEngines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /** @return list<RecordRow> */
    private function filtered(
        SqlRecordRepository $repo,
        int $start = 0,
        int $amount = 100,
        string $sortBy = 'name',
        string $direction = 'ASC',
        bool $comments = false,
        string $search = '',
        string $type = '',
        string $content = ''
    ): array {
        return $repo->getFilteredRecords(self::ZONE_ID, $start, $amount, $sortBy, $direction, $comments, $search, $type, $content);
    }

    /**
     * @param list<RecordRow> $rows
     * @return list<string>
     */
    private static function summary(array $rows): array
    {
        $lines = array_map(fn(RecordRow $r): string => "$r->name $r->type $r->content " . ($r->comment ?? '-'), $rows);
        sort($lines);

        return $lines;
    }

    #[DataProvider('engines')]
    public function testBasicQueryWithoutComments(string $engine): void
    {
        $rows = $this->filtered($this->open($engine));

        $this->assertCount(10, $rows);
        $this->assertNull($rows[0]->comment);
    }

    #[DataProvider('engines')]
    public function testApexRecordsComeFirstAndTheRestIsSortedByName(string $engine): void
    {
        $rows = $this->filtered($this->open($engine));

        $this->assertSame('SOA', $rows[0]->type);
        $this->assertSame(['example.com'], array_values(array_unique(array_map(fn(RecordRow $r) => $r->name, array_slice($rows, 0, 6)))));
        $this->assertSame(
            ['api.example.com', 'ftp.example.com', 'mail.example.com', 'www.example.com'],
            array_map(fn(RecordRow $r) => $r->name, array_slice($rows, 6))
        );
    }

    #[DataProvider('engines')]
    public function testCommentsJoinAttachesTheCommentsAndKeepsEveryRecord(string $engine): void
    {
        $rows = $this->filtered($this->open($engine), comments: true);

        $this->assertCount(10, $rows);
        $comments = [];
        foreach ($rows as $row) {
            $comments[$row->name . '/' . $row->type] = $row->comment;
        }
        $this->assertSame('Main website IP', $comments['example.com/A']);
        $this->assertSame('WWW subdomain', $comments['www.example.com/A']);
        $this->assertSame('Mail server', $comments['mail.example.com/A']);
        $this->assertNull($comments['example.com/MX']);
    }

    #[DataProvider('engines')]
    public function testPaginationWithBoundParameters(string $engine): void
    {
        $repo = $this->open($engine);

        $page1 = $this->filtered($repo, 0, 3);
        $page2 = $this->filtered($repo, 3, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertSame([], array_intersect(array_map(fn(RecordRow $r) => $r->id, $page1), array_map(fn(RecordRow $r) => $r->id, $page2)));
    }

    #[DataProvider('engines')]
    public function testOrderByWithTheCommentJoinDoesNotCauseAnAmbiguousColumn(string $engine): void
    {
        $repo = $this->open($engine);

        foreach (['id', 'name', 'type', 'content', 'ttl'] as $column) {
            $this->assertCount(5, $this->filtered($repo, 0, 5, $column, 'ASC', true), "ORDER BY $column");
        }
    }

    #[DataProvider('engines')]
    public function testZeroOffsetReturnsTheFirstPage(string $engine): void
    {
        $this->assertCount(5, $this->filtered($this->open($engine), 0, 5, comments: true));
    }

    #[DataProvider('engines')]
    public function testAnOffsetPastTheEndReturnsNothing(string $engine): void
    {
        $this->assertSame([], $this->filtered($this->open($engine), 100, 10, comments: true));
    }

    #[DataProvider('engines')]
    public function testSearchTypeAndContentFilters(string $engine): void
    {
        $repo = $this->open($engine);

        $this->assertCount(2, $this->filtered($repo, search: 'mail'));
        $this->assertCount(4, $this->filtered($repo, type: 'A'));
        $this->assertSame(['ftp.example.com'], array_map(fn(RecordRow $r) => $r->name, $this->filtered($repo, content: 'www.example')));
    }

    #[DataProvider('otherEngines')]
    public function testEveryEngineReturnsTheSameRecordsAsSQLite(string $engine): void
    {
        $expected = self::summary($this->filtered($this->open('sqlite'), comments: true));
        $this->assertCount(10, $expected);

        $actual = self::summary($this->filtered($this->open($engine), comments: true));

        $this->assertSame($expected, $actual, "$engine should return the same records and comments as SQLite");
    }
}
