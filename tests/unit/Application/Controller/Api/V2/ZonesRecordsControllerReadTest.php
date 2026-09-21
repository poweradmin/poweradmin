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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Application\Controller\Api\V2\ZonesRRSetsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization of the record listing and single-record fetch, plus the API
 * key operation sets the write endpoints declare for their HTTP methods.
 */
class ZonesRecordsControllerReadTest extends V2ControllerTestCase
{

    private const USER_ID = 5;
    private const ZONE_ID = 81;
    private const ZONE_NAME = 'example.com';

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ApiKeyScope $scope;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canViewZone')->willReturn(true);
    }

    public function testListingRecordsOfAMissingZoneIs404(): void
    {
        $this->zoneRow = null;

        $response = $this->invokeHandler('listRecords');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testListingWithoutViewPermissionIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canViewZone')->willReturn(false);

        $response = $this->invokeHandler('listRecords');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to view this zone', $this->messageOf($response));
    }

    public function testTheTypeQueryFilterReachesTheRepository(): void
    {
        $this->records->expects($this->once())
            ->method('getRecordsByDomainId')
            ->with(self::ZONE_ID, 'MX')
            ->willReturn([]);

        $this->assertSame(200, $this->invokeHandler('listRecords', [], ['type' => 'MX'])->getStatusCode());
    }

    public function testDroppedEntRowsLeaveNoGapsSoRecordsStaySerializedAsAnArray(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'ent.example.com', 'type' => null, 'content' => '', 'ttl' => 3600],
            ['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
        ]);

        $response = $this->invokeHandler('listRecords');
        $records = $this->decode($response)['data']['records'];

        $this->assertStringContainsString('"records":[{', (string)$response->getContent());
        $this->assertSame([0], array_keys($records));
        $this->assertSame(2, $records[0]['id']);
    }

    public function testAListedRecordWithoutAPriorityReportsZero(): void
    {
        // The listing and the single-record fetch agree on 0 for a missing prio
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
        ]);

        $record = $this->decode($this->invokeHandler('listRecords'))['data']['records'][0];

        $this->assertSame(0, $record['priority']);
        // Absent auth reads as authoritative, absent disabled as enabled.
        $this->assertTrue($record['auth']);
        $this->assertFalse($record['disabled']);
    }

    public function testAListedRecordKeepsItsFullyQualifiedName(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'TXT', 'content' => '"hello"', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'auth' => 0],
        ]);

        $record = $this->decode($this->invokeHandler('listRecords'))['data']['records'][0];

        $this->assertSame('www.example.com', $record['name']);
        $this->assertSame('hello', $record['content']);
        $this->assertFalse($record['auth']);
    }

    public function testAnApiBackendRecordIdStaysAString(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 'pdns:abc', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
        ]);

        $record = $this->decode($this->invokeHandler('listRecords'))['data']['records'][0];

        $this->assertSame('pdns:abc', $record['id']);
    }

    public function testANumericStringIdIsNormalisedToAnInteger(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => '17', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
        ]);

        $record = $this->decode($this->invokeHandler('listRecords'))['data']['records'][0];

        $this->assertSame(17, $record['id']);
    }

    public function testFetchingARecordFromAnotherZoneIs404(): void
    {
        $this->records->method('getRecordById')->willReturn(['id' => 3, 'domain_id' => 999, 'type' => 'A']);

        $response = $this->invokeHandler('getRecord', ['record_id' => 3]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Record not found in this zone', $this->messageOf($response));
    }

    public function testAFetchedRecordHasItsZoneSuffixStrippedAndCarriesTheZoneId(): void
    {
        $this->records->method('getRecordById')->willReturn([
            'id' => 3,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
        ]);

        $response = $this->invokeHandler('getRecord', ['record_id' => 3]);
        $record = $this->decode($response)['data']['record'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('www', $record['name']);
        $this->assertSame(self::ZONE_ID, $record['zone_id']);
        // Unlike the listing, a missing prio reads as 0 here.
        $this->assertSame(0, $record['priority']);
    }

    public function testAnRRSetWriteRequiresBothTheCreateAndUpdateOperations(): void
    {
        // POST/PUT/PATCH all upsert, so the key must permit both operations.
        $this->assertSame([ApiKeyScope::OP_CREATE, ApiKeyScope::OP_UPDATE], $this->rrsetOperationsFor('PUT'));
        $this->assertSame([ApiKeyScope::OP_CREATE, ApiKeyScope::OP_UPDATE], $this->rrsetOperationsFor('POST'));
        $this->assertSame([ApiKeyScope::OP_CREATE, ApiKeyScope::OP_UPDATE], $this->rrsetOperationsFor('PATCH'));
        $this->assertSame([ApiKeyScope::OP_VIEW], $this->rrsetOperationsFor('GET'));
        $this->assertSame([ApiKeyScope::OP_VIEW], $this->rrsetOperationsFor('HEAD'));
        $this->assertSame([ApiKeyScope::OP_DELETE], $this->rrsetOperationsFor('DELETE'));
    }

    public function testTheBulkEndpointOptsOutOfTheCentralOperationCheck(): void
    {
        $controller = $this->bareController(ZonesRecordsBulkController::class);
        $this->injectBaseCollaborators($controller, 'POST');

        $method = new ReflectionMethod($controller, 'requiredApiKeyOperations');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($controller));
    }

    /**
     * @return array<int, string>
     */
    private function rrsetOperationsFor(string $httpMethod): array
    {
        $controller = $this->bareController(ZonesRRSetsController::class);
        $this->injectBaseCollaborators($controller, 'GET');
        $this->inject($controller, 'request', Request::create('/api/v2/zones/1/rrsets', $httpMethod));

        $method = new ReflectionMethod($controller, 'requiredApiKeyOperations');
        $method->setAccessible(true);

        /** @var array<int, string> $operations */
        $operations = $method->invoke($controller);

        return $operations;
    }

    /**
     * @param array<string, mixed> $extraPathParameters
     * @param array<string, mixed> $query
     */
    private function invokeHandler(string $handler, array $extraPathParameters = [], array $query = []): JsonResponse
    {
        $controller = $this->bareController(ZonesRecordsController::class);
        $this->injectBaseCollaborators($controller, 'GET');
        $this->inject($controller, 'request', Request::create('/api/v2/zones/' . self::ZONE_ID . '/records', 'GET', $query));

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn(self::ZONE_NAME);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($domains);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);

        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'recordRepository', $this->records);
        $this->inject($controller, 'recordManager', $this->createMock(RecordManagerInterface::class));
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', array_merge(['id' => self::ZONE_ID], $extraPathParameters));

        return $this->callHandler($controller, $handler);
    }
}
