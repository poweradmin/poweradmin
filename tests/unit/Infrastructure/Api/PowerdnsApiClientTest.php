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

    public function testRetrieveZoneTriggersAxfrRetrieve(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('PUT', '/api/v1/servers/localhost/zones/example.com./axfr-retrieve')
            ->willReturn(['responseCode' => 200]);

        $this->assertTrue($this->apiClient->retrieveZone('example.com.'));
    }

    public function testRetrieveZoneReturnsFalseOnNonSuccess(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('PUT', '/api/v1/servers/localhost/zones/example.com./axfr-retrieve')
            ->willReturn(['responseCode' => 422]);

        $this->assertFalse($this->apiClient->retrieveZone('example.com.'));
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

    public function testGetZoneRequestsTheWholeZoneByDefault(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.')
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'example.com.']]);

        $this->apiClient->getZone('example.com.');
    }

    public function testGetZoneCanNarrowToASingleRrset(): void
    {
        // Lets the SOA-health probe read one RRset instead of downloading the
        // whole zone. PowerDNS below 4.7 ignores these and returns everything.
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.?rrset_name=example.com.&rrset_type=SOA')
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'example.com.', 'rrsets' => []]]);

        $this->apiClient->getZoneRrset('example.com.', 'example.com.', 'SOA');
    }

    public function testGetZoneCombinesRrsetsFalseWithTheRrsetFilter(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.?rrsets=false&rrset_name=www.example.com.')
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'example.com.']]);

        $this->apiClient->getZone('example.com.', false, ['rrset_name' => 'www.example.com.']);
    }

    public function testGetAllZonesOmitsDnssecFlagByDefault(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones')
            ->willReturn(['responseCode' => 200, 'data' => []]);

        $this->apiClient->getAllZones();
    }

    public function testGetAllZonesCanSkipDnssecLookup(): void
    {
        // PowerDNS then skips a DNSSEC-keeper lookup per zone
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones?dnssec=false')
            ->willReturn(['responseCode' => 200, 'data' => []]);

        $this->apiClient->getAllZones(false);
    }

    public function testGetAllZoneStatsCanSkipDnssecLookup(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones?dnssec=false')
            ->willReturn(['responseCode' => 200, 'data' => []]);

        $this->apiClient->getAllZoneStats(false);
    }

    public function testGetAllZoneKindsNeverPaysForTheDnssecLookup(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones?dnssec=false')
            ->willReturn(['responseCode' => 200, 'data' => []]);

        $this->apiClient->getAllZoneKinds();
    }

    public function testGetAllZoneStatsReportsNoRecordCount(): void
    {
        // PowerDNS's zone list carries no record count - callers must not expect one
        $this->mockHttpClient
            ->method('makeRequest')
            ->willReturn(['responseCode' => 200, 'data' => [
                ['name' => 'example.com.', 'dnssec' => true, 'serial' => 5, 'edited_serial' => 6, 'notified_serial' => 4],
            ]]);

        $stats = $this->apiClient->getAllZoneStats();

        $this->assertArrayNotHasKey('rrset_count', $stats['example.com.']);
        $this->assertSame(5, $stats['example.com.']['serial']);
        $this->assertTrue($stats['example.com.']['dnssec']);
    }

    // ---------------------------------------------------------------
    // Per-request GET cache
    // ---------------------------------------------------------------

    public function testRepeatedGetsOfTheSameEndpointIssueOneRequest(): void
    {
        // One page render asks for the zone list several times over
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones')
            ->willReturn(['responseCode' => 200, 'data' => [['name' => 'example.com.', 'dnssec' => false]]]);

        $this->apiClient->getAllZones();
        $this->apiClient->getAllZones();
        $stats = $this->apiClient->getAllZoneStats();

        $this->assertArrayHasKey('example.com.', $stats);
    }

    public function testDifferentEndpointsAreCachedSeparately(): void
    {
        $seen = [];
        $this->mockHttpClient
            ->expects($this->exactly(2))
            ->method('makeRequest')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$seen): array {
                $seen[] = $endpoint;
                return ['responseCode' => 200, 'data' => []];
            });

        $this->apiClient->getAllZones();
        $this->apiClient->getAllZones(false);

        $this->assertSame([
            '/api/v1/servers/localhost/zones',
            '/api/v1/servers/localhost/zones?dnssec=false',
        ], $seen);
    }

    public function testWriteClearsTheCacheSoLaterReadsSeeFreshState(): void
    {
        // Guards the check-then-create loops in BatchReverseRecordCreator: a
        // second existence check after a write must not be served from the
        // pre-write body, or duplicate records get created.
        $calls = [];
        $this->mockHttpClient
            ->method('makeRequest')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$calls): array {
                $calls[] = $method . ' ' . $endpoint;
                return ['responseCode' => 200, 'data' => ['name' => 'example.com.', 'rrsets' => []]];
            });

        $this->apiClient->getZone('example.com.');
        $this->apiClient->getZone('example.com.');
        $this->apiClient->patchZoneRRsets('example.com.', ['rrsets' => []]);
        $this->apiClient->getZone('example.com.');

        $this->assertSame([
            'GET /api/v1/servers/localhost/zones/example.com.',
            'PATCH /api/v1/servers/localhost/zones/example.com.',
            'GET /api/v1/servers/localhost/zones/example.com.',
        ], $calls, 'the write must evict the cached body');
    }

    public function testAWholeZoneBodyAnswersALaterFilteredReadOfTheSameZone(): void
    {
        // The record count fetches the whole body; the SOA badge then wants one
        // RRset out of it. Asking PowerDNS again would double the per-row cost,
        // and the reused body must be narrowed to what the filter asked for.
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.')
            ->willReturn(['responseCode' => 200, 'data' => [
                'name' => 'example.com.',
                'rrsets' => [
                    ['name' => 'example.com.', 'type' => 'SOA', 'records' => []],
                    ['name' => 'example.com.', 'type' => 'NS', 'records' => []],
                    ['name' => 'www.example.com.', 'type' => 'A', 'records' => []],
                ],
            ]]);

        $this->apiClient->getZone('example.com.');
        $rrset = $this->apiClient->getZoneRrset('example.com.', 'example.com.', 'SOA');

        $this->assertSame('example.com.', $rrset['name']);
        $this->assertCount(1, $rrset['rrsets']);
        $this->assertSame('SOA', $rrset['rrsets'][0]['type']);
    }

    public function testAWholeZoneBodyAnswersALaterRecordlessReadOfTheSameZone(): void
    {
        // Callers reading only zone-level fields must not pay for a second
        // round trip, and must see the same shape a rrsets=false response has.
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.')
            ->willReturn(['responseCode' => 200, 'data' => [
                'name' => 'example.com.',
                'kind' => 'Master',
                'soa_edit_api' => 'DEFAULT',
                'rrsets' => [
                    ['name' => 'example.com.', 'type' => 'SOA', 'records' => []],
                ],
            ]]);

        $this->apiClient->getZone('example.com.');
        $zone = $this->apiClient->getZone('example.com.', false);

        $this->assertSame('DEFAULT', $zone['soa_edit_api']);
        $this->assertArrayNotHasKey('rrsets', $zone);
    }

    public function testGetZoneRrsetWithoutATypeAsksForEveryTypeAtTheName(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.?rrset_name=www.example.com.')
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'example.com.', 'rrsets' => []]]);

        $this->assertNotNull($this->apiClient->getZoneRrset('example.com.', 'www.example.com.'));
    }

    public function testAHeldBodyNarrowsTheNameCaseInsensitively(): void
    {
        // PowerDNS matches owner names case-insensitively. If the reused body did
        // not, a narrowed read would come back empty and callers that validate
        // against it would silently see no conflicting records.
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['responseCode' => 200, 'data' => [
                'name' => 'example.com.',
                'rrsets' => [['name' => 'WWW.example.com.', 'type' => 'A', 'records' => []]],
            ]]);

        $this->apiClient->getZone('example.com.');
        $rrset = $this->apiClient->getZoneRrset('example.com.', 'www.example.com.', 'A');

        $this->assertCount(1, $rrset['rrsets']);
    }

    public function testRepeatedNarrowedReadsOfTheSameRrsetCostOneRequest(): void
    {
        // A single save reads the same RRset several times, and a narrowed body
        // is never stored as the whole-zone answer, so without this it pays for
        // each read.
        $calls = [];
        $this->mockHttpClient
            ->method('makeRequest')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$calls): array {
                $calls[] = $method . ' ' . $endpoint;
                return ['responseCode' => 200, 'data' => ['name' => 'example.com.', 'rrsets' => []]];
            });

        $this->apiClient->getZoneRrset('example.com.', 'www.example.com.', 'A');
        $this->apiClient->getZoneRrset('example.com.', 'www.example.com.', 'A');
        $this->apiClient->patchZoneRRsets('example.com.', ['rrsets' => []]);
        $this->apiClient->getZoneRrset('example.com.', 'www.example.com.', 'A');

        $narrowed = '/api/v1/servers/localhost/zones/example.com.?rrset_name=www.example.com.&rrset_type=A';
        $this->assertSame([
            'GET ' . $narrowed,
            'PATCH /api/v1/servers/localhost/zones/example.com.',
            'GET ' . $narrowed,
        ], $calls, 'the write must evict the cached narrowed body');
    }

    public function testNarrowedReadsOfDifferentRrsetsAreNotConfused(): void
    {
        $this->mockHttpClient
            ->expects($this->exactly(2))
            ->method('makeRequest')
            ->willReturnCallback(fn(string $method, string $endpoint): array => [
                'responseCode' => 200,
                'data' => ['name' => 'example.com.', 'endpoint' => $endpoint],
            ]);

        $first = $this->apiClient->getZoneRrset('example.com.', 'www.example.com.', 'A');
        $second = $this->apiClient->getZoneRrset('example.com.', 'mail.example.com.', 'MX');

        $this->assertStringContainsString('rrset_name=www.example.com.', $first['endpoint']);
        $this->assertStringContainsString('rrset_name=mail.example.com.', $second['endpoint']);
    }

    public function testARecordlessReadStillGoesToTheServerWhenNoBodyIsHeld(): void
    {
        $this->mockHttpClient
            ->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/api/v1/servers/localhost/zones/example.com.?rrsets=false')
            ->willReturn(['responseCode' => 200, 'data' => ['name' => 'example.com.', 'kind' => 'Master']]);

        $zone = $this->apiClient->getZone('example.com.', false);

        $this->assertSame('Master', $zone['kind']);
    }

    public function testOnlyOneZoneBodyIsHeldAtATime(): void
    {
        // Listing pages loop over a page of zones; retaining every body would
        // scale memory with the page size
        $calls = [];
        $this->mockHttpClient
            ->method('makeRequest')
            ->willReturnCallback(function (string $method, string $endpoint) use (&$calls): array {
                $calls[] = $endpoint;
                return ['responseCode' => 200, 'data' => ['name' => 'zone.', 'rrsets' => []]];
            });

        $this->apiClient->getZone('a.example.com.');
        $this->apiClient->getZone('b.example.com.');
        $this->apiClient->getZone('a.example.com.');

        $this->assertSame([
            '/api/v1/servers/localhost/zones/a.example.com.',
            '/api/v1/servers/localhost/zones/b.example.com.',
            '/api/v1/servers/localhost/zones/a.example.com.',
        ], $calls, 'a second zone must evict the first');
    }

    public function testAFilteredFetchIsNotReusedAsAWholeZoneBody(): void
    {
        // A filtered response holds a subset, so it must not satisfy a caller
        // asking for the entire zone
        $calls = 0;
        $this->mockHttpClient
            ->method('makeRequest')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return ['responseCode' => 200, 'data' => ['name' => 'example.com.', 'rrsets' => []]];
            });

        $this->apiClient->getZoneRrset('example.com.', 'example.com.', 'SOA');
        $this->apiClient->getZone('example.com.');

        $this->assertSame(2, $calls);
    }
}
