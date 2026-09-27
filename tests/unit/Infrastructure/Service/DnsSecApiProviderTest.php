<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Utility\DnssecDataTransformer;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;
use Poweradmin\Infrastructure\Service\DnsSecApiProvider;

class DnsSecApiProviderTest extends TestCase
{
    private $mockApiClient;
    private $mockLogger;
    private $mockTransformer;
    private DnsSecApiProvider $provider;

    protected function setUp(): void
    {
        $this->mockApiClient = $this->createMock(PowerdnsApiClient::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockTransformer = $this->createMock(DnssecDataTransformer::class);

        $this->provider = new DnsSecApiProvider(
            $this->mockApiClient,
            $this->mockLogger,
            $this->mockTransformer,
            '192.168.1.1',
            'testuser'
        );
    }

    public function testGetDsRecordsWithKeysWithoutDsRecords(): void
    {
        $zskKey = new CryptoKey(
            id: 1,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-string',
            ds: null
        );

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([$zskKey]);

        $result = $this->provider->getDsRecords('example.com');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetDsRecordsWithKeysWithDsRecords(): void
    {
        $kskKey = new CryptoKey(
            id: 2,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-string',
            ds: ['12345 13 2 abcdef...', '12345 13 4 123456...']
        );

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([$kskKey]);

        $result = $this->provider->getDsRecords('example.com');

        $this->assertCount(2, $result);
        $this->assertStringContainsString('example.com. IN DS 12345', $result[0]);
        $this->assertStringContainsString('example.com. IN DS 12345', $result[1]);
    }

    public function testGetDsRecordsWithMixedKeyTypes(): void
    {
        $zskKey = new CryptoKey(
            id: 1,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'zsk-dnskey-string',
            ds: null
        );

        $kskKey = new CryptoKey(
            id: 2,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'ksk-dnskey-string',
            ds: ['67890 13 2 fedcba...']
        );

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([$zskKey, $kskKey]);

        $result = $this->provider->getDsRecords('example.com');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('example.com. IN DS 67890', $result[0]);
    }

    public function testGetDnsKeyRecordsWithSingleKey(): void
    {
        $key = new CryptoKey(
            id: 1,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-data',
            ds: ['12345 13 2 abcdef...']
        );

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([$key]);

        $result = $this->provider->getDnsKeyRecords('example.com');

        $this->assertCount(1, $result);
        $this->assertEquals('example.com. IN DNSKEY example-dnskey-data', $result[0]);
    }

    public function testGetDnsKeyRecordsWithMultipleKeys(): void
    {
        $zskKey = new CryptoKey(
            id: 1,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'zsk-dnskey-data',
            ds: null
        );

        $kskKey = new CryptoKey(
            id: 2,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'ksk-dnskey-data',
            ds: ['67890 13 2 fedcba...']
        );

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([$zskKey, $kskKey]);

        $result = $this->provider->getDnsKeyRecords('example.com');

        $this->assertCount(2, $result);
        $this->assertEquals('example.com. IN DNSKEY zsk-dnskey-data', $result[0]);
        $this->assertEquals('example.com. IN DNSKEY ksk-dnskey-data', $result[1]);
    }

    public function testGetDnsKeyRecordsReturnsEmptyArrayWhenNoKeys(): void
    {
        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn([]);

        $result = $this->provider->getDnsKeyRecords('example.com');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testIsZonePresignedReturnsTrueWhenMetadataIsOne(): void
    {
        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneMetadataKind')
            ->with($this->isInstanceOf(Zone::class), 'PRESIGNED')
            ->willReturn(['kind' => 'PRESIGNED', 'metadata' => ['1']]);

        $this->assertTrue($this->provider->isZonePresigned('example.com'));
    }

    public function testIsZonePresignedReturnsFalseWhenMetadataMissing(): void
    {
        $this->mockApiClient
            ->method('getZoneMetadataKind')
            ->willReturn(['kind' => 'PRESIGNED', 'metadata' => []]);

        $this->assertFalse($this->provider->isZonePresigned('example.com'));
    }

    public function testIsZonePresignedReturnsFalseOnApiError(): void
    {
        // getZoneMetadataKind returns [] when the API call fails
        $this->mockApiClient
            ->method('getZoneMetadataKind')
            ->willReturn([]);

        $this->assertFalse($this->provider->isZonePresigned('example.com'));
    }

    public function testIsZonePresignedReturnsFalseWhenMetadataNotOne(): void
    {
        $this->mockApiClient
            ->method('getZoneMetadataKind')
            ->willReturn(['kind' => 'PRESIGNED', 'metadata' => ['0']]);

        $this->assertFalse($this->provider->isZonePresigned('example.com'));
    }

    public function testGetEditedSerialReturnsServedSerial(): void
    {
        $this->mockApiClient
            ->expects($this->once())
            ->method('getZone')
            ->with('example.com.', false)
            ->willReturn(['name' => 'example.com.', 'dnssec' => true, 'serial' => 2024010101, 'edited_serial' => 2024010199]);

        $this->assertSame(2024010199, $this->provider->getEditedSerial('example.com'));
    }

    public function testGetEditedSerialReturnsNullForUnsignedZone(): void
    {
        // Unsigned zones serve the plain serial, so there is no signed serial to report
        $this->mockApiClient
            ->method('getZone')
            ->willReturn(['name' => 'example.com.', 'dnssec' => false, 'serial' => 2024010101, 'edited_serial' => 2024010101]);

        $this->assertNull($this->provider->getEditedSerial('example.com'));
    }

    public function testGetEditedSerialReturnsNullWhenZoneUnavailable(): void
    {
        // getZone returns null on API failure or unknown zone
        $this->mockApiClient
            ->method('getZone')
            ->willReturn(null);

        $this->assertNull($this->provider->getEditedSerial('example.com'));
    }

    public function testGetEditedSerialReturnsNullWhenFieldMissing(): void
    {
        // PowerDNS versions before 4.2 do not expose edited_serial
        $this->mockApiClient
            ->method('getZone')
            ->willReturn(['name' => 'example.com.', 'dnssec' => true, 'serial' => 2024010101]);

        $this->assertNull($this->provider->getEditedSerial('example.com'));
    }

    public function testFetchZoneKeysPassesAnOutageOnAsNull(): void
    {
        $this->mockApiClient->method('fetchZoneKeys')->willReturn(null);

        $this->assertNull($this->provider->fetchZoneKeys('example.com'));
    }

    public function testFetchZoneKeysReturnsTheZonesKeys(): void
    {
        $keys = [new CryptoKey(5, 'ksk'), new CryptoKey(6, 'zsk')];
        $this->mockApiClient->expects($this->once())->method('fetchZoneKeys')
            ->with($this->callback(fn(Zone $zone): bool => $zone->getName() === 'example.com'))
            ->willReturn($keys);

        $this->assertSame($keys, $this->provider->fetchZoneKeys('example.com'));
    }

    public function testCreateZoneKeyReturnsTheCreatedKeyAndLogsIt(): void
    {
        $created = new CryptoKey(9, 'csk', 256, 'ECDSAP256SHA256', true);
        $this->mockApiClient->expects($this->once())->method('createZoneKey')
            ->with(
                $this->callback(fn(Zone $zone): bool => $zone->getName() === 'example.com'),
                $this->callback(fn(CryptoKey $key): bool => $key->getType() === 'csk' && $key->getSize() === 256 && $key->getAlgorithm() === 'ecdsa256'),
                true
            )
            ->willReturn($created);
        $this->mockLogger->expects($this->once())->method('info')->with($this->stringContains('operation:dnssec_add_zone_key zone:example.com'));

        $this->assertSame($created, $this->provider->createZoneKey('example.com', 'csk', 256, 'ecdsa256', true));
    }

    public function testCreateZoneKeyReturnsNullWhenRefused(): void
    {
        $this->mockApiClient->method('createZoneKey')->willReturn(null);

        $this->assertNull($this->provider->createZoneKey('example.com', 'csk', 256, 'ecdsa256', false));
    }
}
