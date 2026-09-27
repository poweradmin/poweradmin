<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

#[CoversClass(PowerdnsApiClient::class)]
class PowerdnsApiClientKeyImportTest extends TestCase
{
    private const ISC_KEY = "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: AAAA\n";

    public function testSendsOnlyKeytypePrivatekeyAndActiveAndReturnsTheCreatedKey(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/api/v1/servers/localhost/zones/example.com./cryptokeys', [
                'keytype' => 'csk',
                'privatekey' => self::ISC_KEY,
                'active' => true,
            ])
            ->willReturn(['responseCode' => 201, 'data' => [
                'id' => 4, 'keytype' => 'csk', 'active' => true, 'bits' => 256, 'algorithm' => 'ECDSAP256SHA256',
                'dnskey' => '257 3 13 AAAA', 'ds' => ['1 13 2 AB'],
            ]]);

        $key = (new PowerdnsApiClient($http, 'localhost'))->createZoneKeyFromPrivateKey(new Zone('example.com.'), 'csk', self::ISC_KEY, true);

        $this->assertInstanceOf(CryptoKey::class, $key);
        $this->assertSame(4, $key->getId());
    }

    /** @return array<string, array{0: int, 1: DnssecKeyOutcome}> */
    public static function errorStatuses(): array
    {
        return [
            'unparseable key' => [422, DnssecKeyOutcome::KEY_REJECTED],
            'wrong PowerDNS API key' => [401, DnssecKeyOutcome::FAILED],
            'forbidden' => [403, DnssecKeyOutcome::FAILED],
            'zone missing in PowerDNS' => [404, DnssecKeyOutcome::FAILED],
            'server error' => [500, DnssecKeyOutcome::FAILED],
            'unreachable' => [0, DnssecKeyOutcome::FAILED],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function testOnlyA422MeansTheKeyWasRejected(int $status, DnssecKeyOutcome $expected): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('makeRequest')->willThrowException(new ApiErrorException('error', $status));

        $result = (new PowerdnsApiClient($http, 'localhost'))->createZoneKeyFromPrivateKey(new Zone('example.com.'), 'csk', self::ISC_KEY);

        $this->assertSame($expected, $result);
    }
}
