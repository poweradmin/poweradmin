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
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\BackendCapabilitiesInterface;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

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

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ApiKeyScope $scope;
    private int $zoneIdParameter = self::ZONE_ID;
    private bool $localTransactions = true;

    protected function setUp(): void
    {
        RecordChangeLogger::resetChangesetScope();

        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
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
            ['total_operations' => 3, 'created' => 1, 'updated' => 1, 'deleted' => 1, 'failed' => 0, 'errors' => []],
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
        $this->assertSame('Bulk operations failed: Failed to update record', $this->messageOf($response));
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
        $factory->method('soaRecordManager')->willReturn($soaManager ?? $this->createMock(SOARecordManagerInterface::class));
        $factory->method('recordChangeLog')->willReturn(new RecordChangeLogger($this->createMock(PDO::class), new FakeConfiguration()));

        $backend = $this->createMock(BackendCapabilitiesInterface::class);
        $backend->method('supportsLocalWriteTransaction')->willReturn($this->localTransactions);

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);

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
