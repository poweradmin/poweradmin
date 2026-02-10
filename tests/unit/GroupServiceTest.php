<?php

namespace unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

#[CoversClass(GroupService::class)]
class GroupServiceTest extends TestCase
{
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private GroupService $service;

    protected function setUp(): void
    {
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->service = new GroupService($this->groupRepo);
    }

    // --- listGroups ---

    #[Test]
    public function listGroupsAdminSeesAll(): void
    {
        $groups = [
            new UserGroup(1, 'Admins', null, 1),
            new UserGroup(2, 'Editors', null, 2),
        ];
        $this->groupRepo->expects($this->once())->method('findAll')->willReturn($groups);
        $this->groupRepo->expects($this->never())->method('findByUserId');

        $result = $this->service->listGroups(1, true);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function listGroupsNonAdminSeesOwnGroups(): void
    {
        $groups = [new UserGroup(2, 'Editors', null, 2)];
        $this->groupRepo->expects($this->never())->method('findAll');
        $this->groupRepo->expects($this->once())->method('findByUserId')->with(5)->willReturn($groups);

        $result = $this->service->listGroups(5, false);

        $this->assertCount(1, $result);
    }

    // --- getGroupById ---

    #[Test]
    public function getGroupByIdReturnsNullForMissing(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->assertNull($this->service->getGroupById(999, 1, true));
    }

    #[Test]
    public function getGroupByIdAdminCanViewAny(): void
    {
        $group = new UserGroup(1, 'Admins', null, 1);
        $this->groupRepo->method('findById')->with(1)->willReturn($group);

        $result = $this->service->getGroupById(1, 99, true);

        $this->assertSame($group, $result);
    }

    #[Test]
    public function getGroupByIdNonAdminWithAccess(): void
    {
        $group = new UserGroup(2, 'Editors', null, 2);
        $this->groupRepo->method('findById')->with(2)->willReturn($group);
        $this->groupRepo->method('findByUserId')->with(5)->willReturn([$group]);

        $result = $this->service->getGroupById(2, 5, false);

        $this->assertSame($group, $result);
    }

    #[Test]
    public function getGroupByIdNonAdminWithoutAccessThrows(): void
    {
        $group = new UserGroup(1, 'Admins', null, 1);
        $this->groupRepo->method('findById')->with(1)->willReturn($group);
        $this->groupRepo->method('findByUserId')->with(5)->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have permission to view this group');

        $this->service->getGroupById(1, 5, false);
    }

    // --- createGroup ---

    #[Test]
    public function createGroupHappyPath(): void
    {
        $this->groupRepo->method('findByName')->with('New Group')->willReturn(null);
        $this->groupRepo->expects($this->once())->method('save')
            ->willReturnCallback(function (UserGroup $g) {
                return new UserGroup(10, $g->getName(), $g->getDescription(), $g->getPermTemplId(), $g->getCreatedBy());
            });

        $result = $this->service->createGroup('New Group', 3, 'Description', 1);

        $this->assertSame(10, $result->getId());
        $this->assertSame('New Group', $result->getName());
    }

    #[Test]
    public function createGroupEmptyNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group name cannot be empty');

        $this->service->createGroup('   ', 1);
    }

    #[Test]
    public function createGroupDuplicateNameThrows(): void
    {
        $this->groupRepo->method('findByName')->with('Existing')->willReturn(new UserGroup(1, 'Existing', null, 1));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A group with this name already exists');

        $this->service->createGroup('Existing', 1);
    }

    // --- updateGroup ---

    #[Test]
    public function updateGroupHappyPath(): void
    {
        $existing = new UserGroup(5, 'Old', 'Old desc', 2);
        $this->groupRepo->method('findById')->with(5)->willReturn($existing);
        $this->groupRepo->method('findByName')->with('New')->willReturn(null);
        $this->groupRepo->expects($this->once())->method('save')
            ->willReturnCallback(fn(UserGroup $g) => $g);

        $result = $this->service->updateGroup(5, 'New', 'New desc', 3);

        $this->assertSame('New', $result->getName());
        $this->assertSame('New desc', $result->getDescription());
        $this->assertSame(3, $result->getPermTemplId());
    }

    #[Test]
    public function updateGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->updateGroup(999, 'Name');
    }

    #[Test]
    public function updateGroupDuplicateNameThrows(): void
    {
        $existing = new UserGroup(5, 'Old', null, 1);
        $other = new UserGroup(6, 'Taken', null, 1);
        $this->groupRepo->method('findById')->with(5)->willReturn($existing);
        $this->groupRepo->method('findByName')->with('Taken')->willReturn($other);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A group with this name already exists');

        $this->service->updateGroup(5, 'Taken');
    }

    #[Test]
    public function updateGroupSameNameAllowed(): void
    {
        $existing = new UserGroup(5, 'Same', null, 1);
        $this->groupRepo->method('findById')->with(5)->willReturn($existing);
        $this->groupRepo->method('findByName')->with('Same')->willReturn($existing);
        $this->groupRepo->method('save')->willReturnCallback(fn(UserGroup $g) => $g);

        $result = $this->service->updateGroup(5, 'Same', 'Updated desc');

        $this->assertSame('Updated desc', $result->getDescription());
    }

    // --- deleteGroup ---

    #[Test]
    public function deleteGroupDelegatesToRepository(): void
    {
        $this->groupRepo->method('findById')->with(5)->willReturn(new UserGroup(5, 'Test', null, 1));
        $this->groupRepo->expects($this->once())->method('delete')->with(5)->willReturn(true);

        $this->assertTrue($this->service->deleteGroup(5));
    }

    #[Test]
    public function deleteGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->deleteGroup(999);
    }

    // --- getGroupDetails ---

    #[Test]
    public function getGroupDetailsReturnsGroupWithCounts(): void
    {
        $group = new UserGroup(5, 'Test', null, 1);
        $this->groupRepo->method('findById')->with(5)->willReturn($group);
        $this->groupRepo->method('countMembers')->with(5)->willReturn(3);
        $this->groupRepo->method('countZones')->with(5)->willReturn(7);

        $result = $this->service->getGroupDetails(5);

        $this->assertSame($group, $result['group']);
        $this->assertSame(3, $result['memberCount']);
        $this->assertSame(7, $result['zoneCount']);
    }

    #[Test]
    public function getGroupDetailsNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->service->getGroupDetails(999);
    }

    // --- isGroupNameAvailable ---

    #[Test]
    public function isGroupNameAvailableReturnsTrueWhenNotFound(): void
    {
        $this->groupRepo->method('findByName')->with('Available')->willReturn(null);

        $this->assertTrue($this->service->isGroupNameAvailable('Available'));
    }

    #[Test]
    public function isGroupNameAvailableReturnsFalseWhenFound(): void
    {
        $this->groupRepo->method('findByName')->with('Taken')->willReturn(new UserGroup(1, 'Taken', null, 1));

        $this->assertFalse($this->service->isGroupNameAvailable('Taken'));
    }

    #[Test]
    public function isGroupNameAvailableExcludesCurrentGroupId(): void
    {
        $group = new UserGroup(5, 'MyGroup', null, 1);
        $this->groupRepo->method('findByName')->with('MyGroup')->willReturn($group);

        $this->assertTrue($this->service->isGroupNameAvailable('MyGroup', 5));
        $this->assertFalse($this->service->isGroupNameAvailable('MyGroup', 99));
    }

    // --- canUserViewGroup ---

    #[Test]
    public function canUserViewGroupReturnsTrueForMember(): void
    {
        $group = new UserGroup(5, 'Editors', null, 1);
        $this->groupRepo->method('findByUserId')->with(10)->willReturn([$group]);

        $this->assertTrue($this->service->canUserViewGroup(5, 10));
    }

    #[Test]
    public function canUserViewGroupReturnsFalseForNonMember(): void
    {
        $this->groupRepo->method('findByUserId')->with(10)->willReturn([]);

        $this->assertFalse($this->service->canUserViewGroup(5, 10));
    }
}
