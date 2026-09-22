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
use Poweradmin\Application\Controller\Api\V2\ZonesController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Characterization of POST, PUT and DELETE on /api/v2/zones: the deliberate
 * 404-before-403 ordering, the split between metadata and description
 * permissions on an update, and the input refusals a create answers with.
 */
class ZonesControllerWriteTest extends V2ControllerTestCase
{

    private const USER_ID = 8;
    private const ZONE_ID = 71;

    private ApiPermissionService&MockObject $permissions;
    private ZoneRepositoryInterface&MockObject $zones;
    private DomainRepositoryInterface&MockObject $domains;
    private ZoneManagementService&MockObject $zoneManagement;
    private ApiKeyScope $scope;
    private int $zoneIdParameter = self::ZONE_ID;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->zoneManagement = $this->createMock(ZoneManagementService::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->domains->method('zoneIdExists')->willReturn(true);
        $this->permissions->method('canEditZoneMeta')->willReturn(true);
        $this->permissions->method('hasZoneContentEditPermission')->willReturn(true);
        $this->permissions->method('canCreateZone')->willReturn(true);
        $this->permissions->method('canDeleteZone')->willReturn(true);
        $this->permissions->method('zoneDeleteRequiresApproval')->willReturn(false);
    }

    public function testAZoneRestrictedKeyMayNotCreateZones(): void
    {
        $this->scope = new ApiKeyScope([1], null, false);
        $this->zoneManagement->expects($this->never())->method('createZone');

        $response = $this->invokeHandler('createZone', 'POST', ['name' => 'example.com']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Forbidden: this API key is restricted to specific zones and cannot create new zones',
            $this->messageOf($response)
        );
    }

    public function testAScalarBodyOnCreateIs400(): void
    {
        $response = $this->invokeRaw('createZone', 'POST', '"example.com"');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON in request body', $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedCreateFieldProvider(): array
    {
        return [
            'array name' => [['name' => ['example.com']]],
            'integer type' => [['name' => 'example.com', 'type' => 7]],
            'array description' => [['name' => 'example.com', 'description' => []]],
            'integer account' => [['name' => 'example.com', 'account' => 5]],
            'unparseable enable_dnssec' => [['name' => 'example.com', 'enable_dnssec' => 'yes please']],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedCreateFieldProvider')]
    public function testAMalformedCreateFieldIs400(array $body): void
    {
        $response = $this->invokeHandler('createZone', 'POST', $body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
    }

    public function testANonNumericTemplateIs400(): void
    {
        $response = $this->invokeHandler('createZone', 'POST', ['name' => 'example.com', 'template' => 'corporate']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Template must be a numeric ID', $this->messageOf($response));
    }

    public function testCreatingAZoneKindTheCallerMayNotCreateIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->expects($this->once())->method('canCreateZone')->with(self::USER_ID, 'SLAVE')->willReturn(false);

        $response = $this->invokeHandler('createZone', 'POST', ['name' => 'example.com', 'type' => 'slave']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to create zones of this type', $this->messageOf($response));
    }

    public function testANonPositiveZoneIdIsRefusedOnUpdate(): void
    {
        $this->zoneIdParameter = 0;
        $this->domains->expects($this->never())->method('zoneIdExists');

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => 'x']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID is required', $this->messageOf($response));
    }

    public function testUpdatingAMissingZoneIs404BeforeAnyPermissionCheck(): void
    {
        // The deliberate 404-before-403 order: existence is confirmed first so the
        // answer matches getZone()'s.
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('zoneIdExists')->willReturn(false);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->expects($this->never())->method('canEditZoneMeta');

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => 'x']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testACallerWithNeitherEditPermissionIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneMeta')->willReturn(false);
        $this->permissions->method('hasZoneContentEditPermission')->willReturn(false);

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => 'x']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this zone', $this->messageOf($response));
    }

    public function testAnUnknownZoneTypeIs400(): void
    {
        $response = $this->invokeHandler('updateZone', 'PUT', ['type' => 'PRODUCER']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid zone type. Must be one of: MASTER, SLAVE, NATIVE', $this->messageOf($response));
    }

    public function testAMalformedMasterListIs400(): void
    {
        $response = $this->invokeHandler('updateZone', 'PUT', ['master' => 'not-an-ip']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringStartsWith('Invalid master servers format:', $this->messageOf($response));
    }

    public function testABodyWithNoRecognisedFieldIs400(): void
    {
        $response = $this->invokeHandler('updateZone', 'PUT', ['nickname' => 'corp']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No valid fields provided for update', $this->messageOf($response));
    }

    public function testResendingTheStoredValuesLeavesNothingToUpdate(): void
    {
        // Clients resend the whole zone; values equal to the stored ones are dropped,
        // and once every field is dropped there is nothing left to write.
        $this->zones->method('getZoneById')->willReturn([
            'id' => self::ZONE_ID,
            'name' => 'example.com.',
            'type' => 'master',
            'master' => '192.0.2.1, 192.0.2.2',
        ]);
        $this->zoneManagement->expects($this->never())->method('updateZone');

        $response = $this->invokeHandler('updateZone', 'PUT', [
            'name' => 'example.com',
            'type' => 'MASTER',
            'master' => '192.0.2.1,192.0.2.2',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Zone updated successfully', $this->messageOf($response));
    }

    public function testConvertingToSlaveWithoutAMasterIs400(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);

        $response = $this->invokeHandler('updateZone', 'PUT', ['type' => 'SLAVE']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Master IP address is required for SLAVE zones', $this->messageOf($response));
    }

    public function testMetadataChangesNeedTheMetadataPermissionNotJustContentEdit(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneMeta')->willReturn(false);
        $this->permissions->method('hasZoneContentEditPermission')->willReturn(true);
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);

        $response = $this->invokeHandler('updateZone', 'PUT', ['name' => 'renamed.example.com']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame("You do not have permission to edit this zone's settings", $this->messageOf($response));
    }

    public function testADescriptionChangeNeedsTheContentEditPermission(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneMeta')->willReturn(true);
        $this->permissions->method('hasZoneContentEditPermission')->willReturn(false);
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);
        $this->zones->expects($this->never())->method('updateZoneComment');

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => 'new text']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this zone', $this->messageOf($response));
    }

    public function testConvertingAZoneNeedsTheCreatePermissionForTheTargetKind(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneMeta')->willReturn(true);
        $this->permissions->method('hasZoneContentEditPermission')->willReturn(true);
        $this->permissions->expects($this->once())->method('canCreateZone')->with(self::USER_ID, 'NATIVE')->willReturn(false);
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);

        $response = $this->invokeHandler('updateZone', 'PUT', ['type' => 'NATIVE']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to change this zone to that type', $this->messageOf($response));
    }

    public function testAFailedUpdateKeepsTheServiceStatusAndMessage(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);
        $this->zoneManagement->method('updateZone')->willReturn(['success' => false, 'message' => 'Zone already exists', 'refusal' => Refusal::CONFLICT]);

        $response = $this->invokeHandler('updateZone', 'PUT', ['name' => 'other.example.com']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Zone already exists', $this->messageOf($response));
    }

    public function testADescriptionOnlyUpdateWritesTheCommentAndEchoesTheZone(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);
        $this->zones->expects($this->once())->method('updateZoneComment')->with(self::ZONE_ID, 'the corporate zone');
        $this->zones->method('getZoneComment')->willReturn('the corporate zone');
        $this->zoneManagement->expects($this->never())->method('updateZone');

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => 'the corporate zone']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('the corporate zone', $this->decode($response)['data']['zone']['description']);
    }

    public function testTheUpdateResponseBodyIsTheDocumentedEnvelope(): void
    {
        $this->zones->method('getZoneById')->willReturn([
            'id' => (string)self::ZONE_ID,
            'name' => 'example.com',
            'type' => 'MASTER',
            'account' => '',
            'master' => '',
        ]);
        $this->zones->method('getZoneComment')->willReturn('');

        $response = $this->invokeHandler('updateZone', 'PUT', ['description' => '']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'data' => [
                'zone' => [
                    'id' => self::ZONE_ID,
                    'name' => 'example.com',
                    'type' => 'MASTER',
                    'masters' => null,
                    'account' => null,
                    'description' => null,
                    'created_at' => null,
                ],
            ],
            'message' => 'Zone updated successfully',
        ], $this->decode($response));
    }

    public function testANonPositiveZoneIdIsRefusedOnDelete(): void
    {
        $this->zoneIdParameter = -1;
        $this->domains->expects($this->never())->method('zoneIdExists');

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID is required', $this->messageOf($response));
    }

    public function testDeletingAMissingZoneIs404BeforeThePermissionCheck(): void
    {
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('zoneIdExists')->willReturn(false);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->expects($this->never())->method('canDeleteZone');

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testDeletingAZoneWithoutPermissionIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canDeleteZone')->willReturn(false);

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to delete this zone', $this->messageOf($response));
    }

    public function testADeleteNeedingApprovalIsPointedAtChangeRequests(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canDeleteZone')->willReturn(true);
        $this->permissions->method('zoneDeleteRequiresApproval')->willReturn(true);
        $this->zoneManagement->expects($this->never())->method('deleteZone');

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Changes to this zone require approval; create a change request instead', $this->messageOf($response));
    }

    public function testASuccessfulZoneDeleteIs204WithAnEmptyBody(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com']);
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => true]);

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string)$response->getContent());
    }

    public function testAFailedZoneDeleteKeepsTheServiceStatus(): void
    {
        $this->zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com']);
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => false, 'message' => 'Zone is a catalog member', 'refusal' => Refusal::CONFLICT]);

        $response = $this->invokeHandler('deleteZone', 'DELETE', null);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Zone is a catalog member', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function invokeHandler(string $handler, string $method, ?array $body): JsonResponse
    {
        return $this->invokeRaw($handler, $method, $body === null ? '' : (string)json_encode($body));
    }

    private function invokeRaw(string $handler, string $method, string $rawBody): JsonResponse
    {
        $controller = $this->bareController(ZonesController::class);
        $this->injectBaseCollaborators($controller, $method);
        $this->inject($controller, 'request', Request::create(
            '/api/v2/zones',
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        ));

        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'domainRepository', $this->domains);
        $this->inject($controller, 'zoneManagementService', $this->zoneManagement);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'ipAddressValidator', new IPAddressValidator());
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', ['id' => $this->zoneIdParameter]);

        return $this->callHandler($controller, $handler);
    }
}
