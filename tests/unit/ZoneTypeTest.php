<?php

namespace Poweradmin\Tests\Unit;

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

    #[Test]
    public function isReadOnlyTrueForSlaveAndConsumer(): void
    {
        $this->assertTrue(ZoneType::isReadOnly('SLAVE'));
        $this->assertTrue(ZoneType::isReadOnly('CONSUMER'));
        $this->assertTrue(ZoneType::isReadOnly('consumer'));
    }

    #[Test]
    public function isReadOnlyFalseForEditableTypes(): void
    {
        $this->assertFalse(ZoneType::isReadOnly('MASTER'));
        $this->assertFalse(ZoneType::isReadOnly('NATIVE'));
        $this->assertFalse(ZoneType::isReadOnly('PRODUCER'));
        $this->assertFalse(ZoneType::isReadOnly(''));
        $this->assertFalse(ZoneType::isReadOnly(null));
    }
}
