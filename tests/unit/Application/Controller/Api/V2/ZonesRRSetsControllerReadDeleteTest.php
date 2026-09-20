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
use Poweradmin\Application\Controller\Api\V2\ZonesRRSetsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Characterization of the read and delete halves of ZonesRRSetsController: how
 * records are grouped and serialized, and the gate order a delete walks.
 */
class ZonesRRSetsControllerReadDeleteTest extends V2ControllerTestCase
{

    private const USER_ID = 3;
    private const ZONE_ID = 11;
    private const ZONE_NAME = 'example.com';

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;
    private RecordManagerInterface&MockObject $recordManager;

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ApiKeyScope $scope;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canViewZone')->willReturn(true);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('canEditZoneRecord')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
    }

    public function testListingAMissingZoneIs404(): void
    {
        $this->zoneRow = null;

        $response = $this->invokeHandler('listRRSets');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testListingWithoutViewPermissionIs403AfterTheZoneWasFound(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canViewZone')->willReturn(false);

        $response = $this->invokeHandler('listRRSets');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to view this zone', $this->messageOf($response));
    }

    public function testRecordsAreGroupedByNameAndTypeAndSerializeAsAJsonArray(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ['id' => 3, 'name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mx.example.com', 'ttl' => 7200, 'prio' => 10, 'disabled' => 1],
        ]);

        $response = $this->invokeHandler('listRRSets');
        $rrsets = $this->decode($response)['data']['rrsets'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"rrsets":[', (string)$response->getContent());
        $this->assertCount(2, $rrsets);
        $this->assertSame([0, 1], array_keys($rrsets));
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_column($rrsets[0]['records'], 'content'));
        $this->assertSame(10, $rrsets[1]['records'][0]['priority']);
        $this->assertTrue($rrsets[1]['records'][0]['disabled']);
    }

    public function testAnEntRowWithoutTypeOrNameIsSkipped(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'sub.example.com', 'type' => '', 'content' => '', 'ttl' => 3600],
            ['id' => 2, 'name' => '', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
            ['id' => 3, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
        ]);

        $rrsets = $this->decode($this->invokeHandler('listRRSets'))['data']['rrsets'];

        $this->assertCount(1, $rrsets);
        $this->assertSame('www.example.com', $rrsets[0]['name']);
    }

    public function testTheGroupKeepsTheLowestTtlWhenMembersDisagree(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
            ['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 60],
        ]);

        $rrsets = $this->decode($this->invokeHandler('listRRSets'))['data']['rrsets'];

        $this->assertSame(60, $rrsets[0]['ttl']);
    }

    public function testASingleStringTxtRecordIsUnquotedInTheListing(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'example.com', 'type' => 'TXT', 'content' => '"v=spf1 -all"', 'ttl' => 3600],
            ['id' => 2, 'name' => 'long.example.com', 'type' => 'TXT', 'content' => '"part1" "part2"', 'ttl' => 3600],
        ]);

        $rrsets = $this->decode($this->invokeHandler('listRRSets'))['data']['rrsets'];

        $this->assertSame('v=spf1 -all', $rrsets[0]['records'][0]['content']);
        // A multi-string TXT value keeps its quoting: the parts must stay distinct.
        $this->assertSame('"part1" "part2"', $rrsets[1]['records'][0]['content']);
    }

    public function testAPostgresBooleanStringBecomesATrueDisabledFlag(): void
    {
        $this->records->method('getRecordsByDomainId')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'disabled' => 't'],
        ]);

        $rrsets = $this->decode($this->invokeHandler('listRRSets'))['data']['rrsets'];

        $this->assertTrue($rrsets[0]['records'][0]['disabled']);
    }

    public function testAnUnknownRRSetIs404(): void
    {
        $this->records->method('getRRSetRecords')->willReturn([]);

        $response = $this->invokeHandler('getRRSet', ['name' => 'www', 'type' => 'a']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('RRSet not found', $this->messageOf($response));
    }

    public function testAFetchedRRSetStripsTheZoneSuffixFromTheName(): void
    {
        $this->records->expects($this->once())
            ->method('getRRSetRecords')
            ->with(self::ZONE_ID, 'www.example.com', 'A')
            ->willReturn([
                ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0],
            ]);

        $response = $this->invokeHandler('getRRSet', ['name' => 'www', 'type' => 'a']);
        $rrset = $this->decode($response)['data']['rrset'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('www', $rrset['name']);
        $this->assertSame('A', $rrset['type']);
        $this->assertSame(3600, $rrset['ttl']);
        $this->assertFalse($rrset['records'][0]['disabled']);
    }

    public function testDeletingWithAnOutOfScopeApiKeyIs403BeforeTheZoneLookup(): void
    {
        $this->scope = new ApiKeyScope([777], null, false);
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'A']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeletingAnRRSetInAMissingZoneIs404(): void
    {
        $this->zoneRow = null;

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'A']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testDeletingARecordTypeTheCallerMayNotTouchIs403WithTheDeleteWording(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->method('canEditZoneRecord')->willReturn(false);

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'NS']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to delete this record type', $this->messageOf($response));
    }

    public function testDeletingAnRRSetThatHoldsNoRecordsIs404(): void
    {
        $this->records->method('getRRSetRecords')->willReturn([]);

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'A']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('RRSet not found', $this->messageOf($response));
    }

    public function testASuccessfulDeleteAnswers204WithAnEmptyBody(): void
    {
        $this->records->method('getRRSetRecords')->willReturn([
            ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 60],
            ['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 60],
        ]);
        $this->recordManager->expects($this->exactly(2))->method('deleteRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'A']);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string)$response->getContent());
    }

    public function testAFailedRowDeleteRollsBackAndReports500(): void
    {
        $this->records->method('getRRSetRecords')->willReturn([
            ['id' => 9, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 60],
        ]);
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::backendFailure('nope'));
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $response = $this->invokeHandler('deleteRRSet', ['name' => 'www', 'type' => 'A']);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Failed to delete record with ID 9', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed> $extraPathParameters
     */
    private function invokeHandler(string $handler, array $extraPathParameters = []): JsonResponse
    {
        $controller = $this->bareController(ZonesRRSetsController::class);
        $this->injectBaseCollaborators($controller, $handler === 'deleteRRSet' ? 'DELETE' : 'GET');

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn(self::ZONE_NAME);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($domains);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('soaRecordManager')->willReturn($this->createMock(SOARecordManagerInterface::class));

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);

        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'recordRepository', $this->records);
        $this->inject($controller, 'recordManager', $this->recordManager);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'backendProvider', $this->createMock(DnsBackendProviderInterface::class));
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', array_merge(['id' => self::ZONE_ID], $extraPathParameters));

        return $this->callHandler($controller, $handler);
    }
}
