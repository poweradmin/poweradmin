<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneType;

#[CoversClass(ZoneType::class)]
class ZoneTypeTest extends TestCase
{
    #[Test]
    public function constantsMasterSlaveNative(): void
    {
        $this->assertSame('MASTER', ZoneType::MASTER);
        $this->assertSame('SLAVE', ZoneType::SLAVE);
        $this->assertSame('NATIVE', ZoneType::NATIVE);
    }

    #[Test]
    public function getTypesReturnsAllThree(): void
    {
        $types = ZoneType::getTypes();

        $this->assertCount(3, $types);
        $this->assertContains('MASTER', $types);
        $this->assertContains('SLAVE', $types);
        $this->assertContains('NATIVE', $types);
    }
}
