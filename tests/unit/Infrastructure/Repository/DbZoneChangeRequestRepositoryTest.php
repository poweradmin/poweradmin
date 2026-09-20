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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Infrastructure\Repository\DbZoneChangeRequestRepository;

/**
 * zone_change_requests round-trips the payload, lists newest first within a
 * window, scopes pending counts to a zone set, and moves through its states.
 */
#[CoversClass(DbZoneChangeRequestRepository::class)]
class DbZoneChangeRequestRepositoryTest extends TestCase
{
    private PDO $db;
    private DbZoneChangeRequestRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(<<<SQL
            CREATE TABLE zone_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                zone_name VARCHAR(255) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                requester_id INTEGER NULL,
                requester_name VARCHAR(64) NOT NULL,
                request_comment TEXT NULL,
                base_serial VARCHAR(32) NULL,
                payload TEXT NOT NULL,
                reviewer_id INTEGER NULL,
                reviewer_name VARCHAR(64) NULL,
                review_comment TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL,
                applied_at TIMESTAMP NULL,
                error TEXT NULL,
                snapshot TEXT NULL
            )
        SQL);
        $this->repository = new DbZoneChangeRequestRepository($this->db);
    }

    public function testCreateStoresThePayloadAndFindReadsItBack(): void
    {
        $actions = [
            ['op' => 'add', 'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => 'ünïcode/slash']],
        ];

        $id = $this->repository->create(42, 'example.com', ZoneChangeRequest::KIND_RECORDS, 7, 'alice', 'please', '2024010101', $actions, 'new zone comment');
        $request = $this->repository->find($id);

        $this->assertNotNull($request);
        $this->assertSame($id, $request->id);
        $this->assertSame(42, $request->zoneId);
        $this->assertSame('example.com', $request->zoneName);
        $this->assertSame(ZoneChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame(7, $request->requesterId);
        $this->assertSame('alice', $request->requesterName);
        $this->assertSame('please', $request->requestComment);
        $this->assertSame('2024010101', $request->baseSerial);
        $this->assertSame($actions, $request->actions);
        $this->assertSame('new zone comment', $request->zoneComment);
        $this->assertNull($request->reviewerId);
        $this->assertNull($request->reviewedAt);
        $this->assertTrue($request->isPending());
        $this->assertStringContainsString('ünïcode/slash', (string)$this->db->query('SELECT payload FROM zone_change_requests')->fetchColumn());
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository->find(999));
    }

    public function testListingIsNewestFirstAndWindowed(): void
    {
        $first = $this->file(1);
        $second = $this->file(1);
        $third = $this->file(2);

        $ids = array_map(fn(ZoneChangeRequest $r): int => $r->id, $this->repository->list([], 0, 10));
        $this->assertSame([$third, $second, $first], $ids);

        $page = array_map(fn(ZoneChangeRequest $r): int => $r->id, $this->repository->list([], 1, 1));
        $this->assertSame([$second], $page);
        $this->assertSame(3, $this->repository->count([]));
    }

    public function testFiltersNarrowByStatusZoneAndRequester(): void
    {
        $mine = $this->file(1, requesterId: 7);
        $this->file(1, requesterId: 8);
        $other = $this->file(2, requesterId: 7);
        $this->repository->markReviewed($mine, ZoneChangeRequest::STATUS_REJECTED, 9, 'bob', null);

        $this->assertSame([$other], $this->ids($this->repository->list(['status' => 'pending', 'requesterId' => 7], 0, 10)));
        $this->assertSame([$mine], $this->ids($this->repository->list(['status' => 'rejected'], 0, 10)));
        $this->assertSame(2, $this->repository->count(['requesterId' => 7]));
        $this->assertSame([$mine], $this->ids($this->repository->list(['zoneIds' => [1], 'requesterId' => 7], 0, 10)));
    }

    public function testPendingCountsTreatNullAsEveryZoneAndEmptyAsNone(): void
    {
        $this->file(1);
        $this->file(1);
        $this->file(2);
        $rejected = $this->file(3);
        $this->repository->markReviewed($rejected, ZoneChangeRequest::STATUS_REJECTED, 9, 'bob', 'no');

        $this->assertSame(3, $this->repository->countPending(null));
        $this->assertSame(0, $this->repository->countPending([]));
        $this->assertSame(2, $this->repository->countPending([1]));
        $this->assertSame(3, $this->repository->countPending([1, 2, 3]));
        $this->assertSame([], $this->repository->listPending([], 0, 10));
        $this->assertCount(1, $this->repository->listPending([2], 0, 10));
        $this->assertCount(2, $this->repository->listPendingForZone(1));
        $this->assertCount(0, $this->repository->listPendingForZone(3));
    }

    public function testReviewAppliedAndFailedTransitions(): void
    {
        $id = $this->file(1);

        $this->assertTrue($this->repository->markReviewed($id, ZoneChangeRequest::STATUS_APPROVED, 9, 'bob', 'looks fine'));
        $reviewed = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $reviewed->status);
        $this->assertSame(9, $reviewed->reviewerId);
        $this->assertSame('bob', $reviewed->reviewerName);
        $this->assertSame('looks fine', $reviewed->reviewComment);
        $this->assertNotNull($reviewed->reviewedAt);
        $this->assertNull($reviewed->appliedAt);

        $this->repository->markFailed($id, 'Action 1 (add) failed: nope');
        $failed = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_FAILED, $failed->status);
        $this->assertSame('Action 1 (add) failed: nope', $failed->error);
        $this->assertSame(9, $failed->reviewerId);

        $this->repository->markApplied($id);
        $applied = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $applied->status);
        $this->assertNotNull($applied->appliedAt);
        $this->assertNull($applied->error);
    }

    public function testCancelMarksTheRequestCancelled(): void
    {
        $id = $this->file(1);

        $this->assertTrue($this->repository->cancel($id));

        $cancelled = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->reviewedAt);
        $this->assertSame(0, $this->repository->countPending(null));
    }

    public function testAMalformedPayloadReadsAsNoActions(): void
    {
        $this->db->exec("INSERT INTO zone_change_requests (zone_id, zone_name, kind, status, requester_name, payload) VALUES (1, 'z', 'records', 'pending', 'x', 'not json')");

        $request = $this->repository->find((int)$this->db->lastInsertId());

        $this->assertSame([], $request->actions);
        $this->assertNull($request->zoneComment);
    }

    private function file(int $zoneId, int $requesterId = 7): int
    {
        return $this->repository->create($zoneId, "zone$zoneId.test", ZoneChangeRequest::KIND_RECORDS, $requesterId, 'alice', null, null, [['op' => 'zone_delete']], null);
    }

    /**
     * @param list<ZoneChangeRequest> $requests
     * @return list<int>
     */
    private function ids(array $requests): array
    {
        return array_map(fn(ZoneChangeRequest $r): int => $r->id, $requests);
    }

    public function testOnlyAPendingRequestCanBeDecidedOrCancelled(): void
    {
        $id = $this->file(1);

        $this->assertTrue($this->repository->markReviewed($id, ZoneChangeRequest::STATUS_REJECTED, 9, 'bob', null));
        $this->assertFalse($this->repository->markReviewed($id, ZoneChangeRequest::STATUS_APPROVED, 10, 'carol', null));
        $this->assertFalse($this->repository->cancel($id));

        $decided = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_REJECTED, $decided->status);
        $this->assertSame('bob', $decided->reviewerName);
    }

    public function testReviewableZonesOrOwnRequestsCombineWithinTheZoneScope(): void
    {
        $inReviewedZone = $this->file(1, requesterId: 9);
        $ownElsewhere = $this->file(2, requesterId: 7);
        $this->file(3, requesterId: 9);

        $filters = ['reviewableZoneIds' => [1], 'orRequesterId' => 7];
        $this->assertSame([$ownElsewhere, $inReviewedZone], $this->ids($this->repository->list($filters, 0, 10)));
        $this->assertSame(2, $this->repository->count($filters));

        $this->assertSame([$ownElsewhere], $this->ids($this->repository->list(['reviewableZoneIds' => [], 'orRequesterId' => 7], 0, 10)));
        $this->assertSame([$inReviewedZone], $this->ids($this->repository->list($filters + ['zoneIds' => [1]], 0, 10)));
        $this->assertSame([], $this->repository->list($filters + ['zoneIds' => []], 0, 10));
    }

    public function testPendingCountsPerZoneSkipZonesWithoutAny(): void
    {
        $this->file(1);
        $this->file(1);
        $decided = $this->file(2);
        $this->repository->markReviewed($decided, ZoneChangeRequest::STATUS_REJECTED, 9, 'bob', null);
        $this->file(3);

        $this->assertSame([1 => 2, 3 => 1], $this->repository->countPendingByZone([1, 2, 3], null, 99));
        $this->assertSame([1 => 2], $this->repository->countPendingByZone([1, 2, 3], [1], 99));
        $this->assertSame([1 => 2, 3 => 1], $this->repository->countPendingByZone([1, 2, 3], [], 7));
        $this->assertSame([], $this->repository->countPendingByZone([1, 2, 3], [], 99));
        $this->assertSame([], $this->repository->countPendingByZone([], null, 7));
    }

    public function testASnapshotIsKeptWithTheRequest(): void
    {
        $id = $this->file(1);
        $this->assertNull($this->repository->find($id)->snapshot);

        $this->repository->storeSnapshot($id, "\$ORIGIN zone1.test.\n@ IN SOA ns1 hostmaster 1 1 1 1 1\n");

        $this->assertStringStartsWith('$ORIGIN zone1.test.', (string)$this->repository->find($id)->snapshot);
    }
}
