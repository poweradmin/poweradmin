<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneCountService;

class ZoneCountServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    public function testOwnPassesTheLoggedInUser(): void
    {
        $_SESSION['userid'] = 5;
        $repository = $this->createMock(ZoneReadRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('countZones')
            ->with('own', 5, 'a', 'reverse')
            ->willReturn(3);

        $service = new ZoneCountService($repository, new UserContextService());

        $this->assertSame(3, $service->countZones('own', 'a', 'reverse'));
    }

    public function testAllPassesNoUser(): void
    {
        $_SESSION['userid'] = 5;
        $repository = $this->createMock(ZoneReadRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('countZones')
            ->with('all', null, 'all', 'forward')
            ->willReturn(7);

        $service = new ZoneCountService($repository, new UserContextService());

        $this->assertSame(7, $service->countZones('all'));
    }
}
