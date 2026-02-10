<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneGroup;

#[CoversClass(ZoneGroup::class)]
class ZoneGroupTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $zoneGroup = new ZoneGroup(1, 100, 5, '2025-01-01 00:00:00', 'example.com', 'MASTER');

        $this->assertSame(1, $zoneGroup->getId());
        $this->assertSame(100, $zoneGroup->getDomainId());
        $this->assertSame(5, $zoneGroup->getGroupId());
        $this->assertSame('2025-01-01 00:00:00', $zoneGroup->getCreatedAt());
        $this->assertSame('example.com', $zoneGroup->getName());
        $this->assertSame('MASTER', $zoneGroup->getType());
    }

    #[Test]
    public function constructorWithOptionalDefaults(): void
    {
        $zoneGroup = new ZoneGroup(null, 50, 3);

        $this->assertNull($zoneGroup->getId());
        $this->assertSame(50, $zoneGroup->getDomainId());
        $this->assertSame(3, $zoneGroup->getGroupId());
        $this->assertNull($zoneGroup->getCreatedAt());
        $this->assertNull($zoneGroup->getName());
        $this->assertNull($zoneGroup->getType());
    }

    #[Test]
    public function createFactorySetsOnlyDomainIdAndGroupId(): void
    {
        $zoneGroup = ZoneGroup::create(200, 10);

        $this->assertNull($zoneGroup->getId());
        $this->assertSame(200, $zoneGroup->getDomainId());
        $this->assertSame(10, $zoneGroup->getGroupId());
        $this->assertNull($zoneGroup->getCreatedAt());
        $this->assertNull($zoneGroup->getName());
        $this->assertNull($zoneGroup->getType());
    }

    #[Test]
    public function constructorWithDenormalizedZoneData(): void
    {
        $zoneGroup = new ZoneGroup(7, 42, 3, '2025-06-15 10:30:00', 'slave-zone.example.com', 'SLAVE');

        $this->assertSame('slave-zone.example.com', $zoneGroup->getName());
        $this->assertSame('SLAVE', $zoneGroup->getType());
    }

    #[Test]
    public function constructorWithNativeZoneType(): void
    {
        $zoneGroup = new ZoneGroup(8, 43, 4, null, 'native-zone.example.com', 'NATIVE');

        $this->assertSame('NATIVE', $zoneGroup->getType());
    }
}
