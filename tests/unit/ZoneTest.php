<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;

#[CoversClass(Zone::class)]
class ZoneTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $zone = new Zone('example.com');

        $this->assertSame('example.com', $zone->getName());
        $this->assertFalse($zone->isSecured());
        $this->assertEmpty($zone->getKeys());
    }

    #[Test]
    public function constructorWithSecuredAndKeys(): void
    {
        $key = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);
        $zone = new Zone('example.com', true, [$key]);

        $this->assertTrue($zone->isSecured());
        $this->assertCount(1, $zone->getKeys());
    }

    #[Test]
    public function secureAndUnsecureToggle(): void
    {
        $zone = new Zone('example.com');

        $this->assertFalse($zone->isSecured());

        $zone->secure();
        $this->assertTrue($zone->isSecured());

        $zone->unsecure();
        $this->assertFalse($zone->isSecured());
    }

    #[Test]
    public function addKey(): void
    {
        $zone = new Zone('example.com');
        $key = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);

        $zone->addKey($key);

        $this->assertCount(1, $zone->getKeys());
        $this->assertSame($key, $zone->getKeys()[0]);
    }

    #[Test]
    public function addMultipleKeys(): void
    {
        $zone = new Zone('example.com');
        $ksk = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);
        $zsk = new CryptoKey(2, 'ZSK', 1024, 'RSASHA256', true);

        $zone->addKey($ksk);
        $zone->addKey($zsk);

        $this->assertCount(2, $zone->getKeys());
    }

    #[Test]
    public function removeKey(): void
    {
        $zone = new Zone('example.com');
        $key1 = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);
        $key2 = new CryptoKey(2, 'ZSK', 1024, 'RSASHA256', true);

        $zone->addKey($key1);
        $zone->addKey($key2);
        $zone->removeKey(1);

        $this->assertCount(1, $zone->getKeys());
        $this->assertSame(2, $zone->getKeys()[0]->getId());
    }

    #[Test]
    public function removeNonExistentKeyDoesNothing(): void
    {
        $zone = new Zone('example.com');
        $key = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);

        $zone->addKey($key);
        $zone->removeKey(999);

        $this->assertCount(1, $zone->getKeys());
    }

    #[Test]
    public function getKeyReturnsKeyById(): void
    {
        $zone = new Zone('example.com');
        $key = new CryptoKey(5, 'KSK', 2048, 'RSASHA256', true);

        $zone->addKey($key);

        $found = $zone->getKey(5);
        $this->assertSame($key, $found);
    }

    #[Test]
    public function getKeyReturnsNullForMissing(): void
    {
        $zone = new Zone('example.com');

        $this->assertNull($zone->getKey(999));
    }

    #[Test]
    public function activateKey(): void
    {
        $zone = new Zone('example.com');
        $key = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', false);

        $zone->addKey($key);
        $this->assertFalse($zone->getKey(1)->isActive());

        $zone->activateKey(1);
        $this->assertTrue($zone->getKey(1)->isActive());
    }

    #[Test]
    public function deactivateKey(): void
    {
        $zone = new Zone('example.com');
        $key = new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true);

        $zone->addKey($key);
        $this->assertTrue($zone->getKey(1)->isActive());

        $zone->deactivateKey(1);
        $this->assertFalse($zone->getKey(1)->isActive());
    }

    #[Test]
    public function activateNonExistentKeyDoesNothing(): void
    {
        $zone = new Zone('example.com');

        $zone->activateKey(999);

        $this->assertEmpty($zone->getKeys());
    }

    #[Test]
    public function removeKeyReindexesArray(): void
    {
        $zone = new Zone('example.com');
        $zone->addKey(new CryptoKey(1, 'KSK', 2048, 'RSASHA256', true));
        $zone->addKey(new CryptoKey(2, 'ZSK', 1024, 'RSASHA256', true));
        $zone->addKey(new CryptoKey(3, 'ZSK', 1024, 'RSASHA256', false));

        $zone->removeKey(2);

        $keys = $zone->getKeys();
        $this->assertCount(2, $keys);
        $this->assertArrayHasKey(0, $keys);
        $this->assertArrayHasKey(1, $keys);
        $this->assertSame(1, $keys[0]->getId());
        $this->assertSame(3, $keys[1]->getId());
    }
}
