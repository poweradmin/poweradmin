<?php

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;

class CryptoKeyTest extends TestCase
{
    public function testConstructorWithAllParameters(): void
    {
        $key = new CryptoKey(
            id: 1,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-string',
            ds: ['12345 13 2 abcdef...', '12345 13 4 123456...']
        );

        $this->assertEquals(1, $key->getId());
        $this->assertEquals('ksk', $key->getType());
        $this->assertEquals(256, $key->getSize());
        $this->assertEquals('ECDSAP256SHA256', $key->getAlgorithm());
        $this->assertTrue($key->isActive());
        $this->assertEquals('example-dnskey-string', $key->getDnskey());
        $this->assertEquals(['12345 13 2 abcdef...', '12345 13 4 123456...'], $key->getDs());
    }

    public function testConstructorWithNullDsInitializesToEmptyArray(): void
    {
        $key = new CryptoKey(
            id: 1,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: false,
            dnskey: 'example-dnskey-string',
            ds: null
        );

        $ds = $key->getDs();
        $this->assertIsArray($ds);
        $this->assertEmpty($ds);
    }

    public function testConstructorWithMinimalParameters(): void
    {
        $key = new CryptoKey(id: 1);

        $this->assertEquals(1, $key->getId());
        $this->assertNull($key->getType());
        $this->assertNull($key->getSize());
        $this->assertNull($key->getAlgorithm());
        $this->assertFalse($key->isActive());
        $this->assertNull($key->getDnskey());
        $this->assertIsArray($key->getDs());
        $this->assertEmpty($key->getDs());
    }

    public function testGetDsAlwaysReturnsArray(): void
    {
        $key1 = new CryptoKey(id: 1, ds: null);
        $this->assertIsArray($key1->getDs());

        $key2 = new CryptoKey(id: 2, ds: []);
        $this->assertIsArray($key2->getDs());

        $key3 = new CryptoKey(id: 3, ds: ['test']);
        $this->assertIsArray($key3->getDs());
    }

    public function testActivateAndDeactivate(): void
    {
        $key = new CryptoKey(id: 1, isActive: false);
        $this->assertFalse($key->isActive());

        $key->activate();
        $this->assertTrue($key->isActive());

        $key->deactivate();
        $this->assertFalse($key->isActive());
    }

    public function testZskKeyWithoutDsRecords(): void
    {
        $key = new CryptoKey(
            id: 1,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-string',
            ds: null
        );

        $this->assertEquals('zsk', $key->getType());
        $this->assertIsArray($key->getDs());
        $this->assertEmpty($key->getDs());
    }

    public function testKskKeyWithDsRecords(): void
    {
        $dsRecords = ['12345 13 2 abcdef...', '12345 13 4 123456...'];
        $key = new CryptoKey(
            id: 2,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'example-dnskey-string',
            ds: $dsRecords
        );

        $this->assertEquals('ksk', $key->getType());
        $this->assertIsArray($key->getDs());
        $this->assertCount(2, $key->getDs());
        $this->assertEquals($dsRecords, $key->getDs());
    }
}
