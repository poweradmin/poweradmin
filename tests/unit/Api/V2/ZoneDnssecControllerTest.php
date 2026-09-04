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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class ZoneDnssecControllerTest extends TestCase
{
    private MockObject $zoneRepository;
    private MockObject $permissionService;
    private MockObject $dnssecProvider;
    private MockObject $apiClient;

    protected function setUp(): void
    {
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->permissionService = $this->createMock(ApiPermissionService::class);
        $this->dnssecProvider = $this->createMock(DnssecProvider::class);
        $this->apiClient = $this->createMock(PowerdnsApiClient::class);
    }

    private function createController(array $pathParameters = ['id' => 1], bool $withApiClient = true): TestableZoneDnssecController
    {
        $controller = new TestableZoneDnssecController([], $pathParameters);
        $controller->setZoneRepository($this->zoneRepository);
        $controller->setApiPermissionService($this->permissionService);
        $controller->setDnssecProvider($this->dnssecProvider);
        $controller->setApiClient($withApiClient ? $this->apiClient : null);
        return $controller;
    }

    public function testGetStatusReturnsParsedDsRecordsAndKskDnskey(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->with(1)->willReturn('example.com');
        $this->dnssecProvider->method('isZoneSecured')->willReturn(true);

        // ZSK first (no DS), then KSK with DS - dnskey must come from the KSK.
        $zsk = new CryptoKey(2, 'zsk', 256, '13', true, '256 3 13 ZSKKEY', []);
        $ksk = new CryptoKey(1, 'ksk', 256, '13', true, '257 3 13 KSKKEY', ['12345 13 2 ABC123DEF456']);
        $this->apiClient->method('getZoneKeys')->willReturn([$zsk, $ksk]);

        $response = $this->createController()->callGetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($content['success']);
        $this->assertTrue($content['data']['enabled']);
        $this->assertCount(1, $content['data']['ds_records']);
        $this->assertSame([
            'key_tag' => 12345,
            'algorithm' => 13,
            'digest_type' => 2,
            'digest' => 'ABC123DEF456',
        ], $content['data']['ds_records'][0]);
        $this->assertSame('257 3 13 KSKKEY', $content['data']['dnskey']);
    }

    public function testGetStatusUnsignedZoneReturnsEmpty(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isZoneSecured')->willReturn(false);
        $this->apiClient->expects($this->never())->method('getZoneKeys');

        $response = $this->createController()->callGetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($content['data']['enabled']);
        $this->assertSame([], $content['data']['ds_records']);
        $this->assertNull($content['data']['dnskey']);
    }

    public function testGetStatusZoneNotFound(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(false);

        $response = $this->createController()->callGetStatus();

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true)['success']);
    }

    public function testGetStatusForbidden(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canViewZone')->willReturn(false);

        $response = $this->createController()->callGetStatus();

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testGetStatusReturns501WhenApiNotConfigured(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canViewZone')->willReturn(true);

        $response = $this->createController(['id' => 1], false)->callGetStatus();

        $this->assertEquals(501, $response->getStatusCode());
        $this->assertStringContainsString('PowerDNS API', json_decode($response->getContent(), true)['message']);
    }

    public function testEnableDnssecSecuresZoneAndLogs(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->expects($this->once())->method('secureZone')->with('example.com')->willReturn(true);
        $this->dnssecProvider->expects($this->once())->method('rectifyZone')->with('example.com')->willReturn(true);
        // First call (no-op guard) reports unsigned, post-sign call reports signed.
        $this->dnssecProvider->method('isZoneSecured')->willReturnOnConsecutiveCalls(false, true);
        $ksk = new CryptoKey(1, 'ksk', 256, '13', true, '257 3 13 KSKKEY', ['12345 13 2 ABC123DEF456']);
        $this->apiClient->method('getZoneKeys')->willReturn([$ksk]);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($content['data']['enabled']);
        $this->assertCount(1, $content['data']['ds_records']);
        $this->assertSame(['zoneId' => 1, 'zoneName' => 'example.com', 'enabled' => true], $controller->loggedChange);
        // SOA serial bumped before signing, matching the web UI flow.
        $this->assertSame([1], $controller->soaBumps);
    }

    public function testDisableDnssecUnsecuresZone(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->expects($this->once())->method('unsecureZone')->with('example.com')->willReturn(true);
        $this->dnssecProvider->expects($this->never())->method('rectifyZone');
        // First call (no-op guard) reports signed, post-unsign call reports unsigned.
        $this->dnssecProvider->method('isZoneSecured')->willReturnOnConsecutiveCalls(true, false);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => false]));
        $response = $controller->callSetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($content['data']['enabled']);
        $this->assertSame(false, $controller->loggedChange['enabled']);
        // SOA serial bumped after unsigning.
        $this->assertSame([1], $controller->soaBumps);
    }

    public function testGetStatusIncludesPresignedFlag(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        // Presigned zones report secured without any local cryptokeys.
        $this->dnssecProvider->method('isZoneSecured')->willReturn(true);
        $this->dnssecProvider->method('isZonePresigned')->willReturn(true);
        $this->apiClient->method('getZoneKeys')->willReturn([]);

        $response = $this->createController()->callGetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($content['data']['enabled']);
        $this->assertTrue($content['data']['presigned']);
    }

    public function testEnableRejectsPresignedZone(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->method('isZonePresigned')->willReturn(true);
        $this->dnssecProvider->expects($this->never())->method('secureZone');

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertStringContainsString('presigned', json_decode($response->getContent(), true)['message']);
    }

    public function testDisableRejectsPresignedZone(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isZonePresigned')->willReturn(true);
        $this->dnssecProvider->expects($this->never())->method('unsecureZone');

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => false]));
        $response = $controller->callSetStatus();

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertStringContainsString('presigned', json_decode($response->getContent(), true)['message']);
    }

    public function testSetStatusRejectsMissingEnabled(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['foo' => 'bar']));
        $response = $controller->callSetStatus();

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSetStatusRejectsNonBooleanEnabled(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => 'yes']));
        $response = $controller->callSetStatus();

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSetStatusForbiddenWithoutDnssecPermission(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(false);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testSetStatusReturns500WhenVerificationFails(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->method('secureZone')->willReturn(true);
        // No-op guard sees unsigned (proceed); post-sign verification still unsigned.
        $this->dnssecProvider->method('isZoneSecured')->willReturn(false);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testEnableNoOpWhenAlreadySigned(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->method('isZoneSecured')->willReturn(true);
        // Already signed: must not re-sign, bump the serial, or log a change.
        $this->dnssecProvider->expects($this->never())->method('secureZone');
        $ksk = new CryptoKey(1, 'ksk', 256, '13', true, '257 3 13 KSKKEY', ['12345 13 2 ABC123DEF456']);
        $this->apiClient->method('getZoneKeys')->willReturn([$ksk]);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($content['data']['enabled']);
        $this->assertSame([], $controller->soaBumps);
        $this->assertNull($controller->loggedChange);
    }

    public function testDisableNoOpWhenAlreadyUnsigned(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isZoneSecured')->willReturn(false);
        // Already unsigned: must not unsign, bump the serial, or log a change.
        $this->dnssecProvider->expects($this->never())->method('unsecureZone');

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => false]));
        $response = $controller->callSetStatus();
        $content = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($content['data']['enabled']);
        $this->assertSame([], $controller->soaBumps);
        $this->assertNull($controller->loggedChange);
    }

    public function testEnableReturns400WhenZoneFailsPreflightValidation(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->method('isZoneSecured')->willReturn(false);
        // Invalid zone must be rejected before signing or bumping the serial.
        $this->dnssecProvider->expects($this->never())->method('secureZone');

        $controller = $this->createController();
        $controller->signingValidationError = 'Zone is missing a valid NS record';
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertSame([], $controller->soaBumps);
        $this->assertStringContainsString('NS record', json_decode($response->getContent(), true)['message']);
    }

    public function testEnableReturns400WhenServerDnssecDisabled(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(false);
        // Must not mutate the zone when the server cannot sign.
        $this->dnssecProvider->expects($this->never())->method('secureZone');

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertSame([], $controller->soaBumps);
        $this->assertStringContainsString('not enabled on the server', json_decode($response->getContent(), true)['message']);
    }

    public function testEnableReturns500WhenSecureZoneCallFails(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        // No-op guard sees unsigned (proceed); the sign call then fails.
        $this->dnssecProvider->method('isZoneSecured')->willReturn(false);
        $this->dnssecProvider->method('secureZone')->willReturn(false);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => true]));
        $response = $controller->callSetStatus();

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertNull($controller->loggedChange);
    }

    public function testDisableReturns500WhenUnsecureZoneCallFails(): void
    {
        $this->zoneRepository->method('zoneExists')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $this->zoneRepository->method('getDomainNameById')->willReturn('example.com');
        // No-op guard sees signed (proceed); the unsign call then fails. A false
        // result must surface as 500 rather than being masked as a successful disable.
        $this->dnssecProvider->method('isZoneSecured')->willReturn(true);
        $this->dnssecProvider->method('unsecureZone')->willReturn(false);

        $controller = $this->createController();
        $controller->setRequestBody(json_encode(['enabled' => false]));
        $response = $controller->callSetStatus();

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertNull($controller->loggedChange);
        $this->assertSame([], $controller->soaBumps);
    }
}
