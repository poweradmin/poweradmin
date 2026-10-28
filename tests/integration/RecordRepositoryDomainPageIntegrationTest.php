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
use Poweradmin\Application\Controller\Api\V2\Resource\RecordResource;
use Poweradmin\Application\Http\ListPaging;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\RecordCommentFixture;

/**
 * SqlRecordRepository::getRecordsPageByDomainId() and countRecordsByDomainId() on each
 * engine: the pages put together equal the unpaged list without empty non-terminals,
 * also across rows that share type and name (#1814).
 */
class RecordRepositoryDomainPageIntegrationTest extends TestCase
{
    private const ZONE_ID = 1;

    private ?PDO $db = null;
    private string $engine = '';

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            RecordCommentFixture::drop($this->db, $this->engine);
            $this->db = null;
        }
    }

    private function open(string $engine): SqlRecordRepository
    {
        try {
            $db = RecordCommentFixture::connect($engine);
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }
        // The app's MySQL connection uses native prepares, which refuse a reused placeholder
        if ($engine === 'mysql') {
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        $this->db = $db;
        $this->engine = $engine;
        RecordCommentFixture::createSchema($db, $engine);

        $records = [
            ['example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1 3600 900 604800 86400'],
            ['example.com', 'NS', 'ns2.example.com'],
            ['example.com', 'NS', 'ns1.example.com'],
            ['www.example.com', 'A', '192.0.2.3'],
            ['www.example.com', 'A', '192.0.2.1'],
            ['www.example.com', 'A', '192.0.2.2'],
            ['mail.example.com', 'A', '192.0.2.9'],
            ['example.com', 'MX', 'mail.example.com'],
            ['', 'A', '192.0.2.99'],
            ['ent.example.com', '', ''],
            ['ftp.example.com', 'CNAME', 'www.example.com'],
        ];
        foreach ($records as $index => [$name, $type, $content]) {
            RecordCommentFixture::addRecord($db, $index + 1, $name, $type, $content);
        }
        RecordCommentFixture::addComment($db, 1, 'www.example.com', 'A', 'Web servers');

        return new SqlRecordRepository($db, RecordCommentFixture::config($engine));
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return RecordCommentFixture::engines();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn(array $r): string => (string)$r['id'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listed(SqlRecordRepository $repo, ?string $type = null): array
    {
        return array_values(array_filter(
            $repo->getRecordsByDomainId(self::ZONE_ID, $type, true),
            static fn(array $r): bool => !empty($r['type']) && !empty($r['name'])
        ));
    }

    #[DataProvider('engines')]
    public function testPagesTogetherEqualTheUnpagedList(string $engine): void
    {
        $repo = $this->open($engine);
        $expected = self::listed($repo);

        $paged = [];
        foreach ([0, 3, 6, 9] as $offset) {
            $paged = array_merge($paged, $repo->getRecordsPageByDomainId(self::ZONE_ID, null, true, 3, $offset));
        }

        $this->assertCount(9, $expected);
        $this->assertSame(self::ids($expected), self::ids($paged));
        $this->assertSame(9, $repo->countRecordsByDomainId(self::ZONE_ID));
    }

    #[DataProvider('engines')]
    public function testPageCarriesTheSameRowsAsTheUnpagedList(string $engine): void
    {
        $repo = $this->open($engine);
        $expected = array_slice(self::listed($repo), 2, 4);

        $page = $repo->getRecordsPageByDomainId(self::ZONE_ID, null, true, 4, 2);

        $this->assertSame($expected, $page);
        $this->assertContains('Web servers', array_column($page, 'comment'));
    }

    #[DataProvider('engines')]
    public function testTypeFilterPagesAndCountsOnlyThatType(string $engine): void
    {
        $repo = $this->open($engine);

        $page = $repo->getRecordsPageByDomainId(self::ZONE_ID, 'A', false, 2, 2);

        $this->assertSame(array_slice(self::ids(self::listed($repo, 'A')), 2, 2), self::ids($page));
        $this->assertSame(4, $repo->countRecordsByDomainId(self::ZONE_ID, 'A'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<string> name|type of each RRset in the order it first appears
     */
    private static function rrsetKeys(array $rows): array
    {
        return array_values(array_unique(array_map(static fn(array $r): string => $r['name'] . '|' . $r['type'], $rows)));
    }

    #[DataProvider('engines')]
    public function testRRSetPagesTogetherCarryEveryRecordOnceInTheUnpagedOrder(string $engine): void
    {
        $repo = $this->open($engine);
        $expected = self::listed($repo);

        $paged = [];
        foreach ([0, 2, 4, 6] as $offset) {
            $paged = array_merge($paged, $repo->getRRSetPageRecords(self::ZONE_ID, null, 2, $offset));
        }

        $this->assertSame(self::ids($expected), self::ids($paged));
        $this->assertSame(6, $repo->countRRSetsByDomainId(self::ZONE_ID));
    }

    #[DataProvider('engines')]
    public function testAnRRSetPageNeverSplitsAnRRSet(string $engine): void
    {
        $repo = $this->open($engine);
        $keys = self::rrsetKeys(self::listed($repo));

        $page = $repo->getRRSetPageRecords(self::ZONE_ID, null, 1, array_search('www.example.com|A', $keys, true));

        $contents = array_column($page, 'content');
        sort($contents);

        $this->assertSame(['www.example.com|A'], self::rrsetKeys($page));
        $this->assertSame(['192.0.2.1', '192.0.2.2', '192.0.2.3'], $contents);
        $this->assertSame(2, $repo->countRRSetsByDomainId(self::ZONE_ID, 'A'));
    }

    /**
     * Ids of the listed rows the API's own PHP filter keeps for one query parameter.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<string>
     */
    private static function phpMatches(array $rows, string $parameter, string $needle): array
    {
        $values = [
            'name' => static fn(array $r): array => [$r['name']],
            'content' => static fn(array $r): array => [$r['content']],
            'q' => static fn(array $r): array => [$r['name'], $r['content']],
        ][$parameter];
        $items = array_map(static fn(array $r): array => RecordResource::listItem($r, static fn($id) => (string)$id), $rows);

        return array_column(ListPaging::filterContains($items, $needle, $values), 'id');
    }

    #[DataProvider('engines')]
    public function testThePreFilterKeepsEveryRowThePhpFilterKeeps(string $engine): void
    {
        $repo = $this->open($engine);
        RecordCommentFixture::addRecord($this->db, 20, 'txt.example.com', 'TXT', '"Hello World"');
        RecordCommentFixture::addRecord($this->db, 21, 'pct.example.com', 'TXT', '"50% off_now"');
        // mb_stripos folds the Kelvin sign to k and the long s to s; SQL LOWER does not
        RecordCommentFixture::addRecord($this->db, 22, 'kelvin.example.com', 'TXT', "\"\u{212A}elvin\"");
        RecordCommentFixture::addRecord($this->db, 23, 'longs.example.com', 'TXT', "\"\u{17F}ecret\"");
        $listed = self::listed($repo);

        foreach (['name', 'content', 'q'] as $parameter) {
            foreach (['www', 'WWW', ' 192.0.2.1 ', 'example', 'hello world', '"', 'Wörld', '50%', '_now', 'mail.example.com', 'nomatch', 'k', 'kelvin', 's', 'secret'] as $needle) {
                $sql = self::ids($repo->getRecordsByDomainIdContaining(self::ZONE_ID, null, true, [$parameter => $needle]));
                $missing = array_diff(self::phpMatches($listed, $parameter, $needle), $sql);
                $this->assertSame([], array_values($missing), "$parameter=$needle drops rows the PHP filter keeps");
            }
        }

        $this->assertSame(['4', '5', '6'], self::ids($repo->getRecordsByDomainIdContaining(self::ZONE_ID, null, true, ['name' => 'WWW'])), 'An ASCII needle narrows exactly');
        $this->assertCount(count($listed), $repo->getRecordsByDomainIdContaining(self::ZONE_ID, null, true, ['content' => '50%']), 'A LIKE wildcard does not narrow');
        $this->assertCount(count($listed), $repo->getRecordsByDomainIdContaining(self::ZONE_ID, null, true, ['q' => 'Wörld']), 'A non-ASCII needle does not narrow');
    }

    #[DataProvider('engines')]
    public function testAnRRSetWithOneMatchingMemberComesBackWhole(string $engine): void
    {
        $repo = $this->open($engine);

        $rows = $repo->getRRSetRecordsContaining(self::ZONE_ID, null, ['content' => '192.0.2.3']);

        $this->assertSame(['www.example.com|A'], self::rrsetKeys($rows));
        $this->assertCount(3, $rows);
    }

    #[DataProvider('engines')]
    public function testOffsetPastTheEndGivesAnEmptyPage(string $engine): void
    {
        $repo = $this->open($engine);

        $this->assertSame([], $repo->getRecordsPageByDomainId(self::ZONE_ID, null, false, 5, 50));
    }
}
