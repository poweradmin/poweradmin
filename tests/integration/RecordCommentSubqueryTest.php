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
use Poweradmin\Infrastructure\Repository\RecordSearch;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\RecordCommentFixture;

/**
 * Comment resolution in the real record repository and record search, on each engine.
 *
 * A record shows its per-record linked comment first (record_comment_links) and falls
 * back to the RRset comment matched by domain, name and type. The same fixture runs
 * through getRecordsFromDomainId(), getFilteredRecords() and RecordSearch, and the
 * MySQL and PostgreSQL results must equal the SQLite ones.
 */
class RecordCommentSubqueryTest extends TestCase
{
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

    private function open(string $engine): PDO
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

        return $db;
    }

    private function seed(PDO $db): void
    {
        RecordCommentFixture::addRecord($db, 1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 3600 900 604800 86400');
        RecordCommentFixture::addRecord($db, 2, 'example.com', 'A', '192.0.2.1');
        RecordCommentFixture::addRecord($db, 3, 'www.example.com', 'A', '192.0.2.2');
        RecordCommentFixture::addRecord($db, 4, 'mail.example.com', 'A', '192.0.2.3');
        RecordCommentFixture::addRecord($db, 5, 'example.com', 'MX', 'mail.example.com');
        // Same RRset as record 4 (its only comment is linked to 4), a different name with the
        // same type, and the same name with a different type: none of them may borrow a comment
        RecordCommentFixture::addRecord($db, 6, 'mail.example.com', 'A', '192.0.2.6');
        RecordCommentFixture::addRecord($db, 7, 'ftp.example.com', 'A', '192.0.2.7');
        RecordCommentFixture::addRecord($db, 8, 'www.example.com', 'TXT', 'v=none');

        // RRset comment with no link: matched by domain, name and type
        RecordCommentFixture::addComment($db, 1, 'www.example.com', 'A', 'Legacy RRset comment');

        // Per-record linked comment for record 4
        RecordCommentFixture::addComment($db, 2, 'mail.example.com', 'A', 'Linked per-record comment');
        RecordCommentFixture::link($db, 4, 2);

        // Record 2 has an unlinked RRset comment and a linked one: the linked one wins
        RecordCommentFixture::addComment($db, 3, 'example.com', 'A', 'Unlinked RRset fallback');
        RecordCommentFixture::addComment($db, 4, 'example.com', 'A', 'Preferred linked comment');
        RecordCommentFixture::link($db, 2, 4);
    }

    private function repository(PDO $db): SqlRecordRepository
    {
        return new SqlRecordRepository($db, RecordCommentFixture::config($this->engine));
    }

    /** @return array<string, ?string> */
    private function zoneListingComments(PDO $db): array
    {
        return $this->commentMap($this->repository($db)->getRecordsFromDomainId(1, 0, 100, 'name', 'ASC', true));
    }

    /** @return array<string, ?string> */
    private function filteredComments(PDO $db): array
    {
        return $this->commentMap($this->repository($db)->getFilteredRecords(1, 0, 100, 'name', 'ASC', true));
    }

    /** @return array<string, ?string> */
    private function searchComments(PDO $db, string $query, bool $inComments = false): array
    {
        $search = new RecordSearch($db, RecordCommentFixture::config($this->engine), $this->engine);
        $parameters = [
            'query' => $query,
            'zones' => false,
            'records' => true,
            'comments' => $inComments,
            'wildcard' => true,
            'reverse' => false,
            'type_filter' => '',
            'content_filter' => '',
        ];

        return $this->commentMap($search->searchRecords($parameters, 'all', null, 'name', 'ASC', false, 100, true, 1));
    }

    /**
     * @param array<array-key, RecordRow|array<string, mixed>> $rows
     * @return array<string, ?string>
     */
    private function commentMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['name'] . '/' . $row['type'] . '/' . $row['content']] = $row['comment'];
        }
        ksort($map);

        return $map;
    }

    /** @return array<string, ?string> */
    private static function expectedComments(): array
    {
        return [
            'example.com/A/192.0.2.1' => 'Preferred linked comment',
            'example.com/MX/mail.example.com' => null,
            'example.com/SOA/ns1.example.com hostmaster.example.com 2024010101 3600 900 604800 86400' => null,
            'ftp.example.com/A/192.0.2.7' => null,
            'mail.example.com/A/192.0.2.3' => 'Linked per-record comment',
            'mail.example.com/A/192.0.2.6' => null,
            'www.example.com/A/192.0.2.2' => 'Legacy RRset comment',
            'www.example.com/TXT/v=none' => null,
        ];
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

    #[DataProvider('engines')]
    public function testZoneListingAttachesTheRightCommentToEachRecord(string $engine): void
    {
        $db = $this->open($engine);

        $this->assertSame(self::expectedComments(), $this->zoneListingComments($db));
    }

    #[DataProvider('engines')]
    public function testFilteredRecordsAttachTheRightCommentToEachRecord(string $engine): void
    {
        $db = $this->open($engine);

        $this->assertSame(self::expectedComments(), $this->filteredComments($db));
    }

    #[DataProvider('engines')]
    public function testSearchAttachesTheRightCommentToEachRecord(string $engine): void
    {
        $db = $this->open($engine);

        $this->assertSame(self::expectedComments(), $this->searchComments($db, 'example'));
    }

    #[DataProvider('engines')]
    public function testSearchOnAPartialNameKeepsTheLinkedComment(string $engine): void
    {
        $db = $this->open($engine);

        // "mail" matches both mail.example.com A records by name and the MX record by content
        $found = $this->searchComments($db, 'mail');

        $this->assertCount(3, $found);
        $this->assertSame('Linked per-record comment', $found['mail.example.com/A/192.0.2.3']);
        $this->assertNull($found['mail.example.com/A/192.0.2.6']);
    }

    #[DataProvider('engines')]
    public function testSearchFindsARecordByItsRRsetCommentText(string $engine): void
    {
        $db = $this->open($engine);

        $this->assertSame(
            ['www.example.com/A/192.0.2.2' => 'Legacy RRset comment'],
            $this->searchComments($db, 'Legacy RRset', true)
        );
    }

    #[DataProvider('engines')]
    public function testSearchByLinkedCommentTextReturnsTheLinkedRecordAndItsRRsetSibling(string $engine): void
    {
        $db = $this->open($engine);

        // Search matches rcl.record_id OR the RRset triple, so sibling 192.0.2.6 comes back too,
        // while the display join excludes linked comments from the fallback and shows it as null.
        $this->assertSame(
            [
                'mail.example.com/A/192.0.2.3' => 'Linked per-record comment',
                'mail.example.com/A/192.0.2.6' => null,
            ],
            $this->searchComments($db, 'Linked per-record', true)
        );
    }

    #[DataProvider('otherEngines')]
    public function testEveryEngineMatchesSQLite(string $engine): void
    {
        $sqlite = $this->open('sqlite');
        $expected = [
            'listing' => $this->zoneListingComments($sqlite),
            'filtered' => $this->filteredComments($sqlite),
            'search' => $this->searchComments($sqlite, 'example'),
        ];

        $other = $this->open($engine);
        $actual = [
            'listing' => $this->zoneListingComments($other),
            'filtered' => $this->filteredComments($other),
            'search' => $this->searchComments($other, 'example'),
        ];

        $this->assertSame($expected, $actual, "$engine and SQLite should return identical comments");
    }
}
