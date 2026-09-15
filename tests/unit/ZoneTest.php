<?php

namespace Poweradmin\Tests\Unit;

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
        $this->assertSame($key, $zone->getKeys()[0]);
    }
}
