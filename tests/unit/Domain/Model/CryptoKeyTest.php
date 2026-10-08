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
        $this->assertSame([], $ds);
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
        $this->assertSame([], $key->getDs());
    }

    public function testGetDsAlwaysReturnsArray(): void
    {
        $key1 = new CryptoKey(id: 1, ds: null);
        $this->assertSame([], $key1->getDs());

        $key2 = new CryptoKey(id: 2, ds: []);
        $this->assertSame([], $key2->getDs());

        $key3 = new CryptoKey(id: 3, ds: ['test']);
        $this->assertSame(['test'], $key3->getDs());
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
        $this->assertSame([], $key->getDs());
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
        $this->assertSame($dsRecords, $key->getDs());
    }

    // The DNSKEY from RFC 4034 section 5.4, whose key tag is 60485
    private const RFC_DNSKEY = '256 3 5 AQOeiiR0GOMYkDshWoSKz9XzfwJr1AYtsmx3TGkJaNXVbfi/2pHm822aJ5iI9BMzNXxeYCmZDRD99WYwYqUSdjMmmAphXdvxegXd/M5+X7OrzKBaMbCVdFLUUh6DhweJBjEVv5f2wwjM9XzcnOf+EPbtG9DMBmADjFDc2w/rljwvFw==';

    public function testKeyTagIsComputedFromTheDnskey(): void
    {
        $key = new CryptoKey(1, 'zsk', 1024, 'RSASHA1', true, self::RFC_DNSKEY, []);

        $this->assertSame(60485, $key->getKeyTag());
        $this->assertSame(5, $key->getAlgorithmId());
    }

    public function testKeyTagIgnoresSpacesInsideThePublicKey(): void
    {
        $split = substr(self::RFC_DNSKEY, 0, 40) . ' ' . substr(self::RFC_DNSKEY, 40);

        $this->assertSame(60485, (new CryptoKey(1, dnskey: $split))->getKeyTag());
    }

    public function testAKeyWithoutAReadableDnskeyHasNoKeyTag(): void
    {
        $this->assertSame(0, (new CryptoKey(1))->getKeyTag());
        $this->assertSame(0, (new CryptoKey(1))->getAlgorithmId());
        $this->assertSame(0, (new CryptoKey(1, dnskey: '257 3'))->getKeyTag());
        $this->assertSame(0, (new CryptoKey(1, dnskey: '257 3'))->getAlgorithmId());
        $this->assertSame(0, (new CryptoKey(1, dnskey: '257 3 13 !!!'))->getKeyTag());
        $this->assertSame(13, (new CryptoKey(1, dnskey: '257 3 13 !!!'))->getAlgorithmId());
    }

    /** @return array<string, array{0: ?string, 1: ?bool}> */
    public static function secureEntryPointProvider(): array
    {
        return [
            'ksk or csk' => ['257 3 13 AAAA', true],
            'zsk' => ['256 3 13 AAAA', false],
            'revoked ksk' => ['385 3 13 AAAA', true],
            'no dnskey' => [null, null],
            'unreadable flags' => ['x 3 13 AAAA', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secureEntryPointProvider')]
    public function testIsSecureEntryPointReadsTheStoredFlag(?string $dnskey, ?bool $expected): void
    {
        $key = new CryptoKey(1, 'csk', 256, 'ECDSAP256SHA256', true, $dnskey, []);

        $this->assertSame($expected, $key->isSecureEntryPoint());
    }
}
