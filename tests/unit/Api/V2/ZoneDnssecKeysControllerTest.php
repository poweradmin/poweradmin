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
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecKeyService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class ZoneDnssecKeysControllerTest extends TestCase
{
    // The DNSKEY from RFC 4034 section 5.4, whose key tag is 60485
    private const RFC_DNSKEY = '256 3 5 AQOeiiR0GOMYkDshWoSKz9XzfwJr1AYtsmx3TGkJaNXVbfi/2pHm822aJ5iI9BMzNXxeYCmZDRD99WYwYqUSdjMmmAphXdvxegXd/M5+X7OrzKBaMbCVdFLUUh6DhweJBjEVv5f2wwjM9XzcnOf+EPbtG9DMBmADjFDc2w/rljwvFw==';

    // A test-only ECDSA P-256 key in the ISC/BIND format
    private const ISC_KEY = "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: 8oJBqwnnl8Tnp7LrlF26dio/Wl/qIuxVmtCCjVFqsbs=\n";

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
        $this->dnssecProvider = $this->createMock(DnssecProviderInterface::class);
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->apiClient = $this->createMock(PowerdnsApiClient::class);
        $this->audit = $this->createMock(AuditService::class);
    }

    private function controller(array $pathParameters = ['id' => 1], ?string $body = null, bool $withApiClient = true): TestableZoneDnssecKeysController
    {
        $controller = new TestableZoneDnssecKeysController([], $pathParameters);
        $controller->setDomainRepository($this->domainRepository);
        $controller->setApiPermissionService($this->permissionService);
        $controller->setKeyService(new DnssecKeyService($this->dnssecProvider, $this->audit));
        $controller->setApiClient($withApiClient ? $this->apiClient : null);
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
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([
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
            'sep' => false,
        ], $body['data'][0]);
    }

    public function testAnUnreachablePowerDnsIsNotAnEmptyListOrAMissingKey(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn(null);
        $this->dnssecProvider->expects($this->never())->method('removeZoneKey');

        $this->assertSame(502, $this->controller()->callListKeys()->getStatusCode());
        $this->assertSame(502, $this->controller(['id' => 1, 'key_id' => 3])->callGetKey()->getStatusCode());
        $this->assertSame(502, $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey()->getStatusCode());
    }

    public function testAnUnreachablePowerDnsIsNotReportedAsDnssecDisabled(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn(null);
        $this->dnssecProvider->expects($this->never())->method('createZoneKey');

        [$status, $body] = self::decode($this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey());

        $this->assertSame(502, $status);
        $this->assertSame('Failed to retrieve DNSSEC keys from PowerDNS', $body['message']);
    }

    public function testAnUnknownKeyIsNotFound(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 9])->callGetKey());

        $this->assertSame(404, $status);
        $this->assertSame('DNSSEC key not found', $body['message']);
    }

    public function testAddReturnsTheKeyPowerDnsCreatedAndAuditsIt(): void
    {
        $this->allowManage();
        $this->dnssecProvider->expects($this->once())->method('createZoneKey')
            ->with('example.com', 'csk', 256, 'ecdsa256', true)
            ->willReturn(new CryptoKey(7, 'csk', 256, 'ECDSAP256SHA256', true, '257 3 13 AAAA', ['1 13 2 ABCD']));
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
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
            'digit string bits of the wrong size' => ['{"type":"zsk","algorithm":"ecdsa256","bits":"384"}', 'ecdsa256 requires 256 bits'],
            'unknown algorithm' => ['{"type":"zsk","algorithm":"md5","bits":256}', 'Missing or invalid required field: algorithm (one of: rsasha1, rsasha1-nsec3-sha1, rsasha256, rsasha512, ecdsa256, ecdsa384, ed25519, ed448)'],
            'active not a boolean' => ['{"type":"zsk","algorithm":"ecdsa256","bits":256,"active":"yes"}', 'Invalid field: active (boolean)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAddProvider')]
    public function testInvalidKeysAreRefusedWithFixedEnglishMessages(string $body, string $message): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->expects($this->never())->method('createZoneKey');

        [$status, $decoded] = self::decode($this->controller(['id' => 1], $body)->callAddKey());

        $this->assertSame(400, $status);
        $this->assertSame($message, $decoded['message']);
    }

    public function testAddingNeedsTheDnssecManagePermission(): void
    {
        $this->permissionService->method('canManageDnssec')->willReturn(false);
        $this->dnssecProvider->expects($this->never())->method('createZoneKey');

        $this->assertSame(403, $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey()->getStatusCode());
    }

    public function testPresignedZonesAreRefused(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
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
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([$key]);
        $this->dnssecProvider->expects($this->once())->method('deactivateZoneKey')->with('example.com', 3)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(1, 'example.com', 3, 'deactivate');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertFalse($body['data']['active']);
        $this->assertSame(3, $body['data']['id']);
    }

    public function testRequestingTheCurrentStateChangesNothing(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])]);
        $this->dnssecProvider->expects($this->never())->method('activateZoneKey');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":true}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertSame('DNSSEC key already active', $body['message']);

        $this->dnssecProvider = $this->createMock(\Poweradmin\Domain\Port\DnssecProviderInterface::class);
        $this->dnssecProvider->method('isDnssecEnabled')->willReturn(true);
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', false, '256 3 13 AAAA', [])]);
        $this->dnssecProvider->expects($this->never())->method('deactivateZoneKey');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertSame('DNSSEC key already inactive', $body['message']);
    }

    public function testDeletingRemovesTheListedKeyAndAudits(): void
    {
        $this->allowManage();
        $key = new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', false, '256 3 13 AAAA', []);
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([$key]);
        $this->dnssecProvider->expects($this->once())->method('removeZoneKey')->with('example.com', 3)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecDeleteKey')->with(1, 'example.com', 3);

        $this->assertSame(200, $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey()->getStatusCode());
    }

    public function testAViewOnlyUserCanReadKeysButNotChangeThem(): void
    {
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(false);
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])]);
        $this->dnssecProvider->expects($this->never())->method('createZoneKey');
        $this->dnssecProvider->expects($this->never())->method('deactivateZoneKey');
        $this->dnssecProvider->expects($this->never())->method('removeZoneKey');

        $this->assertSame(200, $this->controller()->callListKeys()->getStatusCode());
        $this->assertSame(200, $this->controller(['id' => 1, 'key_id' => 3])->callGetKey()->getStatusCode());
        $this->assertSame(403, $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey()->getStatusCode());
        $this->assertSame(403, $this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey()->getStatusCode());
        $this->assertSame(403, $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey()->getStatusCode());
    }

    public function testActivatingReportsTheNewStateAndAudits(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'ksk', 256, 'ECDSAP256SHA256', false, '257 3 13 AAAA', ['1 13 2 AB'])]);
        $this->dnssecProvider->expects($this->once())->method('activateZoneKey')->with('example.com', 3)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(1, 'example.com', 3, 'activate');

        [$status, $body] = self::decode($this->controller(['id' => 1, 'key_id' => 3], '{"active":true}')->callUpdateKey());

        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['active']);
        $this->assertSame('id', array_key_first($body['data']), 'the field order matches list and get');
    }

    public function testAKeyChangeAsksPowerDnsForTheKeysOnce(): void
    {
        $this->allowManage();
        $this->dnssecProvider->expects($this->exactly(2))->method('fetchZoneKeys')
            ->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])]);
        $this->dnssecProvider->method('deactivateZoneKey')->willReturn(true);
        $this->dnssecProvider->method('removeZoneKey')->willReturn(true);

        $this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey();
        $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey();
    }

    /** @return array<string, array{0: string}> */
    public static function backendFailureProvider(): array
    {
        return [
            'add' => ['add'],
            'deactivate' => ['deactivate'],
            'delete' => ['delete'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backendFailureProvider')]
    public function testARefusedPowerDnsWriteIsAServerErrorAndIsNotAudited(string $operation): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([new CryptoKey(3, 'zsk', 256, 'ECDSAP256SHA256', true, '256 3 13 AAAA', [])]);
        $this->dnssecProvider->method('createZoneKey')->willReturn(null);
        $this->dnssecProvider->method('deactivateZoneKey')->willReturn(false);
        $this->dnssecProvider->method('removeZoneKey')->willReturn(false);
        $this->audit->expects($this->never())->method($this->anything());

        $response = match ($operation) {
            'add' => $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}')->callAddKey(),
            'deactivate' => $this->controller(['id' => 1, 'key_id' => 3], '{"active":false}')->callUpdateKey(),
            default => $this->controller(['id' => 1, 'key_id' => 3])->callDeleteKey(),
        };

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testAServerWithoutDnssecRefusesKeyChanges(): void
    {
        $this->permissionService->method('canManageDnssec')->willReturn(true);
        $provider = $this->createMock(DnssecProviderInterface::class);
        $provider->method('isDnssecEnabled')->willReturn(false);
        $provider->method('fetchZoneKeys')->willReturn([]);
        $provider->expects($this->never())->method('createZoneKey');
        $controller = $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":256}');
        $controller->setKeyService(new DnssecKeyService($provider, $this->audit));

        [$status, $body] = self::decode($controller->callAddKey());

        $this->assertSame(400, $status);
        $this->assertSame('DNSSEC is not enabled on the server', $body['message']);
    }

    public function testABadBodyIsRefusedBeforePowerDnsIsAsked(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('isZonePresigned')->willReturn(true);
        $this->dnssecProvider->expects($this->never())->method('fetchZoneKeys');

        $this->assertSame(400, $this->controller(['id' => 1], '{"type":"csk","algorithm":"ecdsa256","bits":384}')->callAddKey()->getStatusCode());
        $this->assertSame(400, $this->controller(['id' => 1, 'key_id' => 3], '{"active":"no"}')->callUpdateKey()->getStatusCode());
    }

    public function testAnOlderServerIsNotOfferedEd448(): void
    {
        $this->allowManage();
        $controller = $this->controller(['id' => 1], '{"type":"csk","algorithm":"ed448","bits":456}');
        $controller->capabilities = \Poweradmin\Domain\Model\PdnsCapabilities::fromServerInfo(['version' => '4.4.0']);

        [$status, $body] = self::decode($controller->callAddKey());

        $this->assertSame(400, $status);
        $this->assertStringNotContainsString('ed448', $body['message']);
    }

    private static function importBody(string $type = 'csk', ?string $privateKey = null): string
    {
        return (string)json_encode(['type' => $type, 'privatekey' => $privateKey ?? self::ISC_KEY, 'active' => true]);
    }

    public function testImportReturnsTheKeyPowerDnsCreatedWithoutThePrivateKey(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->expects($this->once())->method('importZoneKeyFromPrivateKey')
            ->with('example.com', 'csk', self::ISC_KEY, true)
            ->willReturn(new CryptoKey(9, 'csk', 256, 'ECDSAP256SHA256', true, '257 3 13 AAAA', ['1 13 2 ABCD']));
        $this->audit->expects($this->once())->method('logDnssecAddKey')->with(1, 'example.com', 'csk', '256', 'ecdsa256');

        $response = $this->controller(['id' => 1, 'action' => 'import'], self::importBody())->callImportKey();
        [$status, $body] = self::decode($response);

        $this->assertSame(201, $status);
        $this->assertSame(9, $body['data']['id']);
        $this->assertSame('ecdsa256', $body['data']['algorithm']);
        $this->assertArrayNotHasKey('privatekey', $body['data']);
        $this->assertStringNotContainsString('PrivateKey:', (string)$response->getContent());
    }

    public function testImportingNeedsTheDnssecManagePermission(): void
    {
        $this->permissionService->method('canViewZone')->willReturn(true);
        $this->permissionService->method('canManageDnssec')->willReturn(false);
        $this->dnssecProvider->expects($this->never())->method('importZoneKeyFromPrivateKey');

        $response = $this->controller(['id' => 1, 'action' => 'import'], self::importBody())->callImportKey();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('PrivateKey:', (string)$response->getContent());
    }

    public function testARejectedKeyIsA400WithoutTheKeyText(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->method('importZoneKeyFromPrivateKey')->willReturn(DnssecKeyOutcome::KEY_REJECTED);
        $this->audit->expects($this->never())->method('logDnssecAddKey');

        $response = $this->controller(['id' => 1, 'action' => 'import'], self::importBody())->callImportKey();
        [$status, $body] = self::decode($response);

        $this->assertSame(400, $status);
        $this->assertSame('PowerDNS rejected the private key', $body['message']);
        $this->assertStringNotContainsString('PrivateKey:', (string)$response->getContent());
        $this->assertStringNotContainsString('8oJBqwnnl8', (string)$response->getContent());
    }

    public function testAKeyWithAnUnsupportedAlgorithmIsA400BeforePowerDnsIsAsked(): void
    {
        $this->allowManage();
        $this->dnssecProvider->expects($this->never())->method('importZoneKeyFromPrivateKey');

        $dsaKey = str_replace('Algorithm: 13 (ECDSAP256SHA256)', 'Algorithm: 3 (DSA)', self::ISC_KEY);
        $response = $this->controller(['id' => 1, 'action' => 'import'], self::importBody('zsk', $dsaKey))->callImportKey();
        [$status, $body] = self::decode($response);

        $this->assertSame(400, $status);
        $this->assertStringStartsWith('The private key uses an unsupported algorithm (one of: ', $body['message']);
        $this->assertStringNotContainsString('PrivateKey:', (string)$response->getContent());
    }

    public function testAFailedImportIsAServerErrorAndIsNotAudited(): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->method('importZoneKeyFromPrivateKey')->willReturn(DnssecKeyOutcome::FAILED);
        $this->audit->expects($this->never())->method('logDnssecAddKey');

        $this->assertSame(500, $this->controller(['id' => 1, 'action' => 'import'], self::importBody())->callImportKey()->getStatusCode());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidImportProvider(): array
    {
        return [
            'missing type' => [(string)json_encode(['privatekey' => self::ISC_KEY]), 'Missing or invalid required field: type (ksk, zsk or csk)'],
            'pem key' => [
                self::importBody('csk', "-----BEGIN PRIVATE KEY-----\nMIGHAgEA\n-----END PRIVATE KEY-----\n"),
                'Missing or invalid required field: privatekey (an ISC/BIND private key with "Private-key-format: v1.x"; PEM keys can be imported with pdnsutil import-zone-key-pem)',
            ],
            'active not a boolean' => [(string)json_encode(['type' => 'csk', 'privatekey' => self::ISC_KEY, 'active' => 'yes']), 'Invalid field: active (boolean)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidImportProvider')]
    public function testInvalidImportsAreRefusedBeforePowerDnsIsAsked(string $body, string $message): void
    {
        $this->allowManage();
        $this->dnssecProvider->method('fetchZoneKeys')->willReturn([]);
        $this->dnssecProvider->expects($this->never())->method('importZoneKeyFromPrivateKey');

        $response = $this->controller(['id' => 1, 'action' => 'import'], $body)->callImportKey();
        [$status, $decoded] = self::decode($response);

        $this->assertSame(400, $status);
        $this->assertSame($message, $decoded['message']);
        $this->assertStringNotContainsString('MIGHAgEA', (string)$response->getContent());
    }
}
