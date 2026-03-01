<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

#[CoversClass(PowerdnsApiClient::class)]
class PowerdnsApiClientExtendedTest extends TestCase
{
    private $mockHttpClient;
    private PowerdnsApiClient $apiClient;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClient::class);
        $this->apiClient = new PowerdnsApiClient($this->mockHttpClient, 'localhost');
    }

    // ---------------------------------------------------------------
    // getZone
    // ---------------------------------------------------------------

    public function testGetZoneReturnsDataOnSuccess(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.')
            ->willReturn([
                'responseCode' => 200,
                'data' => ['name' => 'example.com.', 'kind' => 'Native', 'rrsets' => []],
            ]);

        $result = $this->apiClient->getZone('example.com.');

        $this->assertNotNull($result);
        $this->assertEquals('example.com.', $result['name']);
    }

    public function testGetZoneReturnsNullOnNotFound(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $result = $this->apiClient->getZone('nonexistent.com.');

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // createZoneWithData
    // ---------------------------------------------------------------

    public function testCreateZoneWithDataReturnsDataOnSuccess(): void
    {
        $zoneData = ['name' => 'new.example.com.', 'kind' => 'Native', 'nameservers' => []];

        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/api/v1/servers/localhost/zones', $zoneData)
            ->willReturn([
                'responseCode' => 201,
                'data' => ['name' => 'new.example.com.', 'kind' => 'Native'],
            ]);

        $result = $this->apiClient->createZoneWithData($zoneData);

        $this->assertNotNull($result);
        $this->assertEquals('new.example.com.', $result['name']);
    }

    public function testCreateZoneWithDataReturnsNullOnFailure(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 422, 'data' => []]);

        $result = $this->apiClient->createZoneWithData(['name' => 'bad.', 'kind' => 'Native', 'nameservers' => []]);

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // updateZoneProperties
    // ---------------------------------------------------------------

    public function testUpdateZonePropertiesReturnsTrue(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('PUT', '/api/v1/servers/localhost/zones/example.com.', ['kind' => 'Master'])
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->updateZoneProperties('example.com.', ['kind' => 'Master']);

        $this->assertTrue($result);
    }

    public function testUpdateZonePropertiesReturnsFalseOnError(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $result = $this->apiClient->updateZoneProperties('nonexistent.com.', ['kind' => 'Master']);

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // patchZoneRRsets
    // ---------------------------------------------------------------

    public function testPatchZoneRRsetsCallsCorrectEndpoint(): void
    {
        $rrsets = [
            [
                'name' => 'www.example.com.',
                'type' => 'A',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [['content' => '1.2.3.4', 'disabled' => false]],
            ],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('PATCH', '/api/v1/servers/localhost/zones/example.com.', ['rrsets' => $rrsets])
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->patchZoneRRsets('example.com.', $rrsets);

        $this->assertTrue($result);
    }

    public function testPatchZoneRRsetsReturnsFalseOnError(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 422, 'data' => []]);

        $result = $this->apiClient->patchZoneRRsets('example.com.', []);

        $this->assertFalse($result);
    }

    public function testPatchZoneRRsetsEncodesSlashInZoneName(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('PATCH', '/api/v1/servers/localhost/zones/0%2F26.1.168.192.in-addr.arpa.', $this->anything())
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->patchZoneRRsets('0/26.1.168.192.in-addr.arpa.', []);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // searchData
    // ---------------------------------------------------------------

    public function testSearchDataReturnsResults(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('GET', $this->stringContains('/search-data?'))
            ->willReturn([
                'responseCode' => 200,
                'data' => [
                    ['object_type' => 'record', 'name' => 'www.example.com.', 'type' => 'A', 'content' => '1.2.3.4'],
                ],
            ]);

        $result = $this->apiClient->searchData('*example*');

        $this->assertCount(1, $result);
        $this->assertEquals('www.example.com.', $result[0]['name']);
    }

    public function testSearchDataReturnsEmptyOnError(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 422, 'data' => []]);

        $result = $this->apiClient->searchData('*invalid*');

        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // Autoprimary operations
    // ---------------------------------------------------------------

    public function testGetAutoprimariesReturnsData(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/autoprimaries')
            ->willReturn([
                'responseCode' => 200,
                'data' => [
                    ['ip' => '192.168.1.1', 'nameserver' => 'ns1.example.com', 'account' => ''],
                ],
            ]);

        $result = $this->apiClient->getAutoprimaries();

        $this->assertCount(1, $result);
        $this->assertEquals('192.168.1.1', $result[0]['ip']);
    }

    public function testAddAutoprimaryReturnsTrue(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/api/v1/servers/localhost/autoprimaries', [
                'ip' => '192.168.1.1',
                'nameserver' => 'ns1.example.com',
                'account' => 'admin',
            ])
            ->willReturn(['responseCode' => 201, 'data' => []]);

        $result = $this->apiClient->addAutoprimary('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertTrue($result);
    }

    public function testAddAutoprimaryAccepts204Response(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->addAutoprimary('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result);
    }

    public function testDeleteAutoprimaryUsesEncodedEndpoint(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/api/v1/servers/localhost/autoprimaries/192.168.1.1/ns1.example.com')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->deleteAutoprimary('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // TSIG key operations
    // ---------------------------------------------------------------

    public function testGetTsigKeysReturnsData(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/tsigkeys')
            ->willReturn([
                'responseCode' => 200,
                'data' => [
                    ['name' => 'test.', 'id' => 'test.', 'algorithm' => 'hmac-sha256'],
                ],
            ]);

        $result = $this->apiClient->getTsigKeys();

        $this->assertCount(1, $result);
        $this->assertEquals('test.', $result[0]['name']);
    }

    public function testGetTsigKeysReturnsEmptyOnError(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 500, 'data' => []]);

        $result = $this->apiClient->getTsigKeys();

        $this->assertEmpty($result);
    }

    public function testCreateTsigKeyReturnsDataOnSuccess(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/api/v1/servers/localhost/tsigkeys', [
                'name' => 'mykey.',
                'algorithm' => 'hmac-sha256',
            ])
            ->willReturn([
                'responseCode' => 201,
                'data' => ['name' => 'mykey.', 'algorithm' => 'hmac-sha256', 'key' => 'generated-secret'],
            ]);

        $result = $this->apiClient->createTsigKey('mykey.', 'hmac-sha256');

        $this->assertNotNull($result);
        $this->assertEquals('mykey.', $result['name']);
    }

    public function testCreateTsigKeyWithExplicitKey(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/api/v1/servers/localhost/tsigkeys', [
                'name' => 'mykey.',
                'algorithm' => 'hmac-sha256',
                'key' => 'my-secret-key',
            ])
            ->willReturn(['responseCode' => 201, 'data' => ['name' => 'mykey.']]);

        $result = $this->apiClient->createTsigKey('mykey.', 'hmac-sha256', 'my-secret-key');

        $this->assertNotNull($result);
    }

    public function testCreateTsigKeyReturnsNullOnFailure(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 422, 'data' => []]);

        $result = $this->apiClient->createTsigKey('bad.', 'invalid');

        $this->assertNull($result);
    }

    public function testDeleteTsigKeyReturnsTrue(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/api/v1/servers/localhost/tsigkeys/mykey.')
            ->willReturn(['responseCode' => 204, 'data' => []]);

        $result = $this->apiClient->deleteTsigKey('mykey.');

        $this->assertTrue($result);
    }

    public function testDeleteTsigKeyReturnsFalse(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $result = $this->apiClient->deleteTsigKey('nonexistent.');

        $this->assertFalse($result);
    }

    public function testUpdateTsigKeyReturnsTrue(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->with('PUT', '/api/v1/servers/localhost/tsigkeys/mykey.', ['algorithm' => 'hmac-sha512'])
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'mykey.']]);

        $result = $this->apiClient->updateTsigKey('mykey.', ['algorithm' => 'hmac-sha512']);

        $this->assertTrue($result);
    }

    public function testUpdateTsigKeyReturnsFalse(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 404, 'data' => []]);

        $result = $this->apiClient->updateTsigKey('nonexistent.', ['algorithm' => 'hmac-sha512']);

        $this->assertFalse($result);
    }
}
