<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class PowerdnsApiClientTest extends TestCase
{
    private $mockHttpClient;
    private PowerdnsApiClient $apiClient;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClient::class);
        $this->apiClient = new PowerdnsApiClient($this->mockHttpClient, 'localhost');
    }

    public function testGetZoneKeysWithMissingDsKey(): void
    {
        $zone = new Zone('example.com');

        $apiResponse = [
            'responseCode' => 200,
            'data' => [
                [
                    'id' => 1,
                    'keytype' => 'zsk',
                    'bits' => 256,
                    'algorithm' => 'ECDSAP256SHA256',
                    'active' => true,
                    'dnskey' => 'example-dnskey-string',
                    // Note: 'ds' key is intentionally missing for ZSK
                ]
            ]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com/cryptokeys')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertCount(1, $keys);
        $this->assertEquals(1, $keys[0]->getId());
        $this->assertEquals('zsk', $keys[0]->getType());
        $this->assertIsArray($keys[0]->getDs());
        $this->assertEmpty($keys[0]->getDs());
    }

    public function testGetZoneKeysWithDsRecords(): void
    {
        $zone = new Zone('example.com');

        $apiResponse = [
            'responseCode' => 200,
            'data' => [
                [
                    'id' => 2,
                    'keytype' => 'ksk',
                    'bits' => 256,
                    'algorithm' => 'ECDSAP256SHA256',
                    'active' => true,
                    'dnskey' => 'example-dnskey-string',
                    'ds' => ['12345 13 2 abcdef...', '12345 13 4 123456...']
                ]
            ]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com/cryptokeys')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertCount(1, $keys);
        $this->assertEquals(2, $keys[0]->getId());
        $this->assertEquals('ksk', $keys[0]->getType());
        $this->assertIsArray($keys[0]->getDs());
        $this->assertCount(2, $keys[0]->getDs());
    }

    public function testGetZoneKeysWithMixedKeys(): void
    {
        $zone = new Zone('example.com');

        $apiResponse = [
            'responseCode' => 200,
            'data' => [
                [
                    'id' => 1,
                    'keytype' => 'zsk',
                    'bits' => 256,
                    'algorithm' => 'ECDSAP256SHA256',
                    'active' => true,
                    'dnskey' => 'zsk-dnskey-string',
                    // Missing 'ds' key for ZSK
                ],
                [
                    'id' => 2,
                    'keytype' => 'ksk',
                    'bits' => 256,
                    'algorithm' => 'ECDSAP256SHA256',
                    'active' => true,
                    'dnskey' => 'ksk-dnskey-string',
                    'ds' => ['67890 13 2 fedcba...']
                ]
            ]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com/cryptokeys')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertCount(2, $keys);

        // First key (ZSK) should have empty DS array
        $this->assertEquals(1, $keys[0]->getId());
        $this->assertEquals('zsk', $keys[0]->getType());
        $this->assertIsArray($keys[0]->getDs());
        $this->assertEmpty($keys[0]->getDs());

        // Second key (KSK) should have DS records
        $this->assertEquals(2, $keys[1]->getId());
        $this->assertEquals('ksk', $keys[1]->getType());
        $this->assertIsArray($keys[1]->getDs());
        $this->assertCount(1, $keys[1]->getDs());
    }

    public function testGetZoneKeysReturnsEmptyArrayOnError(): void
    {
        $zone = new Zone('example.com');

        $apiResponse = [
            'responseCode' => 404,
            'data' => []
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertIsArray($keys);
        $this->assertEmpty($keys);
    }

    public function testGetZoneKeysWithEmptyResponse(): void
    {
        $zone = new Zone('example.com');

        $apiResponse = [
            'responseCode' => 200,
            'data' => []
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertIsArray($keys);
        $this->assertEmpty($keys);
    }
}
