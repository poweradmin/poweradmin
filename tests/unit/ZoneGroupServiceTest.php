<?php

namespace Poweradmin\Tests\Unit;

use InvalidArgumentException;
use Poweradmin\Domain\Error\GroupNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;

#[CoversClass(ZoneGroupService::class)]
class ZoneGroupServiceTest extends TestCase
{
    private MockObject&ZoneGroupRepositoryInterface $zoneGroupRepo;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private MockObject&ZoneOwnershipGuard $ownershipGuard;
    private ZoneGroupService $service;

    protected function setUp(): void
    {
        $this->zoneGroupRepo = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->ownershipGuard = $this->createMock(ZoneOwnershipGuard::class);
        $this->service = new ZoneGroupService($this->zoneGroupRepo, $this->groupRepo, $this->ownershipGuard);
    }

    // --- addGroupToZone ---

    #[Test]
    public function addGroupToZoneHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->method('exists')->with(100, 1)->willReturn(false);
        $zg = ZoneGroup::create(100, 1);
        $this->zoneGroupRepo->expects($this->once())->method('add')->with(100, 1)->willReturn($zg);

        $result = $this->service->addGroupToZone(100, 1);

        $this->assertSame(100, $result->getDomainId());
    }

    #[Test]
    public function addGroupToZoneGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(GroupNotFoundException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->addGroupToZone(100, 999);
    }

    #[Test]
    public function addGroupToZoneAlreadyExistsThrows(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->method('exists')->with(100, 1)->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group already owns this zone');

        $this->service->addGroupToZone(100, 1);
    }

    // --- removeGroupFromZone ---

    #[Test]
    public function removeGroupFromZoneHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->expects($this->once())->method('remove')->with(100, 1)->willReturn(true);

        $this->assertTrue($this->service->removeGroupFromZone(100, 1));
    }

    #[Test]
    public function removeGroupFromZoneGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->removeGroupFromZone(100, 999);
    }

    // --- listZoneOwners ---

    #[Test]
    public function listZoneOwnersDelegatesToRepository(): void
    {
        $owners = [new ZoneGroup(1, 100, 1), new ZoneGroup(2, 100, 2)];
        $this->zoneGroupRepo->expects($this->once())->method('findByDomainId')->with(100)->willReturn($owners);

        $result = $this->service->listZoneOwners(100);

        $this->assertCount(2, $result);
    }

    // --- listGroupZones ---

    #[Test]
    public function listGroupZonesHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $zones = [new ZoneGroup(1, 100, 1, null, 'example.com', 'MASTER')];
        $this->zoneGroupRepo->method('findByGroupId')->with(1)->willReturn($zones);

        $result = $this->service->listGroupZones(1);

        $this->assertCount(1, $result);
    }

    #[Test]
    public function listGroupZonesGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->listGroupZones(999);
    }

    // --- bulkAddZones ---

    #[Test]
    public function bulkAddZonesAllSucceed(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->method('exists')->willReturn(false);
        $this->zoneGroupRepo->method('add')->willReturn(ZoneGroup::create(1, 1));

        $results = $this->service->bulkAddZones(1, [100, 101]);

        $this->assertCount(2, $results['success']);
        $this->assertEmpty($results['failed']);
    }

    #[Test]
    public function bulkAddZonesPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->method('exists')->willReturnMap([
            [100, 1, false],
            [101, 1, true],
        ]);
        $this->zoneGroupRepo->method('add')->willReturn(ZoneGroup::create(1, 1));

        $results = $this->service->bulkAddZones(1, [100, 101]);

        $this->assertCount(1, $results['success']);
        $this->assertSame('Group already owns this zone', $results['failed'][101]);
    }

    #[Test]
    public function bulkAddZonesGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->bulkAddZones(999, [100]);
    }

    // --- bulkRemoveZones ---

    #[Test]
    public function bulkRemoveZonesPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->zoneGroupRepo->method('remove')->willReturnMap([
            [100, 1, true],
            [101, 1, false],
        ]);

        $results = $this->service->bulkRemoveZones(1, [100, 101]);

        $this->assertCount(1, $results['success']);
        $this->assertSame('Group does not own this zone', $results['failed'][101]);
    }

    #[Test]
    public function bulkRemoveZonesKeepsAZoneWhoseLastOwnerIsTheGroup(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->ownershipGuard->method('refuseGroupRemoval')->willReturnMap([
            [100, 1, new ZoneOwnershipRefusal(ZoneOwnershipRefusal::LAST_OWNER, ZoneOwnershipModeService::MODE_BOTH)],
            [101, 1, null],
            [102, 1, new ZoneOwnershipRefusal(ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY, ZoneOwnershipModeService::MODE_GROUPS_ONLY)],
            [103, 1, new ZoneOwnershipRefusal(ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS, ZoneOwnershipModeService::MODE_USERS_ONLY)],
        ]);
        $this->zoneGroupRepo->expects($this->once())->method('remove')->with(101, 1)->willReturn(true);

        $results = $this->service->bulkRemoveZones(1, [100, 101, 102, 103]);

        $this->assertSame([101], $results['success']);
        $this->assertSame([
            100 => 'Cannot remove the last owner: this would leave the zone with no ownership',
            102 => 'Cannot remove the last group: zone ownership mode is groups_only and requires at least one group',
            103 => 'Cannot remove group: zone ownership mode is users_only and the zone has no user owners',
        ], $results['failed']);
    }

    // --- isGroupOwner ---

    #[Test]
    public function isGroupOwnerDelegatesToRepository(): void
    {
        $this->zoneGroupRepo->method('exists')->with(100, 1)->willReturn(true);
        $this->assertTrue($this->service->isGroupOwner(100, 1));
    }

    // --- getGroupDeletionImpact ---

    #[Test]
    public function getGroupDeletionImpactReturnsZoneCountAndLimitedList(): void
    {
        $zones = [];
        for ($i = 0; $i < 25; $i++) {
            $zones[] = new ZoneGroup($i + 1, 100 + $i, 1, null, "zone-$i.example.com", 'MASTER');
        }
        $this->zoneGroupRepo->method('findByGroupId')->with(1)->willReturn($zones);

        $result = $this->service->getGroupDeletionImpact(1);

        $this->assertSame(25, $result['zoneCount']);
        $this->assertCount(20, $result['zones']);
    }

    #[Test]
    public function getGroupDeletionImpactWithCustomLimit(): void
    {
        $zones = [];
        for ($i = 0; $i < 10; $i++) {
            $zones[] = new ZoneGroup($i + 1, 100 + $i, 1);
        }
        $this->zoneGroupRepo->method('findByGroupId')->with(1)->willReturn($zones);

        $result = $this->service->getGroupDeletionImpact(1, 5);

        $this->assertSame(10, $result['zoneCount']);
        $this->assertCount(5, $result['zones']);
    }

    #[Test]
    public function getGroupDeletionImpactNoZones(): void
    {
        $this->zoneGroupRepo->method('findByGroupId')->with(1)->willReturn([]);

        $result = $this->service->getGroupDeletionImpact(1);

        $this->assertSame(0, $result['zoneCount']);
        $this->assertEmpty($result['zones']);
    }
}
