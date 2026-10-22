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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;
use TestHelpers\StubActor;

/**
 * Characterization of POST /api/v2/zones/{id}/records/bulk: the per-action API
 * key scope check, the all-or-nothing rollback, and the counters the caller gets
 * back. Behaviour is pinned as it stands, including the odd parts.
 */
class ZonesRecordsBulkControllerTest extends V2ControllerTestCase
{

    private const USER_ID = 9;
    private const ZONE_ID = 51;
    private const ZONE_NAME = 'example.com';

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;
    private RecordManagerInterface&MockObject $recordManager;
    private AuditService&MockObject $audit;
    private ReverseRecordCreator&MockObject $reverseCreator;
    private RecordCommentService&MockObject $comments;
    private RecordManagerService&MockObject $recordManagerService;
    private RecordCommentSyncService&MockObject $commentSync;

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ApiKeyScope $scope;
    private int $zoneIdParameter = self::ZONE_ID;
    private bool $localTransactions = true;
    /** Canonical id the backend reports for the zone, as for a zone moved from SQL to API mode */
    private ?int $canonicalZoneId = null;
    private ?FakeConfiguration $config = null;
    private ?PDO $txDb = null;

    protected function setUp(): void
    {
        RecordChangeLogger::resetChangesetScope();

        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->reverseCreator = $this->createMock(ReverseRecordCreator::class);
        $this->comments = $this->createMock(RecordCommentService::class);
        $this->recordManagerService = $this->createMock(RecordManagerService::class);
        $this->commentSync = $this->createMock(RecordCommentSyncService::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('canEditZoneRecord')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
    }

    protected function tearDown(): void
    {
        RecordChangeLogger::resetChangesetScope();
    }

    public function testANonPositiveZoneIdIsRefusedFirst(): void
    {
        $this->zoneIdParameter = 0;

        $response = $this->bulk(['operations' => [$this->createOperation()]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID is required', $this->messageOf($response));
    }

    public function testAnOutOfScopeApiKeyIs403(): void
    {
        $this->scope = new ApiKeyScope([1], null, false);

        $response = $this->bulk(['operations' => [$this->createOperation()]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAMissingZoneIs404(): void
    {
        $this->zoneRow = null;

        $response = $this->bulk(['operations' => [$this->createOperation()]]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testARequestModeCallerIsPointedAtChangeRequests(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);

        $response = $this->bulk(['operations' => [$this->createOperation()]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Changes to this zone require approval; create a change request instead', $this->messageOf($response));
    }

    public function testAMissingOperationsFieldIs400(): void
    {
        $response = $this->bulk(['comment' => 'nothing to do']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Field 'operations' is required and must be an array", $this->messageOf($response));
    }

    public function testANonArrayOperationsFieldIs400(): void
    {
        $response = $this->bulk(['operations' => 'create everything']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Field 'operations' is required and must be an array", $this->messageOf($response));
    }

    public function testAnEmptyOperationsListIs400(): void
    {
        $response = $this->bulk(['operations' => []]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('At least one operation is required', $this->messageOf($response));
    }

    public function testMoreOperationsThanTheLimitAreRefusedBeforeAnythingIsWritten(): void
    {
        $this->config = new FakeConfiguration(['api' => ['bulk_max_operations' => 2]]);
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['operations' => [$this->createOperation(), $this->createOperation(), $this->createOperation()]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Too many operations: at most 2 per request', $this->messageOf($response));
    }

    public function testTheDefaultLimitIsAThousandOperations(): void
    {
        $response = $this->bulk(['operations' => array_fill(0, 1001, ['action' => 'delete', 'id' => 3])]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Too many operations: at most 1000 per request', $this->messageOf($response));
    }

    public function testALimitOfZeroLetsAnyNumberOfOperationsThrough(): void
    {
        $this->config = new FakeConfiguration(['api' => ['bulk_max_operations' => 0]]);

        $response = $this->bulk(['operations' => array_fill(0, 1001, ['action' => 'delete'])]);

        // Reaches the per-operation checks instead of the limit
        $this->assertSame("Operation 0 (delete): Field 'id' is required for delete operation", $this->decode($response)['data']['errors'][0]);
    }

    public function testAnApiKeyWithoutTheDeleteOperationIsRefusedBeforeAnythingIsWritten(): void
    {
        // The HTTP method is POST for every action, so the scope is enforced per item.
        $this->scope = new ApiKeyScope(null, [ApiKeyScope::OP_CREATE, ApiKeyScope::OP_UPDATE], false);
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['operations' => [$this->createOperation(), ['action' => 'delete', 'id' => 3]]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: this API key is not permitted to perform the delete operation', $this->messageOf($response));
    }

    public function testAnUnknownActionIsRefusedByTheScopeGateBeforeAnythingIsApplied(): void
    {
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['operations' => [$this->createOperation(), ['action' => 'frobnicate']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Invalid action: frobnicate. Must be 'create', 'update', or 'delete'", $this->messageOf($response));
    }

    public function testACreateMissingARequiredFieldFailsTheWholeBatch(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $response = $this->bulk(['operations' => [
            $this->createOperation(),
            ['action' => 'create', 'type' => 'A', 'content' => '192.0.2.2'],
        ]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(["Operation 1 (create): Field 'name' is required for create operation"], $body['data']['errors']);
        // The rollback zeroes the counters: nothing was persisted.
        $this->assertSame(0, $body['data']['created']);
        $this->assertSame(2, $body['data']['total_operations']);
    }

    public function testWithoutALocalTransactionTheCountersKeepTheirPreFailureValues(): void
    {
        // On a backend with no local transaction there is nothing to roll back, so
        // the counters still report the writes that already went through.
        $this->localTransactions = false;
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $response = $this->bulk(['operations' => [
            $this->createOperation(),
            ['action' => 'update'],
        ]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['data']['created']);
        $this->assertSame(1, $body['data']['failed']);
        $this->assertSame(["Operation 1 (update): Field 'id' is required for update operation"], $body['data']['errors']);
    }

    public function testAnUpdateWithoutAnIdIs400(): void
    {
        $response = $this->bulk(['operations' => [['action' => 'update', 'content' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(["Operation 0 (update): Field 'id' is required for update operation"], $this->decode($response)['data']['errors']);
    }

    public function testADeleteWithoutAnIdIs400(): void
    {
        $response = $this->bulk(['operations' => [['action' => 'delete']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(["Operation 0 (delete): Field 'id' is required for delete operation"], $this->decode($response)['data']['errors']);
    }

    public function testDeletingARecordFromAnotherZoneIs404ForTheWholeBatch(): void
    {
        $this->records->method('getRecordById')->willReturn(['id' => 3, 'domain_id' => 4, 'type' => 'A', 'name' => 'www.example.com']);

        $response = $this->bulk(['operations' => [['action' => 'delete', 'id' => 3]]]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['Operation 0 (delete): Record not found in this zone'], $this->decode($response)['data']['errors']);
    }

    public function testABatchThroughTheZoneRowIdDeletesARecordCarryingTheCanonicalId(): void
    {
        $this->canonicalZoneId = 4;
        $this->records->method('getRecordById')->willReturn(['id' => 3, 'domain_id' => 4, 'type' => 'A', 'name' => 'www.example.com']);
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->bulk(['operations' => [['action' => 'delete', 'id' => 3]]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->decode($response)['data']['deleted']);
    }

    public function testASuccessfulMixedBatchReportsOneCounterPerAction(): void
    {
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'old.example.com',
            'type' => 'A',
            'content' => '192.0.2.5',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, false);

        $response = $this->bulk(['operations' => [
            $this->createOperation(),
            ['action' => 'update', 'id' => 3, 'content' => '192.0.2.6'],
            ['action' => 'delete', 'id' => 3],
        ]]);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Bulk operations completed successfully', $body['message']);
        $this->assertSame(
            ['total_operations' => 3, 'created' => 1, 'updated' => 1, 'deleted' => 1, 'failed' => 0, 'errors' => [], 'warnings' => []],
            $body['data']
        );
    }

    public function testABulkUpdateKeepsAStoredTtlOfZero(): void
    {
        // 0 means "do not cache" and TTLValidator accepts it; an update that does
        // not mention ttl inherits it and must not be refused for that
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 0,
            'prio' => 0,
            'disabled' => 0,
        ]);
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->callback(static fn(array $record): bool => $record['ttl'] === 0))
            ->willReturn(RecordWriteResult::ok(3));

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'content' => '192.0.2.9']]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testABulkUpdateRefusesANegativeTtl(): void
    {
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'ttl' => -1]]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('TTL must not be negative', implode(' ', $body['data']['errors']));
    }

    public function testTheAuditRowRecordsHowManyOperationsRan(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->audit->expects($this->once())->method('logApiBulkRecords')->with(self::ZONE_ID, 2);

        $response = $this->bulk(['operations' => [$this->createOperation(), $this->createOperation(['name' => 'www2'])]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheSerialIsNotBumpedForAnSoaOnlyBatch(): void
    {
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => self::ZONE_NAME,
            'type' => 'SOA',
            'content' => 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $response = $this->bulk(
            ['operations' => [['action' => 'update', 'id' => 3, 'content' => 'ns1.example.com hostmaster.example.com 7 10800 3600 604800 3600']]],
            $soa
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUnchangedUpdateNeitherBumpsTheSerialNorRectifies(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::unchanged());
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'content' => '192.0.2.5']]], $soa);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->decode($response)['data']['updated']);
    }

    public function testABatchWithOneRealWriteBumpsAndRectifiesOnce(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::unchanged());
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, false);

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);

        $response = $this->bulk(['operations' => [
            ['action' => 'update', 'id' => 3, 'content' => '192.0.2.5'],
            $this->createOperation(),
        ]], $soa);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testACreateCommentIsStoredWithTheRecordAndNeverTheChangeReason(): void
    {
        $this->recordManager->expects($this->once())->method('addRecordGetId')->with(
            self::ZONE_ID,
            'www.example.com',
            'A',
            '192.0.2.1',
            3600,
            0,
            0,
            false,
            $this->callback(fn($c) => $c['content'] === 'web server')
        )->willReturn(RecordWriteResult::ok(7));
        $this->comments->expects($this->once())->method('createCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'web server', 7);

        $response = $this->bulk(['comment' => 'ticket-1', 'operations' => [$this->createOperation(['comment' => '  web server  '])]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testACreateWithoutACommentStoresNone(): void
    {
        $this->recordManager->method('addRecordGetId')->with(
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            false,
            null
        )->willReturn(RecordWriteResult::ok(7));
        $this->comments->expects($this->never())->method('createCommentForRecord');

        $this->assertSame(200, $this->bulk(['operations' => [$this->createOperation()]])->getStatusCode());
    }

    public function testAnUpdateCommentReplacesTheStoredOne(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->records->method('getRecordFromId')->willReturn($this->storedARecord());
        $this->recordManager->expects($this->once())->method('editRecord')->with(
            $this->anything(),
            false,
            $this->callback(fn($c) => $c['content'] === 'moved')
        )->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'moved', 3);

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'comment' => 'moved']]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUpdateWithAnEmptyCommentClearsIt(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->records->method('getRecordFromId')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', '', 3);

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'comment' => '']]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnEmptyUpdateCommentIsSyncedToTheCounterpartWhenSyncIsOn(): void
    {
        $this->config = new FakeConfiguration(['misc' => ['record_comments_sync' => true]]);
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->records->method('getRecordFromId')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->commentSync->expects($this->once())->method('updateRelatedRecordComments')
            ->with($this->anything(), $this->callback(fn($r) => $r['type'] === 'A'), '', $this->anything(), self::USER_ID);

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'comment' => '']]])->getStatusCode());
    }

    public function testAnUpdateCommentIsNotSyncedWhenSyncIsOff(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->records->method('getRecordFromId')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->commentSync->expects($this->never())->method('updateRelatedRecordComments');

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'comment' => 'x']]])->getStatusCode());
    }

    public function testACreateWhosePtrFailsStillSyncsItsComment(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->reverseCreator->method('createReverseRecord')
            ->willReturn(['success' => false, 'type' => 'error', 'message' => 'A PTR record already exists.']);
        $this->recordManagerService->expects($this->once())->method('syncCompanionComment')
            ->with(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 'note', $this->anything(), self::USER_ID);

        $response = $this->bulk(['operations' => [$this->createOperation(['comment' => 'note', 'create_ptr' => true])]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->decode($response)['data']['warnings']);
    }

    public function testAnUpdateWithoutACommentLeavesTheStoredOneAlone(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->expects($this->once())->method('editRecord')->with($this->anything(), false, null)->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->never())->method('updateCommentForRecord');

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'content' => '192.0.2.9']]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUpdateWithANonStringCommentIs400(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->expects($this->never())->method('editRecord');

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'comment' => 5]]]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreatePtrOnAnARecordCreatesTheReverseRecordAndFinalizesThatZoneOnce(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->reverseCreator->expects($this->exactly(2))->method('createReverseRecord')
            ->with('www.example.com', 'A', '192.0.2.1', self::ZONE_ID, 1800, 0, '', $this->anything(), false)
            ->willReturn(['success' => true, 'type' => 'success', 'message' => 'Reverse record added', 'zone_id' => 88]);
        $finalized = [];
        $this->recordManager->method('finalizeZone')->willReturnCallback(function (int $id, bool $bump) use (&$finalized): void {
            $finalized[] = [$id, $bump];
        });
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $bumped = [];
        $soa->method('updateSOASerial')->willReturnCallback(function (int $id) use (&$bumped): bool {
            $bumped[] = $id;
            return true;
        });

        $response = $this->bulk(['operations' => [
            $this->createOperation(['create_ptr' => true]),
            $this->createOperation(['create_ptr' => true]),
        ]], $soa);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['data']['warnings']);
        $this->assertSame([self::ZONE_ID, 88], $bumped);
        $this->assertSame([[self::ZONE_ID, false], [88, false]], $finalized);
    }

    public function testCreatePtrIsIgnoredForOtherTypes(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->reverseCreator->expects($this->never())->method('createReverseRecord');

        $response = $this->bulk(['operations' => [
            $this->createOperation(['type' => 'TXT', 'content' => 'hello', 'create_ptr' => true]),
        ]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreatePtrIsNotReadOnAnUpdate(): void
    {
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->reverseCreator->expects($this->never())->method('createReverseRecord');

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'content' => '192.0.2.9', 'create_ptr' => true]]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAFailedPtrIsAWarningAndTheRecordStillCounts(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->reverseCreator->method('createReverseRecord')
            ->willReturn(['success' => false, 'type' => 'error', 'message' => 'There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.']);
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, false);

        $response = $this->bulk(['operations' => [
            $this->createOperation(),
            $this->createOperation(['name' => 'www2', 'create_ptr' => true]),
        ]]);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['created']);
        $this->assertSame(0, $body['data']['failed']);
        $this->assertSame(
            ['Operation 1 (create): PTR record creation failed: There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.'],
            $body['data']['warnings']
        );
    }

    public function testAPerOperationCommentDoesNotSatisfyARequiredChangeReason(): void
    {
        $this->config = new FakeConfiguration(['logging' => ['require_change_comment' => true]]);
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['operations' => [$this->createOperation(['comment' => 'web server'])]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Field 'comment' is required: this installation requires a reason for every change", $this->messageOf($response));
    }

    public function testTheTopLevelReasonStillSatisfiesTheRequirement(): void
    {
        $this->config = new FakeConfiguration(['logging' => ['require_change_comment' => true]]);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $response = $this->bulk(['comment' => 'ticket-1', 'operations' => [$this->createOperation()]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function storedARecord(): array
    {
        return [
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.5',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ];
    }

    public function testANonSoaBatchBumpsTheSerialOnce(): void
    {
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);

        $response = $this->bulk(
            ['operations' => [$this->createOperation(), $this->createOperation(['name' => 'www2'])]],
            $soa
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUpdateBackendFaultIsReportedAs500(): void
    {
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.5',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::backendFailure('boom'));

        $response = $this->bulk(['operations' => [['action' => 'update', 'id' => 3, 'content' => '192.0.2.6']]]);

        // A plain Exception, not an ApiErrorException, so the generic 500 arm answers.
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Bulk operations failed', $this->messageOf($response));
    }

    private function transactionSpy(int $commits, int $rollbacks): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->exactly($commits))->method('commit')->willReturn(true);
        $db->expects($this->exactly($rollbacks))->method('rollBack')->willReturn(true);
        $this->txDb = $db;
    }

    public function testADryRunOnALocalDatabaseRollsBackAndWritesNothingAfterwards(): void
    {
        $this->transactionSpy(0, 1);
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $this->audit->expects($this->never())->method('logApiBulkRecords');
        $this->reverseCreator->method('createReverseRecord')
            ->willReturn(['success' => true, 'type' => 'success', 'message' => 'Reverse record added', 'zone_id' => 88]);

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->exactly(2))->method('updateSOASerial');

        $response = $this->bulk(['dry_run' => true, 'operations' => [
            $this->createOperation(['create_ptr' => true]),
            ['action' => 'update', 'id' => 3, 'content' => '192.0.2.9'],
            ['action' => 'delete', 'id' => 3],
        ]], $soa);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Bulk operations validated (dry run, nothing was written)', $this->messageOf($response));
        $this->assertTrue($body['data']['dry_run']);
        $this->assertSame([1, 1, 1, 0], [$body['data']['created'], $body['data']['updated'], $body['data']['deleted'], $body['data']['failed']]);
    }

    public function testADryRunAuditsNoPtrAndAWriteRunStillDoes(): void
    {
        $this->transactionSpy(0, 1);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));
        $this->reverseCreator->expects($this->once())->method('createReverseRecord')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), false, false, false)
            ->willReturn(['success' => true, 'type' => 'success', 'message' => 'ok', 'zone_id' => 88]);

        $response = $this->bulk(['dry_run' => true, 'operations' => [$this->createOperation(['create_ptr' => true])]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnOrdinaryRunCommitsAndHasNoDryRunFlag(): void
    {
        $this->transactionSpy(1, 0);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $response = $this->bulk(['dry_run' => false, 'operations' => [$this->createOperation()]]);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('dry_run', $body['data']);
        $this->assertSame('Bulk operations completed successfully', $this->messageOf($response));
    }

    public function testAFailingOperationInADryRunFailsLikeARealRun(): void
    {
        $this->transactionSpy(0, 1);
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(1));

        $response = $this->bulk(['dry_run' => true, 'operations' => [$this->createOperation(), ['action' => 'update']]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $body['data']['created']);
        $this->assertSame(1, $body['data']['failed']);
        $this->assertTrue($body['data']['dry_run']);
    }

    public function testANonBooleanDryRunIs400(): void
    {
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['dry_run' => 'maybe', 'operations' => [$this->createOperation()]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Field 'dry_run' must be a boolean", $this->messageOf($response));
    }

    public function testADryRunUnderChangeApprovalIsStillRefused(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);

        $response = $this->bulk(['dry_run' => true, 'operations' => [$this->createOperation()]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testADryRunStillNeedsTheReasonWhenTheInstallRequiresOne(): void
    {
        $this->config = new FakeConfiguration(['logging' => ['require_change_comment' => true]]);
        $this->recordManager->expects($this->never())->method('addRecordGetId');

        $response = $this->bulk(['dry_run' => true, 'operations' => [$this->createOperation()]]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAnApiBackendDryRunOnlyValidatesAndCallsNoWriteHelper(): void
    {
        $this->localTransactions = false;
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->expects($this->once())->method('addRecordGetId')->with(
            self::ZONE_ID,
            'www.example.com',
            'A',
            '192.0.2.1',
            3600,
            0,
            0,
            false,
            $this->anything(),
            true
        )->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->anything(), false, $this->anything(), true)->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('deleteRecord')
            ->with(3, false, true)->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $this->audit->expects($this->never())->method('logApiBulkRecords');
        $this->comments->expects($this->never())->method('createCommentForRecord');
        $this->comments->expects($this->never())->method('updateCommentForRecord');
        $this->recordManagerService->expects($this->never())->method('syncCompanionComment');
        $this->reverseCreator->expects($this->once())->method('createReverseRecord')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), false, false, true)
            ->willReturn(['success' => true, 'type' => 'warning', 'message' => 'Reverse record added. Warning: a PTR already exists', 'zone_id' => 88]);
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $response = $this->bulk(['dry_run' => true, 'operations' => [
            $this->createOperation(['create_ptr' => true, 'comment' => 'web']),
            ['action' => 'update', 'id' => 3, 'ttl' => 300, 'comment' => 'x'],
            ['action' => 'delete', 'id' => 3],
        ]], $soa);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 1, 1], [$body['data']['created'], $body['data']['updated'], $body['data']['deleted']]);
        $this->assertSame(['Operation 0 (create): Reverse record added. Warning: a PTR already exists'], $body['data']['warnings']);
    }

    public function testAnApiBackendDryRunReportsValidationErrorsAndPtrFailures(): void
    {
        $this->localTransactions = false;
        $this->recordManager->method('addRecordGetId')->willReturnOnConsecutiveCalls(
            RecordWriteResult::ok(),
            RecordWriteResult::failure('Invalid IPv4 address.')
        );
        $this->reverseCreator->method('createReverseRecord')
            ->willReturn(['success' => false, 'type' => 'error', 'message' => 'There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.']);

        $response = $this->bulk(['dry_run' => true, 'operations' => [
            $this->createOperation(['create_ptr' => true]),
            $this->createOperation(['name' => 'bad', 'content' => 'nope']),
        ]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['data']['created']);
        $this->assertSame(['Operation 1 (create): Invalid IPv4 address.'], $body['data']['errors']);
        $this->assertSame(['Operation 0 (create): PTR record creation failed: There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.'], $body['data']['warnings']);
        $this->assertTrue($body['data']['dry_run']);
    }

    public function testAnApiBackendDryRunCatchesADuplicateInsideTheBatch(): void
    {
        $this->localTransactions = false;
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok());

        $response = $this->bulk(['dry_run' => true, 'operations' => [$this->createOperation(), $this->createOperation()]]);
        $body = $this->decode($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['Operation 1 (create): A record with this hostname, type, and content already exists.'], $body['data']['errors']);
    }

    private function apiDryRunOfStoredRecord(): void
    {
        $this->localTransactions = false;
        $this->records->method('getRecordById')->willReturn($this->storedARecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return array{0: JsonResponse, 1: array<string, mixed>}
     */
    private function dryRun(array $operations): array
    {
        $response = $this->bulk(['dry_run' => true, 'operations' => $operations]);

        return [$response, $this->decode($response)];
    }

    public function testAnApiDryRunRefusesADeleteAfterAnUpdateThatReKeysTheRecord(): void
    {
        $this->apiDryRunOfStoredRecord();

        [$response, $body] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'content' => '192.0.2.9'],
            ['action' => 'delete', 'id' => 3],
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['Operation 1 (delete): Record not found in this zone'], $body['data']['errors']);
    }

    public function testAnApiDryRunRefusesASecondDeleteOfTheSameRecord(): void
    {
        $this->apiDryRunOfStoredRecord();

        [$response] = $this->dryRun([['action' => 'delete', 'id' => 3], ['action' => 'delete', 'id' => 3]]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnApiDryRunRefusesASecondUpdateAfterTheFirstReKeys(): void
    {
        $this->apiDryRunOfStoredRecord();

        [$response] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'content' => '192.0.2.9'],
            ['action' => 'update', 'id' => 3, 'ttl' => 60],
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnApiDryRunAllowsASecondUpdateWhenTheFirstKeepsTheId(): void
    {
        $this->apiDryRunOfStoredRecord();

        [$response, $body] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'ttl' => 60],
            ['action' => 'update', 'id' => 3, 'ttl' => 120],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['updated']);
    }

    public function testAnApiDryRunLetsACreateReuseARecordTheBatchDeleted(): void
    {
        $this->apiDryRunOfStoredRecord();
        $this->recordManager->method('addRecordGetId')
            ->willReturn(RecordWriteResult::failure('A record with this hostname, type, and content already exists.', Refusal::CONFLICT));

        [$response, $body] = $this->dryRun([
            ['action' => 'delete', 'id' => 3],
            $this->createOperation(['content' => '192.0.2.5']),
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['created']);
    }

    public function testAnApiDryRunLetsACreateReuseTheNameOfARenamedRecord(): void
    {
        $this->apiDryRunOfStoredRecord();
        $this->recordManager->method('addRecordGetId')
            ->willReturn(RecordWriteResult::failure('A record with this hostname, type, and content already exists.', Refusal::CONFLICT));

        [$response] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'name' => 'www2'],
            $this->createOperation(['content' => '192.0.2.5']),
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnApiDryRunRefusesASecondCreateOfAFreedRecord(): void
    {
        $this->apiDryRunOfStoredRecord();
        $this->recordManager->method('addRecordGetId')
            ->willReturn(RecordWriteResult::failure('A record with this hostname, type, and content already exists.', Refusal::CONFLICT));

        [$response, $body] = $this->dryRun([
            ['action' => 'delete', 'id' => 3],
            $this->createOperation(['content' => '192.0.2.5']),
            $this->createOperation(['content' => '192.0.2.5']),
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['Operation 2 (create): A record with this hostname, type, and content already exists'], $body['data']['errors']);
    }

    public function testAnApiDryRunRefusesACreateOfTheKeyAnEarlierRenameMovedTo(): void
    {
        $this->apiDryRunOfStoredRecord();
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok());

        [$response, $body] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'name' => 'www2'],
            $this->createOperation(['name' => 'www2', 'content' => '192.0.2.5']),
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['Operation 1 (create): A record with this hostname, type, and content already exists.'], $body['data']['errors']);
    }

    public function testAnApiDryRunAllowsADifferentCreateAfterARename(): void
    {
        $this->apiDryRunOfStoredRecord();
        $this->recordManager->method('addRecordGetId')->willReturn(RecordWriteResult::ok());

        [$response] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'name' => 'www2'],
            $this->createOperation(['name' => 'www2', 'content' => '192.0.2.77']),
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function storedCname(): array
    {
        return ['id' => 3, 'domain_id' => self::ZONE_ID, 'name' => 'x.example.com', 'type' => 'CNAME', 'content' => 'foo.example.com', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    public function testAnApiDryRunIgnoresATrailingDotWhenAnEarlierDeleteFreedTheRecord(): void
    {
        $this->localTransactions = false;
        $this->records->method('getRecordById')->willReturn($this->storedCname());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->method('addRecordGetId')
            ->willReturn(RecordWriteResult::failure('A record with this hostname, type, and content already exists.', Refusal::CONFLICT));

        [$response] = $this->dryRun([
            ['action' => 'delete', 'id' => 3],
            $this->createOperation(['name' => 'x', 'type' => 'CNAME', 'content' => 'foo.example.com.']),
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnApiDryRunDoesNotTreatAddingATrailingDotAsAReKey(): void
    {
        $this->localTransactions = false;
        $this->records->method('getRecordById')->willReturn($this->storedCname());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        [$response, $body] = $this->dryRun([
            ['action' => 'update', 'id' => 3, 'content' => 'foo.example.com.'],
            ['action' => 'update', 'id' => 3, 'ttl' => 60],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $body['data']['updated']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createOperation(array $overrides = []): array
    {
        return array_merge(['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600], $overrides);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulk(array $body, ?SOARecordManagerInterface $soaManager = null): JsonResponse
    {
        $controller = $this->bareController(ZonesRecordsBulkController::class);
        $this->injectBaseCollaborators($controller, 'POST');
        if ($this->config !== null) {
            $this->inject($controller, 'config', $this->config);
        }
        $this->inject($controller, 'request', Request::create(
            '/api/v2/zones/' . self::ZONE_ID . '/records/bulk',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string)json_encode($body)
        ));

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->audit);
        $factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $factory->method('recordCommentService')->willReturn($this->comments);
        $factory->method('recordCommentSyncService')->willReturn($this->commentSync);
        $factory->method('recordManagerService')->willReturn($this->recordManagerService);
        $factory->method('soaRecordManager')->willReturn($soaManager ?? $this->createMock(SOARecordManagerInterface::class));
        $factory->method('recordChangeLog')->willReturn(new RecordChangeLogger($this->createMock(PDO::class), $this->config ?? new FakeConfiguration(), StubActor::nobody()));

        $factory->method('transaction')->willReturn(new PdoTransaction($this->txDb ?? $this->stubDb()));
        $canonical = $this->createMock(DnsBackendProviderInterface::class);
        $canonical->method('getZoneIdByName')->willReturnCallback(
            fn(string $name): ?int => $name === self::ZONE_NAME ? $this->canonicalZoneId : null
        );
        $factory->method('dnsBackendProvider')->willReturn($canonical);

        $backend = $this->createMock(BackendCapabilitiesInterface::class);
        $backend->method('supportsLocalWriteTransaction')->willReturn($this->localTransactions);

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);
        $ttlResolver->method('resolvePtrTtl')->willReturn(1800);

        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'recordRepository', $this->records);
        $this->inject($controller, 'recordManager', $this->recordManager);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'backendProvider', $backend);
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', ['id' => $this->zoneIdParameter]);

        return $this->callHandler($controller, 'bulkRecordOperations');
    }
}
