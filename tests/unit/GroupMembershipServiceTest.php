<?php

namespace unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\GroupMembershipService;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

#[CoversClass(GroupMembershipService::class)]
class GroupMembershipServiceTest extends TestCase
{
    private MockObject&UserGroupMemberRepositoryInterface $memberRepo;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private GroupMembershipService $service;

    protected function setUp(): void
    {
        $this->memberRepo = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->service = new GroupMembershipService($this->memberRepo, $this->groupRepo);
    }

    // --- addUserToGroup ---

    #[Test]
    public function addUserToGroupHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->method('exists')->with(1, 5)->willReturn(false);
        $member = UserGroupMember::create(1, 5);
        $this->memberRepo->expects($this->once())->method('add')->with(1, 5)->willReturn($member);

        $result = $this->service->addUserToGroup(1, 5);

        $this->assertSame(1, $result->getGroupId());
        $this->assertSame(5, $result->getUserId());
    }

    #[Test]
    public function addUserToGroupGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->addUserToGroup(999, 5);
    }

    #[Test]
    public function addUserToGroupAlreadyMemberThrows(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->method('exists')->with(1, 5)->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User is already a member of this group');

        $this->service->addUserToGroup(1, 5);
    }

    // --- removeUserFromGroup ---

    #[Test]
    public function removeUserFromGroupHappyPath(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->expects($this->once())->method('remove')->with(1, 5)->willReturn(true);

        $this->assertTrue($this->service->removeUserFromGroup(1, 5));
    }

    #[Test]
    public function removeUserFromGroupGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->removeUserFromGroup(999, 5);
    }

    // --- listGroupMembers ---

    #[Test]
    public function listGroupMembersDelegatesToRepository(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $members = [
            new UserGroupMember(1, 1, 5, null, 'admin', 'Admin', 'admin@test.com'),
            new UserGroupMember(2, 1, 6, null, 'user', 'User', 'user@test.com'),
        ];
        $this->memberRepo->method('findByGroupId')->with(1)->willReturn($members);

        $result = $this->service->listGroupMembers(1);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function listGroupMembersGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->service->listGroupMembers(999);
    }

    // --- listUserGroups ---

    #[Test]
    public function listUserGroupsDelegatesToRepository(): void
    {
        $memberships = [UserGroupMember::create(1, 5), UserGroupMember::create(2, 5)];
        $this->memberRepo->expects($this->once())->method('findByUserId')->with(5)->willReturn($memberships);

        $result = $this->service->listUserGroups(5);

        $this->assertCount(2, $result);
    }

    // --- bulkAddUsers ---

    #[Test]
    public function bulkAddUsersAllSucceed(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->method('exists')->willReturn(false);
        $this->memberRepo->method('add')->willReturn(UserGroupMember::create(1, 1));

        $results = $this->service->bulkAddUsers(1, [5, 6, 7]);

        $this->assertCount(3, $results['success']);
        $this->assertEmpty($results['failed']);
    }

    #[Test]
    public function bulkAddUsersPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->method('exists')->willReturnMap([
            [1, 5, false],
            [1, 6, true],
            [1, 7, false],
        ]);
        $this->memberRepo->method('add')->willReturn(UserGroupMember::create(1, 1));

        $results = $this->service->bulkAddUsers(1, [5, 6, 7]);

        $this->assertCount(2, $results['success']);
        $this->assertCount(1, $results['failed']);
        $this->assertSame('Already a member', $results['failed'][6]);
    }

    #[Test]
    public function bulkAddUsersGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->service->bulkAddUsers(999, [5]);
    }

    // --- bulkRemoveUsers ---

    #[Test]
    public function bulkRemoveUsersPartialSuccess(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->memberRepo->method('remove')->willReturnMap([
            [1, 5, true],
            [1, 6, false],
        ]);

        $results = $this->service->bulkRemoveUsers(1, [5, 6]);

        $this->assertCount(1, $results['success']);
        $this->assertCount(1, $results['failed']);
        $this->assertSame('Not a member', $results['failed'][6]);
    }

    // --- isUserMember ---

    #[Test]
    public function isUserMemberReturnsTrueForExisting(): void
    {
        $this->memberRepo->method('exists')->with(1, 5)->willReturn(true);
        $this->assertTrue($this->service->isUserMember(1, 5));
    }

    #[Test]
    public function isUserMemberReturnsFalseForNonExisting(): void
    {
        $this->memberRepo->method('exists')->with(1, 99)->willReturn(false);
        $this->assertFalse($this->service->isUserMember(1, 99));
    }
}
