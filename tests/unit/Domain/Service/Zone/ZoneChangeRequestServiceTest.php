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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\ChangeRequestNotifierInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Service\Zone\ZoneEditService;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Infrastructure\Repository\DbZoneChangeRequestRepository;
use TestHelpers\FakeConfiguration;

/**
 * Filing stores only the rows that differ and refuses invalid or oversized
 * ones; approval replays the actions in order with one serial bump and records
 * a refused write as a failure; reject and cancel move the state; staleness
 * flags records the zone has moved away from.
 */
class ZoneChangeRequestServiceTest extends TestCase
{
    private const ZONE_ID = 42;
    private const ZONE = 'example.com';
    private const REQUESTER = 7;
    private const REVIEWER = 9;
    private const SOA = 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600';

    private PDO $db;
    private DbZoneChangeRequestRepository $repository;
    private RecordRepositoryInterface&MockObject $records;
    private DomainRepositoryInterface&MockObject $domains;
    private ZoneRepositoryInterface&MockObject $zones;
    private RecordManagerInterface&MockObject $recordManager;
    private SOARecordManagerInterface&MockObject $soa;
    private DnsRecordValidationServiceInterface&MockObject $validator;
    private ZoneManagementService&MockObject $zoneManagement;
    private BackendCapabilitiesInterface&MockObject $backend;
    /** @var array<int|string, array<string, mixed>> Stored rows keyed by id */
    private array $stored = [];
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE zone_change_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INTEGER NOT NULL,
            zone_name VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, requester_id INTEGER NULL,
            requester_name VARCHAR(64) NOT NULL, request_comment TEXT NULL, base_serial VARCHAR(32) NULL, payload TEXT NOT NULL,
            reviewer_id INTEGER NULL, reviewer_name VARCHAR(64) NULL, review_comment TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at TIMESTAMP NULL, applied_at TIMESTAMP NULL, error TEXT NULL, snapshot TEXT NULL)');
        $this->repository = new DbZoneChangeRequestRepository($this->db);

        $this->stored = [
            '5' => $this->stored('5', 'www', '192.0.2.1'),
            '6' => $this->stored('6', 'mail', '192.0.2.2'),
        ];
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(int|string $id): ?array => $this->stored[(string)$id] ?? null);
        $this->records->method('getRecordsByName')->willReturn([]);
        $this->records->method('recordExists')->willReturn(false);
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturn('MASTER');
        $this->domains->method('getDomainNameById')->willReturn(self::ZONE);
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->method('getZoneComment')->willReturn('old comment');
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->soa->method('getSOARecord')->willReturn(self::SOA);
        $this->validator = $this->createMock(DnsRecordValidationServiceInterface::class);
        $this->validator->method('validateRecord')->willReturn(ValidationResult::success([]));
        $this->zoneManagement = $this->createMock(ZoneManagementService::class);
        $this->backend = $this->createMock(BackendCapabilitiesInterface::class);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
    }

    public function testFilingStoresOnlyTheRowsThatDiffer(): void
    {
        $this->recordManager->expects($this->never())->method('editRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService()->fileRecordEdits($this->submission([
            '5' => $this->row('5', 'www', '192.0.2.9'),
            '6' => $this->row('6', 'mail', '192.0.2.2'),
        ], serial: '2024010101', zoneComment: 'old comment'), 'please apply');

        $this->assertTrue($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_OK, $result->code);
        $request = $this->repository->find($result->requestId);
        $this->assertSame(ZoneChangeRequest::KIND_RECORDS, $request->kind);
        $this->assertSame('2024010101', $request->baseSerial);
        $this->assertSame('please apply', $request->requestComment);
        $this->assertNull($request->zoneComment);
        $this->assertCount(1, $request->actions);
        $action = $request->actions[0];
        $this->assertSame('edit', $action['op']);
        $this->assertSame('5', $action['record_id']);
        $this->assertSame('192.0.2.1', $action['before']['content']);
        $this->assertSame('5', $action['before']['id']);
        $this->assertSame(self::ZONE, $action['before']['zone_name']);
        $this->assertSame(['id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled', 'comment', 'zone_name'], array_keys($action['before']));
        $this->assertSame('192.0.2.9', $action['after']['content']);
        $this->assertSame('www.example.com', $action['after']['name']);
        $this->assertSame(3600, $action['after']['ttl']);
    }

    public function testAChangedZoneCommentIsFiledOnItsOwn(): void
    {
        $result = $this->makeService()->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.1')], zoneComment: 'new comment'));

        $this->assertTrue($result->success);
        $this->assertSame('new comment', $this->repository->find($result->requestId)->zoneComment);
        $this->assertSame([], $this->repository->find($result->requestId)->actions);
    }

    public function testNothingDifferentFilesNothing(): void
    {
        $result = $this->makeService()->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.1')], zoneComment: 'old comment'));

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_NO_CHANGES, $result->code);
        $this->assertSame(0, $this->repository->count([]));
    }

    public function testValidationFailureRefusesFiling(): void
    {
        $this->validator = $this->createMock(DnsRecordValidationServiceInterface::class);
        $this->validator->method('validateRecord')->willReturn(ValidationResult::failure('Invalid IP address'));

        $result = $this->makeService()->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', 'nope')]));

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_VALIDATION, $result->code);
        $this->assertSame(['Invalid IP address'], $result->errors);
        $this->assertSame(0, $this->repository->count([]));
    }

    public function testReadOnlyZonesRefuseFiling(): void
    {
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturn('SLAVE');

        $result = $this->makeService()->fileRecordAdd(self::ZONE_ID, self::ZONE, ['name' => 'x', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0], self::REQUESTER, 'alice');

        $this->assertSame(ZoneChangeRequestResult::CODE_READ_ONLY_ZONE, $result->code);
        $this->assertSame(403, $result->status);
    }

    public function testOversizedPayloadIsRefusedNotTruncated(): void
    {
        $record = ['name' => 'big', 'type' => 'TXT', 'content' => str_repeat('x', 100), 'ttl' => 300, 'prio' => 0, 'comment' => str_repeat('c', ZoneChangeRequestService::MAX_PAYLOAD_BYTES)];

        $result = $this->makeService()->fileRecordAdd(self::ZONE_ID, self::ZONE, $record, self::REQUESTER, 'alice');

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_PAYLOAD_TOO_LARGE, $result->code);
        $this->assertSame(413, $result->status);
        $this->assertSame(0, $this->repository->count([]));
    }

    public function testFilingAnAddRestoresTheZoneSuffixAndRefusesDuplicates(): void
    {
        $result = $this->makeService()->fileRecordAdd(self::ZONE_ID, self::ZONE, ['name' => 'new', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => '300', 'prio' => '0', 'comment' => 'why'], self::REQUESTER, 'alice');
        $this->assertTrue($result->success);
        $action = $this->repository->find($result->requestId)->actions[0];
        $this->assertSame('add', $action['op']);
        $this->assertSame(['name' => 'new.example.com', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0, 'disabled' => 0, 'comment' => 'why'], $action['after']);

        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('recordExists')->willReturn(true);
        $duplicate = $this->makeService()->fileRecordAdd(self::ZONE_ID, self::ZONE, ['name' => 'new', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0], self::REQUESTER, 'alice');
        $this->assertSame(ZoneChangeRequestResult::CODE_VALIDATION, $duplicate->code);
        $this->assertSame(409, $duplicate->status);
    }

    public function testFilingADeleteSnapshotsTheStoredRowAndRefusesOtherZones(): void
    {
        $result = $this->makeService()->fileRecordDelete(self::ZONE_ID, '6', self::REQUESTER, 'alice');
        $this->assertTrue($result->success);
        $action = $this->repository->find($result->requestId)->actions[0];
        $this->assertSame('delete', $action['op']);
        $this->assertSame('6', $action['record_id']);
        $this->assertSame('mail.example.com', $action['before']['name']);

        $this->stored['8'] = ['id' => '8', 'domain_id' => 99, 'name' => 'x.other.test', 'type' => 'A', 'content' => '192.0.2.8', 'ttl' => 60, 'prio' => 0, 'disabled' => 0];
        $foreign = $this->makeService()->fileRecordDelete(self::ZONE_ID, 8, self::REQUESTER, 'alice');
        $this->assertSame(ZoneChangeRequestResult::CODE_RECORD_NOT_FOUND, $foreign->code);
        $this->assertSame(404, $foreign->status);
    }

    public function testApproveReplaysAddEditAndDeleteInOrderAndFinalizesOnce(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturnCallback(function (int $zoneId, string $name, string $type, string $content, int $ttl, mixed $prio, int $disabled, bool $finalize): RecordWriteResult {
            $this->calls[] = "add:$name:$content:" . ($finalize ? 'finalize' : 'batch');
            return RecordWriteResult::ok(77);
        });
        $this->recordManager->method('editRecord')->willReturnCallback(function (array $record, bool $finalize): RecordWriteResult {
            $this->calls[] = "edit:{$record['rid']}:{$record['content']}:" . ($finalize ? 'finalize' : 'batch');
            return RecordWriteResult::ok();
        });
        $this->recordManager->method('deleteRecord')->willReturnCallback(function (int|string $rid, bool $finalize): RecordWriteResult {
            $this->calls[] = "delete:$rid:" . ($finalize ? 'finalize' : 'batch');
            return RecordWriteResult::ok();
        });
        $this->recordManager->method('editZoneComment')->willReturnCallback(function (int $zoneId, string $comment): RecordWriteResult {
            $this->calls[] = "zone_comment:$comment";
            return RecordWriteResult::ok();
        });
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID)->willReturnCallback(function (): void {
            $this->calls[] = 'finalize';
        });
        $id = $this->fileThreeActions(zoneComment: 'approved comment');
        $scoped = [];
        $changeset = function (?int $zoneId, ?string $comment, callable $work) use (&$scoped): mixed {
            $scoped[] = [$zoneId, $comment];
            return $work();
        };

        $result = $this->makeService($changeset)->approve($id, self::REVIEWER, 'bob', 'ok by me');

        $this->assertTrue($result->success, $result->message);
        $this->assertSame([
            'add:new.example.com:192.0.2.5:batch',
            'edit:5:192.0.2.9:batch',
            'delete:6:batch',
            'zone_comment:approved comment',
            'finalize',
        ], $this->calls);
        $this->assertSame([[self::ZONE_ID, 'Change request #' . $id . ' from alice: reason given']], $scoped);
        $request = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(self::REVIEWER, $request->reviewerId);
        $this->assertSame('ok by me', $request->reviewComment);
        $this->assertNotNull($request->appliedAt);
        $this->assertNull($request->error);
    }

    public function testApproveOnATransactionalBackendBumpsInsideAndRectifiesAfterTheCommit(): void
    {
        $this->backend = $this->createMock(BackendCapabilitiesInterface::class);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->recordManager->method('addRecordGetId')->willReturnCallback(function (): RecordWriteResult {
            $this->assertTrue($this->db->inTransaction());
            return RecordWriteResult::ok(77);
        });
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID)->willReturnCallback(function (): bool {
            $this->assertTrue($this->db->inTransaction());
            return true;
        });
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, false)->willReturnCallback(function (): void {
            $this->assertFalse($this->db->inTransaction());
        });
        $id = $this->fileThreeActions();

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertTrue($result->success, $result->message);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $this->repository->find($id)->status);
    }

    public function testARefusedWriteMarksTheRequestFailedAndStops(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(77));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::forbidden('You do not have permission to edit this record.'));
        $this->recordManager->expects($this->never())->method('deleteRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $id = $this->fileThreeActions();

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_APPLY_FAILED, $result->code);
        $this->assertSame(403, $result->status);
        $this->assertSame($id, $result->requestId);
        $request = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_FAILED, $request->status);
        $this->assertSame('Action 2 (edit) failed: You do not have permission to edit this record. Applied before the failure: 1.', $request->error);
        $this->assertSame(self::REVIEWER, $request->reviewerId);
    }

    public function testARefusedZoneCommentMarksTheRequestFailedAfterTheRecords(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(77));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('editZoneComment')->willReturn(RecordWriteResult::forbidden('You do not have the permission to edit this comment.'));
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $id = $this->fileThreeActions(zoneComment: 'approved comment');

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_APPLY_FAILED, $result->code);
        $this->assertSame(403, $result->status);
        $this->assertSame(
            'Action 4 (zone_comment) failed: You do not have the permission to edit this comment. Applied before the failure: 1, 2, 3.',
            $this->repository->find($id)->error
        );
    }

    public function testARefusedWriteOnATransactionalBackendRollsBack(): void
    {
        $this->backend = $this->createMock(BackendCapabilitiesInterface::class);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(77));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::failure('Invalid IP address'));
        $this->soa->expects($this->never())->method('updateSOASerial');
        $id = $this->fileThreeActions();

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertFalse($result->success);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame('Action 2 (edit) failed: Invalid IP address. Nothing was applied.', $this->repository->find($id)->error);
    }

    public function testAnEditWhoseRecordVanishedFailsAndADeleteWhoseRecordVanishedIsSatisfied(): void
    {
        unset($this->stored['5'], $this->stored['6']);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(77));
        $this->recordManager->expects($this->never())->method('deleteRecord');
        $id = $this->fileThreeActions();

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertSame(404, $result->status);
        $this->assertStringStartsWith('Action 2 (edit) failed: Record not found.', $this->repository->find($id)->error);

        $deleteOnly = $this->repository->create(self::ZONE_ID, self::ZONE, ZoneChangeRequest::KIND_RECORDS, self::REQUESTER, 'alice', null, null, [
            ['op' => 'delete', 'record_id' => '6', 'before' => ['name' => 'mail.example.com', 'type' => 'A', 'content' => '192.0.2.2']],
        ], null);
        $this->assertTrue($this->makeService()->approve($deleteOnly, self::REVIEWER, 'bob')->success);
    }

    public function testApprovingAZoneDeleteRequestDeletesTheZone(): void
    {
        $this->zoneManagement->expects($this->once())->method('deleteZone')->with(self::ZONE_ID)->willReturn(['success' => true, 'message' => 'Zone deleted successfully']);
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice', 'retire it')->requestId;
        $this->assertSame(ZoneChangeRequest::KIND_ZONE_DELETE, $this->repository->find($id)->kind);

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertTrue($result->success);
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $this->repository->find($id)->status);
    }

    public function testAnApprovedZoneDeleteKeepsAZoneFileSnapshot(): void
    {
        $order = [];
        $this->zoneManagement->method('deleteZone')->willReturnCallback(function () use (&$order): array {
            $order[] = 'delete';
            return ['success' => true, 'message' => 'Zone deleted successfully'];
        });
        $snapshot = function (int $zoneId, string $zoneName) use (&$order): string {
            $order[] = 'snapshot';
            return "\$ORIGIN $zoneName.\n";
        };
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;

        $this->assertTrue($this->makeService(zoneSnapshot: $snapshot)->approve($id, self::REVIEWER, 'bob')->success);

        $this->assertSame(['snapshot', 'delete'], $order);
        $this->assertSame("\$ORIGIN example.com.\n", $this->repository->find($id)->snapshot);
    }

    public function testAnOversizedSnapshotIsNotKept(): void
    {
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => true, 'message' => 'Zone deleted successfully']);
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;

        $this->assertTrue($this->makeService(zoneSnapshot: fn(): string => str_repeat('x', ZoneChangeRequestService::MAX_PAYLOAD_BYTES + 1))->approve($id, self::REVIEWER, 'bob')->success);

        $this->assertNull($this->repository->find($id)->snapshot);
    }

    public function testAFailedZoneDeleteMarksTheRequestFailed(): void
    {
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => false, 'message' => 'Zone not found', 'status' => 404]);
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertSame(404, $result->status);
        $this->assertSame('Zone not found', $this->repository->find($id)->error);
        $this->assertSame(ZoneChangeRequest::STATUS_FAILED, $this->repository->find($id)->status);
    }

    public function testAZoneDeleteNeedsTheReviewersDeletePermission(): void
    {
        $this->zoneManagement->expects($this->never())->method('deleteZone');
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canPerformZoneAction')->willReturn(false);
        $permissions->method('hasPermission')->willReturn(false);
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;

        $result = $this->makeService(null, $permissions)->approve($id, self::REVIEWER, 'bob');

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
        $this->assertSame(ZoneChangeRequest::STATUS_FAILED, $this->repository->find($id)->status);
    }

    public function testAZoneDeleteRunsWhenTheReviewerMayDeleteOwnZones(): void
    {
        $this->zoneManagement->expects($this->once())->method('deleteZone')->willReturn(['success' => true, 'message' => 'Zone deleted successfully']);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canPerformZoneAction')->with(self::REVIEWER, self::ZONE_ID, 'zone_delete_own')->willReturn(true);
        $id = $this->makeService()->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;

        $this->assertTrue($this->makeService(null, $permissions)->approve($id, self::REVIEWER, 'bob')->success);
    }

    public function testADecisionAlreadyTakenElsewhereIsReportedNotApplied(): void
    {
        $this->recordManager->expects($this->never())->method('editRecord');
        $id = $this->makeService()->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]))->requestId;
        // Another reviewer decided between the pending check and the claim
        $this->repository->markReviewed($id, ZoneChangeRequest::STATUS_REJECTED, 11, 'carol', null);

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob');

        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_PENDING, $result->code);
        $this->assertSame(ZoneChangeRequest::STATUS_REJECTED, $this->repository->find($id)->status);
        $this->assertSame('carol', $this->repository->find($id)->reviewerName);
    }

    public function testFilingRefusesARowFromAnotherZone(): void
    {
        $this->stored['77'] = ['id' => '77', 'domain_id' => 99, 'name' => 'secret.other.test', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];

        $result = $this->makeService()->fileRecordEdits($this->submission(['77' => $this->row('77', 'secret', '198.51.100.2')]));

        $this->assertFalse($result->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_VALIDATION, $result->code);
        $this->assertSame(['Record 77 was not found in this zone.'], $result->errors);
        $this->assertSame(0, $this->repository->countPending(null));
    }

    public function testTheNotifierHearsAboutFilingAndEveryDecision(): void
    {
        $heard = [];
        $notifier = $this->createMock(ChangeRequestNotifierInterface::class);
        $notifier->method('requestFiled')->willReturnCallback(function (ZoneChangeRequest $r) use (&$heard): void {
            $heard[] = 'filed:' . $r->status;
        });
        $notifier->method('requestDecided')->willReturnCallback(function (ZoneChangeRequest $r) use (&$heard): void {
            $heard[] = 'decided:' . $r->status;
        });
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $service = $this->makeService(null, null, $notifier);

        $approved = $service->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]))->requestId;
        $service->approve($approved, self::REVIEWER, 'bob');
        $rejected = $service->fileRecordEdits($this->submission(['6' => $this->row('6', 'mail', '192.0.2.3')]))->requestId;
        $service->reject($rejected, self::REVIEWER, 'bob', 'no');
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => false, 'message' => 'Zone not found', 'status' => 404]);
        $failed = $service->fileZoneDelete(self::ZONE_ID, self::REQUESTER, 'alice')->requestId;
        $service->approve($failed, self::REVIEWER, 'bob');

        $this->assertSame(['filed:pending', 'decided:approved', 'filed:pending', 'decided:rejected', 'filed:pending', 'decided:failed'], $heard);
    }

    public function testARequiredReasonIsCheckedWhenFiling(): void
    {
        $service = $this->makeService(requireComment: true);

        $refused = $service->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]));
        $this->assertFalse($refused->success);
        $this->assertSame(ZoneChangeRequestResult::CODE_VALIDATION, $refused->code);
        $this->assertSame(0, $this->repository->countPending(null));

        $this->assertTrue($service->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]), 'because')->success);
    }

    public function testCancellingTellsTheNotifier(): void
    {
        $notifier = $this->createMock(ChangeRequestNotifierInterface::class);
        $notifier->expects($this->once())->method('requestCancelled')
            ->with($this->callback(fn(ZoneChangeRequest $r): bool => $r->status === ZoneChangeRequest::STATUS_CANCELLED));
        $service = $this->makeService(null, null, $notifier);
        $id = $service->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]))->requestId;

        $this->assertTrue($service->cancel($id, self::REQUESTER)->success);
    }

    public function testAFailedRequestCanBeTriedAgainAndSkipsWhatAlreadyLanded(): void
    {
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(int|string $id): ?array => $this->stored[(string)$id] ?? null);
        // The add of the first attempt landed in full before the edit failed
        $this->records->method('getRecordsByName')->willReturnCallback(fn(int $z, string $name): array => $name === 'new.example.com'
            ? [['id' => '9', 'domain_id' => self::ZONE_ID, 'name' => 'new.example.com', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0, 'disabled' => 0]]
            : []);
        $this->records->method('recordExists')->willReturn(false);
        $this->recordManager->expects($this->never())->method('addRecordGetId');
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $id = $this->fileThreeActions();
        $this->repository->markReviewed($id, ZoneChangeRequest::STATUS_APPROVED, self::REVIEWER, 'bob', null);
        $this->repository->markFailed($id, 'Action 2 (edit) failed: boom. Applied before the failure: 1.');

        $result = $this->makeService()->approve($id, self::REVIEWER, 'bob', 'fixed');

        $this->assertTrue($result->success, $result->message);
        $request = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_APPROVED, $request->status);
        $this->assertNull($request->error);
        $this->assertNotNull($request->appliedAt);
    }

    public function testAnAddIsAppliedWhenTheStoredRowDiffersInTtl(): void
    {
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(int|string $id): ?array => $this->stored[(string)$id] ?? null);
        $this->records->method('getRecordsByName')->willReturn([['id' => '9', 'domain_id' => self::ZONE_ID, 'name' => 'new.example.com', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 60, 'prio' => 0, 'disabled' => 0]]);
        $this->records->method('recordExists')->willReturn(false);
        $this->recordManager->expects($this->once())->method('addRecordGetId')->willReturn(RecordWriteResult::ok(9));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $id = $this->fileThreeActions();

        $this->assertTrue($this->makeService()->approve($id, self::REVIEWER, 'bob')->success);
    }

    public function testRejectAndCancelStillRefuseAFailedRequest(): void
    {
        $id = $this->makeService()->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]))->requestId;
        $this->repository->markFailed($id, 'boom');

        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_PENDING, $this->makeService()->reject($id, self::REVIEWER, 'bob')->code);
        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_PENDING, $this->makeService()->cancel($id, self::REQUESTER)->code);
    }

    public function testAThrowingNotifierDoesNotUndoTheEvent(): void
    {
        $notifier = $this->createMock(ChangeRequestNotifierInterface::class);
        $notifier->method('requestFiled')->willThrowException(new \RuntimeException('smtp down'));
        $notifier->method('requestDecided')->willThrowException(new \RuntimeException('smtp down'));
        $service = $this->makeService(null, null, $notifier);

        $filed = $service->fileRecordEdits($this->submission(['5' => $this->row('5', 'www', '192.0.2.9')]));
        $this->assertTrue($filed->success);
        $this->assertTrue($service->reject($filed->requestId, self::REVIEWER, 'bob')->success);
        $this->assertSame(ZoneChangeRequest::STATUS_REJECTED, $this->repository->find($filed->requestId)->status);
    }

    public function testRejectRecordsTheDecisionWithoutWriting(): void
    {
        $this->recordManager->expects($this->never())->method('addRecordGetId');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $id = $this->fileThreeActions();

        $result = $this->makeService()->reject($id, self::REVIEWER, 'bob', 'not now');

        $this->assertTrue($result->success);
        $request = $this->repository->find($id);
        $this->assertSame(ZoneChangeRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('not now', $request->reviewComment);
        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_PENDING, $this->makeService()->approve($id, self::REVIEWER, 'bob')->code);
    }

    public function testCancelIsForTheRequesterOnly(): void
    {
        $id = $this->fileThreeActions();

        $refused = $this->makeService()->cancel($id, self::REVIEWER);
        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_REQUESTER, $refused->code);
        $this->assertSame(403, $refused->status);
        $this->assertSame(ZoneChangeRequest::STATUS_PENDING, $this->repository->find($id)->status);

        $this->assertTrue($this->makeService()->cancel($id, self::REQUESTER)->success);
        $this->assertSame(ZoneChangeRequest::STATUS_CANCELLED, $this->repository->find($id)->status);
        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_PENDING, $this->makeService()->cancel($id, self::REQUESTER)->code);
        $this->assertSame(ZoneChangeRequestResult::CODE_NOT_FOUND, $this->makeService()->cancel(999, self::REQUESTER)->code);
    }

    public function testStaleActionsFlagChangedMissingAndAlreadyPresentRecords(): void
    {
        $id = $this->repository->create(self::ZONE_ID, self::ZONE, ZoneChangeRequest::KIND_RECORDS, self::REQUESTER, 'alice', null, '2024010101', [
            ['op' => 'edit', 'record_id' => '5', 'before' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false], 'after' => []],
            ['op' => 'delete', 'record_id' => '6', 'before' => ['name' => 'mail.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0, 'disabled' => false]],
            ['op' => 'delete', 'record_id' => '7', 'before' => ['name' => 'gone.example.com', 'type' => 'A', 'content' => '192.0.2.3', 'ttl' => 3600, 'prio' => 0, 'disabled' => false]],
            ['op' => 'add', 'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1']],
            ['op' => 'add', 'after' => ['name' => 'fresh.example.com', 'type' => 'A', 'content' => '192.0.2.4']],
        ], null);
        $this->stored['5']['content'] = '192.0.2.99';
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(int|string $rid): ?array => $this->stored[(string)$rid] ?? null);
        $this->records->method('getRecordsByName')->willReturn([]);
        $this->records->method('recordExists')->willReturnCallback(fn(int $zone, string $name, string $type, string $content): bool => $name === 'www.example.com');
        $service = $this->makeService();
        $request = $this->repository->find($id);

        $this->assertSame([0, 2, 3], $service->staleActions($request));
        $this->assertFalse($service->baseSerialMismatch($request));

        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->soa->method('getSOARecord')->willReturn(str_replace('2024010101', '2024010102', self::SOA));
        $this->assertTrue($this->makeService()->baseSerialMismatch($request));
    }

    public function testStalenessFallsBackToNameTypeAndContentWhenTheIdNoLongerResolves(): void
    {
        $id = $this->repository->create(self::ZONE_ID, self::ZONE, ZoneChangeRequest::KIND_RECORDS, self::REQUESTER, 'alice', null, null, [
            ['op' => 'delete', 'record_id' => 'old-encoded-id', 'before' => ['name' => 'mail.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0, 'disabled' => false]],
        ], null);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturn(null);
        $this->records->method('getRecordsByName')->with(self::ZONE_ID, 'mail.example.com', 'A')->willReturn([$this->stored['6']]);
        $this->recordManager->expects($this->once())->method('deleteRecord')->with(6, false)->willReturn(RecordWriteResult::ok());

        $service = $this->makeService();
        $this->assertSame([], $service->staleActions($this->repository->find($id)));
        $this->assertTrue($service->approve($id, self::REVIEWER, 'bob')->success);
    }

    private function fileThreeActions(?string $zoneComment = null): int
    {
        return $this->repository->create(self::ZONE_ID, self::ZONE, ZoneChangeRequest::KIND_RECORDS, self::REQUESTER, 'alice', 'reason given', '2024010101', [
            ['op' => 'add', 'after' => ['name' => 'new.example.com', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0, 'disabled' => 0, 'comment' => '']],
            [
                'op' => 'edit',
                'record_id' => '5',
                'before' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
                'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => ''],
            ],
            ['op' => 'delete', 'record_id' => '6', 'before' => ['name' => 'mail.example.com', 'type' => 'A', 'content' => '192.0.2.2']],
        ], $zoneComment);
    }

    private function makeService(?\Closure $changeset = null, ?PermissionService $reviewerPermissions = null, ?ChangeRequestNotifierInterface $notifier = null, bool $requireComment = false, ?\Closure $zoneSnapshot = null): ZoneChangeRequestService
    {
        $config = new FakeConfiguration([
            'interface' => ['show_record_comments' => false, 'show_zone_comments' => true],
            'logging' => ['require_change_comment' => $requireComment],
            'misc' => ['edit_conflict_resolution' => 'last_writer_wins', 'record_comments_sync' => false],
            'dns' => ['bump_serial_on_unchanged_save' => true, 'hostmaster' => 'hostmaster.example.com', 'ttl' => 86400, 'txt_auto_quote' => false],
        ]);
        $permissions = $this->createMock(PermissionService::class);
        $zoneEdit = new ZoneEditService(
            $config,
            $permissions,
            $this->zones,
            $this->domains,
            $this->records,
            $this->recordManager,
            $this->soa,
            $this->createMock(RecordCommentService::class),
            $this->createMock(RecordCommentSyncService::class),
            $this->createMock(AuditService::class)
        );

        return new ZoneChangeRequestService(
            $this->repository,
            $zoneEdit,
            $this->validator,
            $this->records,
            $this->domains,
            $this->zones,
            $this->recordManager,
            $this->soa,
            $this->zoneManagement,
            $this->backend,
            $this->db,
            $config,
            null,
            $changeset,
            $reviewerPermissions,
            $notifier,
            $zoneSnapshot
        );
    }

    private function submission(?array $records = null, ?string $serial = null, ?string $zoneComment = null): ZoneEditSubmission
    {
        return new ZoneEditSubmission(self::ZONE_ID, self::ZONE, self::REQUESTER, 'alice', $records, true, $serial, true, $zoneComment);
    }

    /** @return array<string, mixed> A posted row as the editor sends it */
    private function row(string $rid, string $name, string $content): array
    {
        return ['rid' => $rid, 'zid' => self::ZONE_ID, 'name' => $name, 'type' => 'A', 'content' => $content, 'ttl' => '3600', 'prio' => '0', '_complete' => '1'];
    }

    /** @return array<string, mixed> The stored row RecordLog compares against */
    private function stored(string $id, string $name, string $content): array
    {
        return ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => $name . '.example.com', 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }
}
