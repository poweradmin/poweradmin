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
use Poweradmin\Application\Controller\Api\V2\ZonesController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization of GET /api/v2/zones and GET /api/v2/zones/{id}: how the
 * listing pages and narrows to an API key's zones, and the envelope a single
 * zone comes back in.
 */
class ZonesControllerReadTest extends V2ControllerTestCase
{

    private const USER_ID = 2;
    private const ZONE_ID = 61;

    private ApiPermissionService&MockObject $permissions;
    private ZoneRepositoryInterface&MockObject $zones;
    private ApiKeyScope $scope;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canViewZone')->willReturn(true);
    }

    public function testAnEmptyListStillCarriesPaginationMetadata(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn([]);
        $this->zones->method('getZoneCountFiltered')->willReturn(0);
        $this->zones->expects($this->never())->method('getAllZonesFiltered');

        $response = $this->listZones();
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['data']['zones']);
        $this->assertSame(['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1], $body['pagination']);
    }

    public function testWithoutPerPageEveryZoneIsReturnedAndNoPaginationBlockIsAdded(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->method('getZoneCountFiltered')->willReturn(2);
        $this->zones->expects($this->once())
            ->method('getAllZonesFiltered')
            ->with(null, null, null)
            ->willReturn([
                ['id' => 1, 'name' => 'a.example.com', 'type' => 'MASTER', 'created_at' => '2026-01-01 00:00:00'],
                ['id' => 2, 'name' => 'b.example.com'],
            ]);

        $response = $this->listZones();
        $body = $this->decode($response);

        $this->assertArrayNotHasKey('pagination', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertCount(2, $body['data']['zones']);
        // An absent type reads as MASTER and an absent canonical_id mirrors the id.
        $this->assertSame('MASTER', $body['data']['zones'][1]['type']);
        $this->assertSame(2, $body['data']['zones'][1]['canonical_id']);
        $this->assertNull($body['data']['zones'][1]['created_at']);
    }

    public function testZonesSerializeAsAJsonArrayNotAnObject(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->method('getZoneCountFiltered')->willReturn(1);
        $this->zones->method('getAllZonesFiltered')->willReturn([['id' => 1, 'name' => 'a.example.com']]);

        $this->assertStringContainsString('"zones":[{', (string)$this->listZones()->getContent());
    }

    public function testPaginationClampsThePageToOneAndComputesTheLastPage(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->method('getZoneCountFiltered')->willReturn(10);
        $this->zones->expects($this->once())
            ->method('getAllZonesFiltered')
            ->with(null, null, null, 0, 4)
            ->willReturn([]);

        $body = $this->decode($this->listZones(['per_page' => 4, 'page' => -3]));

        $this->assertSame(['current_page' => 1, 'per_page' => 4, 'total' => 10, 'last_page' => 3], $body['pagination']);
    }

    public function testPerPageIsCappedAtTheMaximumPageSize(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->method('getZoneCountFiltered')->willReturn(1);
        $this->zones->expects($this->once())
            ->method('getAllZonesFiltered')
            ->with(null, null, null, 0, 10000)
            ->willReturn([]);

        $body = $this->decode($this->listZones(['per_page' => 999999]));

        $this->assertSame(10000, $body['pagination']['per_page']);
    }

    public function testAZoneRestrictedKeyNarrowsTheVisibleSetRatherThanAnswering403(): void
    {
        $this->scope = new ApiKeyScope([2, 3], null, false);
        $this->permissions->method('getUserVisibleZoneIds')->willReturn([1, 2]);
        $this->zones->expects($this->once())
            ->method('getZoneCountFiltered')
            ->with([2], self::USER_ID, null)
            ->willReturn(0);

        $this->assertSame(200, $this->listZones()->getStatusCode());
    }

    public function testAZoneRestrictedKeyForAUeberuserUsesTheKeysZonesVerbatim(): void
    {
        // A null visible set means "all zones", so the key's list becomes the set.
        $this->scope = new ApiKeyScope([5], null, false);
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->expects($this->once())
            ->method('getZoneCountFiltered')
            ->with([5], null, null)
            ->willReturn(0);

        $this->assertSame(200, $this->listZones()->getStatusCode());
    }

    public function testTheNameFilterIsPassedThroughToTheRepository(): void
    {
        $this->permissions->method('getUserVisibleZoneIds')->willReturn(null);
        $this->zones->expects($this->once())
            ->method('getZoneCountFiltered')
            ->with(null, null, 'example')
            ->willReturn(0);

        $this->assertSame(200, $this->listZones(['name' => 'example'])->getStatusCode());
    }

    public function testFetchingAZoneOutsideTheKeysScopeIs403(): void
    {
        $this->scope = new ApiKeyScope([999], null, false);
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->getZone();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: this API key does not have access to the requested zone', $this->messageOf($response));
    }

    public function testAMissingZoneIs404BeforeThePermissionCheck(): void
    {
        $this->zones->method('getZoneById')->willReturn(null);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->expects($this->never())->method('canViewZone');

        $response = $this->getZone();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testAZoneTheCallerMayNotViewIs403(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canViewZone')->willReturn(false);

        $response = $this->getZone();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to view this zone', $this->messageOf($response));
    }

    public function testEmptyZoneAttributesAreReportedAsNull(): void
    {
        $this->zones->method('getZoneById')->willReturn([
            'id' => self::ZONE_ID,
            'name' => 'example.com',
            'type' => 'MASTER',
            'account' => '',
            'master' => '',
        ]);
        $this->zones->method('getZoneComment')->willReturn('');

        $zone = $this->decode($this->getZone())['data']['zone'];

        $this->assertNull($zone['masters']);
        $this->assertNull($zone['account']);
        $this->assertNull($zone['description']);
        $this->assertNull($zone['created_at']);
        $this->assertSame(self::ZONE_ID, $zone['id']);
    }

    public function testAPopulatedZoneCarriesItsMastersAccountAndDescription(): void
    {
        $this->zones->method('getZoneById')->willReturn([
            'id' => self::ZONE_ID,
            'name' => 'example.com',
            'type' => 'SLAVE',
            'account' => 'team-dns',
            'master' => '192.0.2.1,192.0.2.2',
            'created_at' => '2026-02-02 10:00:00',
        ]);
        $this->zones->method('getZoneComment')->willReturn('the corporate zone');

        $zone = $this->decode($this->getZone())['data']['zone'];

        $this->assertSame('192.0.2.1,192.0.2.2', $zone['masters']);
        $this->assertSame('team-dns', $zone['account']);
        $this->assertSame('the corporate zone', $zone['description']);
        $this->assertSame('SLAVE', $zone['type']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listZones(array $query = []): JsonResponse
    {
        return $this->invokeHandler('listZones', $query, []);
    }

    private function getZone(): JsonResponse
    {
        return $this->invokeHandler('getZone', [], ['id' => self::ZONE_ID]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $pathParameters
     */
    private function invokeHandler(string $handler, array $query, array $pathParameters): JsonResponse
    {
        $controller = $this->bareController(ZonesController::class);
        $this->injectBaseCollaborators($controller, 'GET');
        $this->inject($controller, 'request', Request::create('/api/v2/zones', 'GET', $query));

        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'domainRepository', $this->createMock(DomainRepositoryInterface::class));
        $this->inject($controller, 'zoneManagementService', $this->createMock(ZoneManagementService::class));
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'ipAddressValidator', new IPAddressValidator());
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', $pathParameters);

        return $this->callHandler($controller, $handler);
    }
}
