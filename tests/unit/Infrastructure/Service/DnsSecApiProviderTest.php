<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Utility\DnssecTransformer;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Logger\LegacyLoggerInterface;
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
        $this->mockLogger = $this->createMock(LegacyLoggerInterface::class);
        $this->mockTransformer = $this->createMock(DnssecTransformer::class);

        $this->provider = new DnsSecApiProvider(
            $this->mockApiClient,
            $this->mockLogger,
            $this->mockTransformer,
            '192.168.1.1',
            'testuser'
        );
    }

    /**
     * Test that keyExists works correctly with integer comparison
     * This tests the core functionality that should work after bug fixes
     */
    public function testKeyExistsWithIntegerComparison(): void
    {
        // Mock keys with integer IDs (proper scenario)
        $key1 = $this->createMockCryptoKey(5);
        $key2 = $this->createMockCryptoKey(6);
        $mockKeys = [$key1, $key2];

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn($mockKeys);

        // Test with integer keyId
        $result = $this->provider->keyExists('example.com', 6);

        $this->assertTrue($result, 'keyExists should return true when key ID matches');
    }

    /**
     * Test that keyExists returns false when key doesn't exist
     */
    public function testKeyExistsReturnsFalseWhenKeyNotFound(): void
    {
        $key1 = $this->createMockCryptoKey(5);
        $mockKeys = [$key1];

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn($mockKeys);

        $result = $this->provider->keyExists('example.com', 999);

        $this->assertFalse($result, 'keyExists should return false when key ID not found');
    }

    /**
     * Test that getZoneKey works correctly
     */
    public function testGetZoneKeyReturnsCorrectKey(): void
    {
        $targetKey = $this->createMockCryptoKey(6);
        $mockKeys = [
            $this->createMockCryptoKey(5),
            $targetKey
        ];

        $expectedTransformedKey = ['id' => 6, 'type' => 'ksk', 'active' => true];

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn($mockKeys);

        $this->mockTransformer
            ->expects($this->once())
            ->method('transformKey')
            ->with($targetKey)
            ->willReturn($expectedTransformedKey);

        $result = $this->provider->getZoneKey('example.com', 6);

        $this->assertEquals($expectedTransformedKey, $result);
    }

    /**
     * Test that getZoneKey returns empty array when key not found
     */
    public function testGetZoneKeyReturnsEmptyArrayWhenKeyNotFound(): void
    {
        $mockKeys = [
            $this->createMockCryptoKey(5),
            $this->createMockCryptoKey(7)
        ];

        $this->mockApiClient
            ->expects($this->once())
            ->method('getZoneKeys')
            ->willReturn($mockKeys);

        $this->mockTransformer
            ->expects($this->never())
            ->method('transformKey');

        $result = $this->provider->getZoneKey('example.com', 6);

        $this->assertEquals([], $result);
    }

    /**
     * Test that keyExists works with multiple keys
     */
    public function testKeyExistsWithMultipleKeys(): void
    {
        $key1 = $this->createMockCryptoKey(5);
        $key2 = $this->createMockCryptoKey(6);
        $mockKeys = [$key1, $key2];

        $this->mockApiClient
            ->expects($this->exactly(2))
            ->method('getZoneKeys')
            ->willReturn($mockKeys);

        // Test finding first key
        $this->assertTrue($this->provider->keyExists('example.com', 5));

        // Test finding second key
        $this->assertTrue($this->provider->keyExists('example.com', 6));
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

    /**
     * Helper method to create mock CryptoKey with specific ID
     */
    private function createMockCryptoKey(int $id): CryptoKey
    {
        $mock = $this->createMock(CryptoKey::class);
        $mock->method('getId')->willReturn($id);
        return $mock;
    }
}
