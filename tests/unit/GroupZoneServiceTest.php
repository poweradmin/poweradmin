<?php

namespace unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\GroupZoneService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;

#[CoversClass(GroupZoneService::class)]
class GroupZoneServiceTest extends TestCase
{
    private MockObject&ZoneGroupRepositoryInterface $zoneRepo;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private GroupZoneService $service;

    protected function setUp(): void
    {
        $this->zoneRepo = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->service = new GroupZoneService($this->zoneRepo, $this->groupRepo);
    }

    // --- addZoneToGroup ---

    #[Test]
    public function addZoneToGroupHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->method('exists')->with(100, 1)->willReturn(false);
        $zg = ZoneGroup::create(100, 1);
        $this->zoneRepo->expects($this->once())->method('add')->with(100, 1)->willReturn($zg);

        $result = $this->service->addZoneToGroup(1, 100);

        $this->assertSame(100, $result->getDomainId());
        $this->assertSame(1, $result->getGroupId());
    }

    #[Test]
    public function addZoneToGroupGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->addZoneToGroup(999, 100);
    }

    #[Test]
    public function addZoneToGroupAlreadyExistsThrows(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->method('exists')->with(100, 1)->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Zone is already owned by this group');

        $this->service->addZoneToGroup(1, 100);
    }

    // --- removeZoneFromGroup ---

    #[Test]
    public function removeZoneFromGroupHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->expects($this->once())->method('remove')->with(100, 1)->willReturn(true);

        $this->assertTrue($this->service->removeZoneFromGroup(1, 100));
    }

    #[Test]
    public function removeZoneFromGroupGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->removeZoneFromGroup(999, 100);
    }

    // --- listGroupZones ---

    #[Test]
    public function listGroupZonesReturnsZones(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $zones = [
            new ZoneGroup(1, 100, 1, null, 'example.com', 'MASTER'),
            new ZoneGroup(2, 101, 1, null, 'test.com', 'NATIVE'),
        ];
        $this->zoneRepo->method('findByGroupId')->with(1)->willReturn($zones);

        $result = $this->service->listGroupZones(1);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function listGroupZonesGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->listGroupZones(999);
    }

    // --- listZoneGroups ---

    #[Test]
    public function listZoneGroupsDelegatesToRepository(): void
    {
        $groups = [new ZoneGroup(1, 100, 1), new ZoneGroup(2, 100, 2)];
        $this->zoneRepo->expects($this->once())->method('findByDomainId')->with(100)->willReturn($groups);

        $result = $this->service->listZoneGroups(100);

        $this->assertCount(2, $result);
    }

    // --- bulkAddZones ---

    #[Test]
    public function bulkAddZonesAllSucceed(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->method('exists')->willReturn(false);
        $this->zoneRepo->method('add')->willReturn(ZoneGroup::create(1, 1));

        $results = $this->service->bulkAddZones(1, [100, 101, 102]);

        $this->assertCount(3, $results['success']);
        $this->assertEmpty($results['failed']);
    }

    #[Test]
    public function bulkAddZonesPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->method('exists')->willReturnMap([
            [100, 1, false],
            [101, 1, true],
        ]);
        $this->zoneRepo->method('add')->willReturn(ZoneGroup::create(1, 1));

        $results = $this->service->bulkAddZones(1, [100, 101]);

        $this->assertCount(1, $results['success']);
        $this->assertCount(1, $results['failed']);
        $this->assertSame('Already owned by this group', $results['failed'][101]);
    }

    // --- bulkRemoveZones ---

    #[Test]
    public function bulkRemoveZonesPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneRepo->method('remove')->willReturnMap([
            [100, 1, true],
            [101, 1, false],
        ]);

        $results = $this->service->bulkRemoveZones(1, [100, 101]);

        $this->assertCount(1, $results['success']);
        $this->assertCount(1, $results['failed']);
        $this->assertSame('Not owned by this group', $results['failed'][101]);
    }

    // --- isZoneOwnedByGroup ---

    #[Test]
    public function isZoneOwnedByGroupDelegatesToRepository(): void
    {
        $this->zoneRepo->method('exists')->with(100, 1)->willReturn(true);
        $this->assertTrue($this->service->isZoneOwnedByGroup(1, 100));
    }
}
