<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    }
}
