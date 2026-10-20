<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneCountService;
use TestHelpers\StubActor;

class ZoneCountServiceTest extends TestCase
{
    public function testOwnPassesTheActingUser(): void
    {
        $repository = $this->createMock(ZoneReadRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('countZones')
            ->with('own', 5, 'a', 'reverse')
            ->willReturn(3);

        $service = new ZoneCountService($repository, new StubActor(5));

        $this->assertSame(3, $service->countZones('own', 'a', 'reverse'));
    }

    public function testAllPassesNoUser(): void
    {
        $repository = $this->createMock(ZoneReadRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('countZones')
            ->with('all', null, 'all', 'forward')
            ->willReturn(7);

        $service = new ZoneCountService($repository, new StubActor(5));

        $this->assertSame(7, $service->countZones('all'));
    }
}
