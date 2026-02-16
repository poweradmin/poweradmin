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

    public function testSecureZoneEncodesSlashInRfc2317ZoneName(): void
    {
        $zone = new Zone('0/26.1.168.192.in-addr.arpa.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('PUT', '/api/v1/servers/localhost/zones/0%2F26.1.168.192.in-addr.arpa.')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->secureZone($zone);

        $this->assertTrue($result);
    }

    public function testGetZoneKeysEncodesSlashInRfc2317ZoneName(): void
    {
        $zone = new Zone('0/26.1.168.192.in-addr.arpa.');

        $apiResponse = [
            'responseCode' => 200,
            'data' => [
                [
                    'id' => 1,
                    'keytype' => 'ksk',
                    'bits' => 256,
                    'algorithm' => 'ECDSAP256SHA256',
                    'active' => true,
                    'dnskey' => 'example-dnskey-string',
                    'ds' => ['12345 13 2 abcdef...']
                ]
            ]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/0%2F26.1.168.192.in-addr.arpa./cryptokeys')
            ->willReturn($apiResponse);

        $keys = $this->apiClient->getZoneKeys($zone);

        $this->assertCount(1, $keys);
    }

    public function testIsZoneSecuredEncodesSlashInRfc2317ZoneName(): void
    {
        $zone = new Zone('128/25.0.168.192.in-addr.arpa.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/128%2F25.0.168.192.in-addr.arpa.')
            ->willReturn(['responseCode' => 200, 'data' => ['dnssec' => true]]);

        $result = $this->apiClient->isZoneSecured($zone);

        $this->assertTrue($result);
    }

    public function testDeleteZoneEncodesSlashInRfc2317ZoneName(): void
    {
        $zone = new Zone('0/26.1.168.192.in-addr.arpa.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/api/v1/servers/localhost/zones/0%2F26.1.168.192.in-addr.arpa.')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->deleteZone($zone);

        $this->assertTrue($result);
    }

    public function testGetZoneMetadataReturnsMetadataArray(): void
    {
        $zone = new Zone('example.com.');

        $apiResponse = [
            'responseCode' => 200,
            'data' => [
                ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.168.1.0/24']],
                ['kind' => 'SOA-EDIT-API', 'metadata' => ['DEFAULT']],
            ]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com./metadata')
            ->willReturn($apiResponse);

        $metadata = $this->apiClient->getZoneMetadata($zone);

        $this->assertCount(2, $metadata);
        $this->assertEquals('ALLOW-AXFR-FROM', $metadata[0]['kind']);
        $this->assertEquals(['192.168.1.0/24'], $metadata[0]['metadata']);
    }

    public function testGetZoneMetadataReturnsEmptyArrayOnError(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $metadata = $this->apiClient->getZoneMetadata($zone);

        $this->assertIsArray($metadata);
        $this->assertEmpty($metadata);
    }

    public function testGetZoneMetadataKindReturnsSpecificMetadata(): void
    {
        $zone = new Zone('example.com.');

        $apiResponse = [
            'responseCode' => 200,
            'data' => ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.168.1.0/24', '10.0.0.0/8']]
        ];

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com./metadata/ALLOW-AXFR-FROM')
            ->willReturn($apiResponse);

        $metadata = $this->apiClient->getZoneMetadataKind($zone, 'ALLOW-AXFR-FROM');

        $this->assertEquals('ALLOW-AXFR-FROM', $metadata['kind']);
        $this->assertCount(2, $metadata['metadata']);
    }

    public function testGetZoneMetadataKindReturnsEmptyArrayOnNotFound(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $metadata = $this->apiClient->getZoneMetadataKind($zone, 'NONEXISTENT');

        $this->assertIsArray($metadata);
        $this->assertEmpty($metadata);
    }

    public function testCreateZoneMetadata(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with(
                'POST',
                '/api/v1/servers/localhost/zones/example.com./metadata',
                ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.168.1.0/24']]
            )
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->createZoneMetadata($zone, 'ALLOW-AXFR-FROM', ['192.168.1.0/24']);

        $this->assertTrue($result);
    }

    public function testCreateZoneMetadataReturnsFalseOnError(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 422, 'data' => []]);

        $result = $this->apiClient->createZoneMetadata($zone, 'INVALID', ['value']);

        $this->assertFalse($result);
    }

    public function testUpdateZoneMetadata(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with(
                'PUT',
                '/api/v1/servers/localhost/zones/example.com./metadata/ALLOW-AXFR-FROM',
                ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['10.0.0.0/8']]
            )
            ->willReturn(['responseCode' => 200, 'data' => ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['10.0.0.0/8']]]);

        $result = $this->apiClient->updateZoneMetadata($zone, 'ALLOW-AXFR-FROM', ['10.0.0.0/8']);

        $this->assertTrue($result);
    }

    public function testDeleteZoneMetadata(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/api/v1/servers/localhost/zones/example.com./metadata/TSIG-ALLOW-AXFR')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->deleteZoneMetadata($zone, 'TSIG-ALLOW-AXFR');

        $this->assertTrue($result);
    }

    public function testDeleteZoneMetadataReturnsFalseOnNotFound(): void
    {
        $zone = new Zone('example.com.');

        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $result = $this->apiClient->deleteZoneMetadata($zone, 'NONEXISTENT');

        $this->assertFalse($result);
    }
}
