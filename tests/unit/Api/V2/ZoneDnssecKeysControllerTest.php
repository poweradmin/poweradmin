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
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Port\ZoneSigningInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class ZoneDnssecKeysControllerTest extends TestCase
{
    // The DNSKEY from RFC 4034 section 5.4, whose key tag is 60485
    private const RFC_DNSKEY = '256 3 5 AQOeiiR0GOMYkDshWoSKz9XzfwJr1AYtsmx3TGkJaNXVbfi/2pHm822aJ5iI9BMzNXxeYCmZDRD99WYwYqUSdjMmmAphXdvxegXd/M5+X7OrzKBaMbCVdFLUUh6DhweJBjEVv5f2wwjM9XzcnOf+EPbtG9DMBmADjFDc2w/rljwvFw==';

    private MockObject $domainRepository;
    private MockObject $permissionService;
    private MockObject $dnssecProvider;
    private MockObject $apiClient;
    private MockObject $audit;

    protected function setUp(): void
    {
        $this->domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $this->domainRepository->method('getDomainNameById')->willReturnCallback(fn(int $id): ?string => $id === 1 ? 'example.com' : null);
        $this->permissionService = $this->createMock(ApiPermissionService::class);
        $this->dnssecProvider = $this->createMock(ZoneSigningInterface::class);
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->apiClient = $this->createMock(PowerdnsApiClient::class);
        $this->audit = $this->createMock(AuditService::class);
    }

    private function controller(array $pathParameters = ['id' => 1], ?string $body = null, bool $withApiClient = true): TestableZoneDnssecKeysController
    {
        $controller = new TestableZoneDnssecKeysController([], $pathParameters);
        $controller->setDomainRepository($this->domainRepository);
        $controller->setApiPermissionService($this->permissionService);
        $controller->setDnssecProvider($this->dnssecProvider);
        $controller->setApiClient($withApiClient ? $this->apiClient : null);
        $controller->setAuditService($this->audit);
        if ($body !== null) {
            $controller->setRequestBody($body);
        }

        return $controller;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function decode(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return [$response->getStatusCode(), json_decode((string)$response->getContent(), true)];
    }

    private function allowManage(): void
    {
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(true);
    }

    public function testListNamesEveryFieldAndComputesTheKeyTag(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn([
            new CryptoKey(3, 'zsk', 1024, 'RSASHA1', true, self::RFC_DNSKEY, []),
        ]);

        [$status, $body] = self::decode($this->controller()->callListKeys());

        $this->assertSame(200, $status);
        $this->assertSame([
            'id' => 3,
            'type' => 'zsk',
            'keytag' => 60485,
            'algorithm' => 'rsasha1',
            'algorithm_id' => 5,
            'bits' => 1024,
            'active' => true,
            'dnskey' => self::RFC_DNSKEY,
            'ds' => [],
        ], $body['data'][0]);
    }

    public function testAnUnreachablePowerDnsIsNotAnEmptyListOrAMissingKey(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn(null);
        $this->apiClient->expects($this->never())->method('removeZoneKey');

        $this->assertSame(502, $this->controller()->callListKeys()->getStatusCode());
        $this->assertSame(502, $this->controller(['id' => 1, 'key_id' => 3])->callGetKey()->getStatusCode());
        $this->assertSame(502, $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey()->getStatusCode());
    }

    public function testAnUnreachablePowerDnsIsNotReportedAsDnssecDisabled(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn(null);
        $this->apiClient->expects($this->never())->method('createZoneKey');

        [$status, $body] = self::decode($this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey());

        $this->assertSame(502, $status);
        $this->assertSame('Failed to retrieve DNSSEC keys from PowerDNS', $body['message']);
    }

    public function testAnUnknownKeyIsNotFound(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn([]);

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 9])->callGetKey());

        $this->assertSame(404, $status);
        $this->assertSame('DNSSEC key not found', $body['message']);
    }

    public function testAddReturnsTheKeyPowerDnsCreatedAndAuditsIt(): void
    {
        $this->allowManage();
        $this->apiClient->expects($this->once())->method('createZoneKey')
            ->with($this->anything(), $this->callback(fn(CryptoKey $k): bool => $k->getType() === 'csk' && $k->getSize() === 256 && $k->getAlgorithm() === 'ecdsa256'), true)
            ->willReturn(new CryptoKey(7, 'csk', 256, 'ECDSAP256SHA256', true, '257 3 13 AAAA', ['1 13 2 ABCD']));
        $this->apiClient->method('fetchZoneKeys')->willReturn([]);
        $this->audit->expects($this->once())->method('logDnssecAddKey')->with(1, 'example.com', 'csk', '256', 'ecdsa256');

        [$status, $body] = self::decode($this->controller(['id' => 1], '{"type":"CSK","algorithm":"ecdsa256","bits":256,"active":true}')->callAddKey());

        $this->assertSame(201, $status);
        $this->assertSame(7, $body['data']['id']);
        $this->assertTrue($body['data']['active']);
        $this->assertSame(['1 13 2 ABCD'], $body['data']['ds']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidAddProvider(): array
    {
        return [
            'missing type' => ['{"algorithm":"ecdsa256","bits":256}', 'Missing or invalid required field: type (ksk, zsk or csk)'],
            'curve size mismatch' => ['{"type":"zsk","algorithm":"ecdsa256","bits":384}', 'ecdsa256 requires 256 bits'],
            'rsa size' => ['{"type":"zsk","algorithm":"rsasha256","bits":768}', 'rsasha256 requires 1024 or 2048 bits'],
            'bits not a number' => ['{"type":"zsk","algorithm":"ecdsa256","bits":"big"}', 'Missing or invalid required field: bits (integer)'],
            'active not a boolean' => ['{"type":"zsk","algorithm":"ecdsa256","bits":256,"active":"yes"}', 'Invalid field: active (boolean)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAddProvider')]
    public function testInvalidKeysAreRefusedWithFixedEnglishMessages(string $body, string $message): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn([]);
        $this->apiClient->expects($this->never())->method('createZoneKey');

        [$status, $decoded] = self::decode($this->controller(['id' => 1], $body)->callAddKey());

        $this->assertSame(400, $status);
        $this->assertSame($message, $decoded['message']);
    }

    public function testAddingNeedsTheDnssecManagePermission(): void
    {
        $this->permissionService->method('canManageDnssec')->willReturn(false);
        $this->apiClient->expects($this->never())->method('createZoneKey');

        $this->assertSame(403, $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey()->getStatusCode());
    }

    public function testPresignedZonesAreRefused(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->method('isZonePresigned')->willReturn(true);

        $this->assertSame(409, $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey()->getStatusCode());
    }

    public function testWithoutThePowerDnsApiKeysAreNotImplemented(): void
    {
        $this->allowManage();

        $this->assertSame(501, $this->controller(['id' => 1], null, false)->callListKeys()->getStatusCode());
    }

    public function testAnUnknownZoneIsNotFound(): void
    {
        $this->allowManage();

        $this->assertSame(404, $this->controller(['id' => 2])->callListKeys()->getStatusCode());
    }

    public function testDeactivatingReportsTheNewStateAndAudits(): void
    {
        $this->allowManage();
        $key = new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', []);
        $this->apiClient->method('fetchZoneKeys')->willReturn([$key]);
        $this->apiClient->expects($this->once())->method('deactivateZoneKey')->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(1, 'example.com', 3, 'deactivate');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertFalse($body['data']['active']);
        $this->assertSame(3, $body['data']['id']);
    }

    public function testRequestingTheCurrentStateChangesNothing(): void
    {
        $this->allowManage();
        $this->apiClient->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])]);
        $this->apiClient->expects($this->never())->method('activateZoneKey');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":true}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertSame('DNSSEC key already active', $body['message']);
    }

    public function testDeletingRemovesTheListedKeyAndAudits(): void
    {
        $this->allowManage();
        $key = new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', false, '256 3 13 AAAA', []);
        $this->apiClient->method('fetchZoneKeys')->willReturn([$key]);
        $this->apiClient->expects($this->once())->method('removeZoneKey')->with($this->anything(), $key)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecDeleteKey')->with(1, 'example.com', 3);

        $this->assertSame(200, $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey()->getStatusCode());
    }
}
