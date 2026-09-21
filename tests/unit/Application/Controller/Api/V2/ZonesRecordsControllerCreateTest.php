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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\RecordAddResult;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization of POST /api/v2/zones/{id}/records: the gate order, every
 * input refusal, and the shape of the 201 envelope.
 */
class ZonesRecordsControllerCreateTest extends V2ControllerTestCase
{

    private const USER_ID = 4;
    private const ZONE_ID = 21;
    private const ZONE_NAME = 'example.com';

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;
    private RecordAddService&MockObject $addService;

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ?string $zoneName = self::ZONE_NAME;
    private ApiKeyScope $scope;
    private int $zoneIdParameter = self::ZONE_ID;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->addService = $this->createMock(RecordAddService::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('canEditZoneRecord')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
    }

    public function testANonPositiveZoneIdIsRefusedFirst(): void
    {
        $this->zoneIdParameter = 0;
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->create($this->validBody());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID is required', $this->messageOf($response));
    }

    public function testAnOutOfScopeApiKeyIsRefusedBeforeTheZoneLookup(): void
    {
        $this->scope = new ApiKeyScope([1], null, false);
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->create($this->validBody());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: this API key does not have access to the requested zone', $this->messageOf($response));
    }

    public function testAMissingZoneIs404BeforeThePermissionCheck(): void
    {
        $this->zoneRow = null;
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->expects($this->never())->method('canEditZoneContent');

        $response = $this->create($this->validBody());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testAZoneWithoutEditPermissionIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(false);

        $response = $this->create($this->validBody());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this zone', $this->messageOf($response));
    }

    public function testARequestModeCallerIsPointedAtChangeRequests(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);

        $response = $this->create($this->validBody());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Changes to this zone require approval; create a change request instead', $this->messageOf($response));
    }

    public function testAScalarJsonBodyIs400(): void
    {
        $response = $this->createRaw('42');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON in request body', $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingOrBlankFieldProvider(): array
    {
        return [
            'absent name' => [['type' => 'A', 'content' => '192.0.2.1'], "Field 'name' is required"],
            'blank name' => [['name' => '   ', 'type' => 'A', 'content' => '192.0.2.1'], "Field 'name' is required"],
            'empty name' => [['name' => '', 'type' => 'A', 'content' => '192.0.2.1'], "Field 'name' is required"],
            'absent type' => [['name' => 'www', 'content' => '192.0.2.1'], "Field 'type' is required"],
            'blank content' => [['name' => 'www', 'type' => 'A', 'content' => ' '], "Field 'content' is required"],
            // A non-string value reads as missing, not as a type error.
            'numeric type' => [['name' => 'www', 'type' => 5, 'content' => '192.0.2.1'], "Field 'type' is required"],
            'array content' => [['name' => 'www', 'type' => 'A', 'content' => ['192.0.2.1']], "Field 'content' is required"],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('missingOrBlankFieldProvider')]
    public function testAMissingOrBlankRequiredFieldIs400(array $body, string $message): void
    {
        $response = $this->create($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    public function testAZoneWithoutAResolvableNameIs404(): void
    {
        $this->zoneName = null;

        $response = $this->create($this->validBody());

        $this->assertSame(404, $response->getStatusCode());
        // Same wording as every other zone 404 on this controller
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nonNumericNumberProvider(): array
    {
        return [
            'ttl' => [['ttl' => 'soon']],
            'priority' => [['priority' => 'high']],
            'disabled' => [['disabled' => 'maybe']],
            'disabled as array' => [['disabled' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('nonNumericNumberProvider')]
    public function testNonNumericTtlPriorityOrDisabledIs400(array $overrides): void
    {
        $response = $this->create(array_merge($this->validBody(), $overrides));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Fields ttl, priority, and disabled must be numeric', $this->messageOf($response));
    }

    public function testANegativeTtlIs400(): void
    {
        $response = $this->create(array_merge($this->validBody(), ['ttl' => -1]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('TTL must not be negative', $this->messageOf($response));
    }

    public function testAZeroTtlIsAcceptedAsDoNotCache(): void
    {
        // TTLValidator allows 0, so the API must not refuse it either; assert the
        // value reaches the write rather than merely that the request is not a 400
        $this->addService->expects($this->once())
            ->method('add')
            ->with(
                self::ZONE_ID,
                self::ZONE_NAME,
                'www.example.com',
                'A',
                '192.0.2.1',
                0,
                0,
                '',
                self::USER_ID,
                'apiuser',
                '',
                0
            )
            ->willReturn(new RecordAddResult(RecordWriteResult::ok(99)));

        $response = $this->create(array_merge($this->validBody(), ['ttl' => 0]));

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testADisabledValueOtherThanZeroOrOneIs400(): void
    {
        $response = $this->create(array_merge($this->validBody(), ['disabled' => 2]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Disabled field must be 0 or 1', $this->messageOf($response));
    }

    public function testABooleanDisabledIsAccepted(): void
    {
        $this->addService->expects($this->once())
            ->method('add')
            ->with(
                self::ZONE_ID,
                self::ZONE_NAME,
                'www.example.com',
                'A',
                '192.0.2.1',
                3600,
                0,
                '',
                self::USER_ID,
                'apiuser',
                '',
                1
            )
            ->willReturn(new RecordAddResult(RecordWriteResult::ok(99)));

        $response = $this->create(array_merge($this->validBody(), ['disabled' => true]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['data']['record']['disabled']);
    }

    public function testARecordTypeTheCallerMayNotManageIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'NS', 'MASTER', 'www.example.com', self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->create(array_merge($this->validBody(), ['type' => 'ns']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this record type', $this->messageOf($response));
    }

    public function testADuplicateIsReportedAs409WithTheContractWording(): void
    {
        $this->addService->method('add')->willReturn(
            RecordAddResult::refused(RecordWriteResult::failure('whatever the manager said', 409))
        );

        $response = $this->create($this->validBody());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('A record with this hostname, type, and content already exists', $this->messageOf($response));
    }

    public function testABackendFaultIsReportedAs500WithTheGenericWording(): void
    {
        $this->addService->method('add')->willReturn(
            RecordAddResult::refused(RecordWriteResult::backendFailure('connection reset'))
        );

        $response = $this->create($this->validBody());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to create record', $this->messageOf($response));
    }

    public function testAnyOtherRefusalCarriesTheManagersOwnReason(): void
    {
        $this->addService->method('add')->willReturn(
            RecordAddResult::refused(RecordWriteResult::failure('Invalid IPv4 address', 400))
        );

        $response = $this->create($this->validBody());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid IPv4 address', $this->messageOf($response));
    }

    public function testASuccessfulCreateEchoesTheStoredRecord(): void
    {
        $this->addService->method('add')->willReturn(new RecordAddResult(RecordWriteResult::ok(77)));
        $this->records->method('getRecordById')->with(77)->willReturn([
            'id' => 77,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 1800,
            'prio' => 0,
        ]);

        $response = $this->create($this->validBody());
        $record = $this->decode($response)['data']['record'];

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Record created successfully', $this->messageOf($response));
        $this->assertSame(77, $record['id']);
        $this->assertSame('www', $record['name']);
        // The stored TTL wins over the submitted one.
        $this->assertSame(1800, $record['ttl']);
        $this->assertTrue($record['auth']);
        $this->assertFalse($record['ptr_created']);
    }

    public function testASingleStringTxtValueIsQuotedOnTheWayInAndUnquotedOnTheWayOut(): void
    {
        $this->addService->expects($this->once())
            ->method('add')
            ->with(self::ZONE_ID, self::ZONE_NAME, self::ZONE_NAME, 'TXT', '"v=spf1 -all"', 3600, 0, '', self::USER_ID, 'apiuser', '', 0)
            ->willReturn(new RecordAddResult(RecordWriteResult::ok(5)));
        $this->records->method('getRecordById')->willReturn([
            'id' => 5,
            'domain_id' => self::ZONE_ID,
            'name' => self::ZONE_NAME,
            'type' => 'TXT',
            'content' => '"v=spf1 -all"',
            'ttl' => 3600,
        ]);

        $response = $this->create(['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 -all', 'ttl' => 3600]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('v=spf1 -all', $this->decode($response)['data']['record']['content']);
    }

    public function testAFailedCompanionPtrStillCreatesTheRecordAndSaysSo(): void
    {
        $this->addService->method('add')->willReturn(
            new RecordAddResult(RecordWriteResult::ok(8), RecordAddResult::COMPANION_PTR, false, false, 'no reverse zone')
        );

        $response = $this->create(array_merge($this->validBody(), ['create_ptr' => true]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Record created successfully PTR record creation failed: no reverse zone', $this->messageOf($response));
        $this->assertFalse($this->decode($response)['data']['record']['ptr_created']);
    }

    public function testACreatedCompanionPtrIsReported(): void
    {
        $this->addService->method('add')->willReturn(
            new RecordAddResult(RecordWriteResult::ok(8), RecordAddResult::COMPANION_PTR, true)
        );

        $response = $this->create(array_merge($this->validBody(), ['create_ptr' => true]));

        $this->assertSame('Record created successfully PTR record created successfully.', $this->messageOf($response));
        $this->assertTrue($this->decode($response)['data']['record']['ptr_created']);
    }

    public function testCreatePtrIsIgnoredForANonAddressType(): void
    {
        // The companion is only requested for A/AAAA; a CNAME silently drops it.
        $this->addService->expects($this->once())
            ->method('add')
            ->with(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'CNAME', 'target.example.net', 3600, 0, '', self::USER_ID, 'apiuser', '', 0)
            ->willReturn(new RecordAddResult(RecordWriteResult::ok(3)));

        $response = $this->create(['name' => 'www', 'type' => 'CNAME', 'content' => 'target.example.net', 'ttl' => 3600, 'create_ptr' => true]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringNotContainsString('PTR', $this->messageOf($response));
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body): JsonResponse
    {
        return $this->createRaw((string)json_encode($body));
    }

    private function createRaw(string $rawBody): JsonResponse
    {
        $controller = $this->bareController(ZonesRecordsController::class);
        $this->injectBaseCollaborators($controller, 'POST');
        $this->inject($controller, 'request', Request::create(
            '/api/v2/zones/' . self::ZONE_ID . '/records',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        ));

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn($this->zoneName);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($domains);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('recordAddService')->willReturn($this->addService);
        $factory->method('userRepository')->willReturn($this->stubUsers());

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
        $this->inject($controller, 'pathParameters', ['id' => $this->zoneIdParameter]);

        return $this->callHandler($controller, 'createRecord');
    }
}
