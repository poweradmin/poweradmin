<?php

namespace Poweradmin\Tests\Unit\Domain\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Utility\DnssecDataTransformer;

class DnssecDataTransformerTest extends TestCase
{
    private DnssecDataTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new DnssecDataTransformer();
    }

    public function testTransformKeyWithValidData(): void
    {
        $key = new CryptoKey(
            id: 1,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: '257 3 13 mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==',
            ds: ['31406 13 2 a]cdef1234567890']
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
        $this->assertEquals(1, $result[0]);
        $this->assertEquals('KSK', $result[1]);
        $this->assertEquals('31406', $result[2]);
        $this->assertEquals('13', $result[3]);
        $this->assertEquals(256, $result[4]);
        $this->assertTrue($result[5]);
    }

    public function testTransformKeyWithEmptyDs(): void
    {
        $key = new CryptoKey(
            id: 2,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: false,
            dnskey: '256 3 13 oJMRESz5E4gYzS/q6XDrvU1qMPYIjCWzJaOau8XNEZeqCYKD5ar0IRd8KqXXFJkqmVfRvMGPmM1x8fGAa2XhSA==',
            ds: []
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
        $this->assertEquals(2, $result[0]);
        $this->assertEquals('ZSK', $result[1]);
    }

    public function testTransformKeyWithNullDnskey(): void
    {
        $key = new CryptoKey(
            id: 3,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: null,
            ds: ['31406 13 2 abcdef']
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
    }

    public function testTransformKeyWithInsufficientDnskeyParts(): void
    {
        $key = new CryptoKey(
            id: 4,
            type: 'ksk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: '257',
            ds: ['31406 13 2 abcdef']
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
    }

    public function testTransformKeyWithEmptyDsString(): void
    {
        $key = new CryptoKey(
            id: 5,
            type: 'zsk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: false,
            dnskey: '256 3 13 keydata',
            ds: ['']
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
    }

    public function testTransformKeyWithSingleElementDnskey(): void
    {
        $key = new CryptoKey(
            id: 6,
            type: 'csk',
            size: 256,
            algorithm: 'ECDSAP256SHA256',
            isActive: true,
            dnskey: 'onlyonevalue',
            ds: ['12345 8 2 hash']
        );

        $result = $this->transformer->transformKey($key);

        $this->assertIsArray($result);
        $this->assertCount(6, $result);
    }
}
