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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Repository\ApiRecordSearch;

/**
 * Searching comments over the PowerDNS API: a comment hit pulls in every record
 * of its RRset, merged with the name/content hits without duplicates.
 */
class ApiRecordSearchCommentsTest extends TestCase
{
    private PDO $db;
    /** @var list<array{string, string}> */
    private array $searches = [];
    private int $zoneFetches = 0;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (7, 'alice', 'Alice')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment) VALUES (1, 1, 7, ''), (2, 2, 8, '')");
    }

    private function row(int $domainId, string $zone, string $name, string $type, string $content): array
    {
        return [
            'id' => "$name|$type|$content", 'domain_id' => $domainId, 'name' => $name, 'type' => $type,
            'content' => $content, 'ttl' => 60, 'prio' => 0, 'disabled' => 0, 'zone_name' => $zone,
        ];
    }

    private function search(bool $enabled): ApiRecordSearch
    {
        $zoneA = [
            $this->row(1, 'a.example', 'www.a.example', 'A', '192.0.2.1'),
            $this->row(1, 'a.example', 'www.a.example', 'A', '192.0.2.2'),
            $this->row(1, 'a.example', 'mail.a.example', 'A', '192.0.2.3'),
        ];
        $zoneB = [$this->row(2, 'b.example', 'www.b.example', 'A', '192.0.2.9')];

        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('searchDnsData')->willReturnCallback(function (string $query, string $type) use ($zoneA) {
            $this->searches[] = [$query, $type];
            if ($type === 'comment') {
                return ['zones' => [], 'records' => [], 'comments' => [
                    ['domain_id' => 1, 'zone_name' => 'a.example', 'name' => 'www.a.example', 'type' => 'A', 'comment' => 'rotate in march'],
                    ['domain_id' => 2, 'zone_name' => 'b.example', 'name' => 'www.b.example', 'type' => 'A', 'comment' => 'rotate in march'],
                ]];
            }
            // The name/content search already found one of the commented records
            return ['zones' => [], 'records' => [$zoneA[0]]];
        });
        $backend->method('getZoneRecords')->willReturnCallback(function (int $domainId, string $zone) use ($zoneA, $zoneB) {
            $this->zoneFetches++;
            return $zone === 'a.example' ? $zoneA : $zoneB;
        });

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getOwnedZoneIds')->willReturn([1]);

        return new ApiRecordSearch($this->db, $backend, $zones, $enabled);
    }

    private function parameters(array $overrides = []): array
    {
        return array_merge(['query' => 'march', 'zones' => false, 'records' => true, 'comments' => true, 'wildcard' => true, 'reverse' => false], $overrides);
    }

    private function contents(array $rows): array
    {
        $contents = array_column($rows, 'content');
        sort($contents);
        return $contents;
    }

    public function testCommentHitReturnsEveryRecordOfTheRrsetWithoutDuplicates(): void
    {
        $search = $this->search(true);
        $rows = $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['192.0.2.1', '192.0.2.2', '192.0.2.9'], $this->contents($rows));
        $this->assertSame(3, $search->getTotalRecords($this->parameters(), 'all', null, false));
    }

    public function testOneZoneFetchServesAllHitsOfThatZone(): void
    {
        $this->search(true)->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(2, $this->zoneFetches, 'one fetch per zone, not per hit');
    }

    public function testCommentOptionOffSearchesNoComments(): void
    {
        $search = $this->search(true);
        $rows = $search->searchRecords($this->parameters(['comments' => false]), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['192.0.2.1'], $this->contents($rows));
        $this->assertNotContains('comment', array_column($this->searches, 1));
    }

    public function testRecordCommentsInterfaceSettingOffSearchesNoComments(): void
    {
        $search = $this->search(false);
        $rows = $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['192.0.2.1'], $this->contents($rows));
        $this->assertSame(1, $search->getTotalRecords($this->parameters(), 'all', null, false));
        $this->assertNotContains('comment', array_column($this->searches, 1));
    }

    public function testOwnViewDropsCommentMatchesInOtherUsersZones(): void
    {
        $search = $this->search(true);
        $rows = $search->searchRecords($this->parameters(), 'own', 7, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['192.0.2.1', '192.0.2.2'], $this->contents($rows));
        $this->assertSame(2, $search->getTotalRecords($this->parameters(), 'own', 7, false));
    }

    public function testPaginationAndCountCoverTheMergedSet(): void
    {
        $search = $this->search(true);
        $first = $search->searchRecords($this->parameters(), 'all', null, 'content', 'ASC', false, 2, false, 1);
        $second = $search->searchRecords($this->parameters(), 'all', null, 'content', 'ASC', false, 2, false, 2);

        $this->assertCount(2, $first);
        $this->assertCount(1, $second);
        $this->assertSame(3, $search->getTotalRecords($this->parameters(), 'all', null, false));
    }

    public function testExactMatchWithoutWildcardNeedsTheWholeComment(): void
    {
        $search = $this->search(true);

        $this->assertSame([], $search->searchRecords($this->parameters(['wildcard' => false]), 'all', null, 'name', 'ASC', false, 50, false, 1));
        $exact = $search->searchRecords($this->parameters(['wildcard' => false, 'query' => 'rotate in march']), 'all', null, 'name', 'ASC', false, 50, false, 1);
        $this->assertSame(['192.0.2.1', '192.0.2.2', '192.0.2.9'], $this->contents($exact));
    }

    public function testTypeFilterStillAppliesToCommentMatches(): void
    {
        $search = $this->search(true);
        $rows = $search->searchRecords($this->parameters(['type_filter' => 'MX']), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame([], $rows);
    }

    /**
     * @param list<array> $commentHits
     * @param array<string, list<array>> $zoneRows rows by zone name
     */
    private function searchWith(array $commentHits, array $zoneRows, array $nameHits = [], array $owned = [1]): ApiRecordSearch
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('searchDnsData')->willReturnCallback(function (string $query, string $type) use ($commentHits, $nameHits) {
            $this->searches[] = [$query, $type];
            return $type === 'comment'
                ? ['zones' => [], 'records' => [], 'comments' => $commentHits]
                : ['zones' => [], 'records' => $nameHits];
        });
        $backend->method('getZoneRecords')->willReturnCallback(function (int $domainId, string $zone) use ($zoneRows) {
            $this->zoneFetches++;
            return $zoneRows[$zone] ?? [];
        });
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getOwnedZoneIds')->willReturn($owned);

        return new ApiRecordSearch($this->db, $backend, $zones, true);
    }

    private function commentHit(int $domainId, string $zone, string $name, string $type = 'A'): array
    {
        return ['domain_id' => $domainId, 'zone_name' => $zone, 'name' => $name, 'type' => $type, 'comment' => 'march'];
    }

    public function testRecordsDifferingOnlyByContentCaseAreBothKept(): void
    {
        $upper = $this->row(1, 'a.example', 'txt.a.example', 'TXT', 'Token');
        $lower = $this->row(1, 'a.example', 'txt.a.example', 'TXT', 'token');
        $search = $this->searchWith([$this->commentHit(1, 'a.example', 'txt.a.example', 'TXT')], ['a.example' => [$upper, $lower]], [$upper]);

        $rows = $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['Token', 'token'], $this->contents($rows));
    }

    public function testIdenticalRecordsInDifferentZonesDoNotSuppressEachOther(): void
    {
        $inA = $this->row(1, 'a.example', 'www.shared', 'A', '192.0.2.1');
        $inB = $this->row(2, 'b.example', 'www.shared', 'A', '192.0.2.1');
        unset($inA['id'], $inB['id']);
        $search = $this->searchWith([$this->commentHit(2, 'b.example', 'www.shared')], ['b.example' => [$inB]], [$inA]);

        $rows = $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);

        $ids = array_column($rows, 'domain_id');
        sort($ids);
        $this->assertSame([1, 2], $ids);
    }

    public function testOwnViewFetchesOnlyTheUsersZones(): void
    {
        $zoneA = [$this->row(1, 'a.example', 'www.a.example', 'A', '192.0.2.1')];
        $search = $this->searchWith(
            [$this->commentHit(1, 'a.example', 'www.a.example'), $this->commentHit(2, 'b.example', 'www.b.example'), $this->commentHit(3, 'c.example', 'www.c.example')],
            ['a.example' => $zoneA],
        );

        $rows = $search->searchRecords($this->parameters(), 'own', 7, 'name', 'ASC', false, 50, false, 1);

        $this->assertSame(['192.0.2.1'], $this->contents($rows));
        $this->assertSame(1, $this->zoneFetches, 'other users zones are never fetched');
    }

    public function testZoneFetchesAreCappedPerSearch(): void
    {
        $hits = [];
        $zoneRows = [];
        for ($i = 1; $i <= 80; $i++) {
            $hits[] = $this->commentHit($i, "z$i.example", "www.z$i.example");
            $zoneRows["z$i.example"] = [$this->row($i, "z$i.example", "www.z$i.example", 'A', '192.0.2.1')];
        }
        $search = $this->searchWith($hits, $zoneRows);

        $rows = $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 100, false, 1);

        $this->assertSame(50, $this->zoneFetches);
        $this->assertCount(50, $rows);
    }

    /** @return array{list<array>, array<string, list<array>>} */
    private function zoneHits(int $count): array
    {
        $hits = [];
        $zoneRows = [];
        for ($i = 1; $i <= $count; $i++) {
            $hits[] = $this->commentHit($i, "z$i.example", "www.z$i.example");
            $zoneRows["z$i.example"] = [$this->row($i, "z$i.example", "www.z$i.example", 'A', '192.0.2.1')];
        }

        return [$hits, $zoneRows];
    }

    public function testMoreThanTheZoneCapSetsTheTruncatedFlag(): void
    {
        [$hits, $zoneRows] = $this->zoneHits(51);
        $search = $this->searchWith($hits, $zoneRows);

        $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 100, false, 1);

        $this->assertTrue($search->commentMatchesTruncated());
    }

    public function testExactlyTheZoneCapDoesNotSetTheTruncatedFlag(): void
    {
        [$hits, $zoneRows] = $this->zoneHits(50);
        $search = $this->searchWith($hits, $zoneRows);

        $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 100, false, 1);

        $this->assertFalse($search->commentMatchesTruncated());
    }

    public function testZonesTheUserCannotSeeDoNotCountTowardsTruncation(): void
    {
        [$hits, $zoneRows] = $this->zoneHits(80);
        $search = $this->searchWith($hits, $zoneRows, [], range(1, 10));

        $rows = $search->searchRecords($this->parameters(), 'own', 7, 'name', 'ASC', false, 100, false, 1);

        $this->assertCount(10, $rows);
        $this->assertFalse($search->commentMatchesTruncated());
    }

    public function testTheTruncatedFlagSurvivesTheCountAndResetsOnTheNextSearch(): void
    {
        [$hits, $zoneRows] = $this->zoneHits(60);
        $search = $this->searchWith($hits, $zoneRows);
        $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 100, false, 1);
        $search->getTotalRecords($this->parameters(), 'all', null, false);
        $this->assertTrue($search->commentMatchesTruncated());

        $search->searchRecords($this->parameters(['comments' => false]), 'all', null, 'name', 'ASC', false, 100, false, 1);

        $this->assertFalse($search->commentMatchesTruncated());
    }

    public function testCountReusesTheCommentRowsOfThePageSearch(): void
    {
        $search = $this->search(true);
        $search->searchRecords($this->parameters(), 'all', null, 'name', 'ASC', false, 50, false, 1);
        $fetches = $this->zoneFetches;
        $commentSearches = count(array_filter($this->searches, fn($s) => $s[1] === 'comment'));

        $this->assertSame(3, $search->getTotalRecords($this->parameters(), 'all', null, false));
        $this->assertSame($fetches, $this->zoneFetches, 'the count fetches no zone again');
        $this->assertCount($commentSearches, array_filter($this->searches, fn($s) => $s[1] === 'comment'));
    }

    public function testMemoisedRowsAreNotSharedAcrossUsersOrViews(): void
    {
        $search = $this->search(true);
        $all = $search->getTotalRecords($this->parameters(), 'all', null, false);
        $own = $search->getTotalRecords($this->parameters(), 'own', 7, false);

        $this->assertSame(3, $all);
        $this->assertSame(2, $own);
    }
}
