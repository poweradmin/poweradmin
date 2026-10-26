<?php

namespace Poweradmin\Tests\Unit;

use InvalidArgumentException;
use Poweradmin\Domain\Error\GroupNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\User\GroupMembershipService;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

#[CoversClass(GroupMembershipService::class)]
class GroupMembershipServiceTest extends TestCase
{
    private MockObject&UserGroupMemberRepositoryInterface $memberRepo;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private MockObject&UserPermissionReadInterface $users;
    private GroupMembershipService $service;

    protected function setUp(): void
    {
        $this->memberRepo = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->users = $this->createMock(UserPermissionReadInterface::class);
        $this->service = new GroupMembershipService($this->memberRepo, $this->groupRepo, new LastSuperAdminGuard($this->users));
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

        $this->expectException(GroupNotFoundException::class);
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

    /**
     * One super admin now, user 5 through group 1; removing that membership leaves none.
     */
    private function givenUserFiveIsTheLastSuperAdminThroughGroupOne(): void
    {
        $this->users->method('countUberusersAfter')->willReturnCallback(
            static fn(UberuserChange $change): int => $change->memberGroupId === 1 && $change->memberUserId === 5 ? 0 : 1
        );
    }

    #[Test]
    public function removeUserFromGroupRefusesTheLastSuperAdmin(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Admins', null, 1));
        $this->givenUserFiveIsTheLastSuperAdminThroughGroupOne();
        $this->memberRepo->expects($this->never())->method('remove');

        $result = $this->service->removeUserFromGroup(1, 5);

        $this->assertIsArray($result);
        $this->assertSame(Refusal::CONFLICT, $result['refusal']);
        $this->assertSame(LastSuperAdminGuard::ERR_LAST_ADMIN_DEMOTE, $result['code']);
    }

    #[Test]
    public function removeUserFromGroupGoesAheadWhenNoSuperAdminIsLeftToProtect(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Test', null, 1));
        $this->users->method('countUberusersAfter')->willReturn(0);
        $this->memberRepo->expects($this->once())->method('remove')->with(1, 5)->willReturn(true);

        $this->assertTrue($this->service->removeUserFromGroup(1, 5));
    }

    #[Test]
    public function bulkRemoveUsersKeepsTheLastSuperAdmin(): void
    {
        $this->groupRepo->method('findById')->with(1)->willReturn(new UserGroup(1, 'Admins', null, 1));
        $this->givenUserFiveIsTheLastSuperAdminThroughGroupOne();
        $this->memberRepo->expects($this->once())->method('remove')->with(1, 6)->willReturn(true);

        $results = $this->service->bulkRemoveUsers(1, [5, 6]);

        $this->assertSame([6], $results['success']);
        $this->assertSame([5], $results['refused']);
        $this->assertArrayNotHasKey(5, $results['failed']);
    }

    #[Test]
    public function removeUserFromGroupGroupNotFoundThrows(): void
    {
        $this->groupRepo->method('findById')->with(999)->willReturn(null);

        $this->expectException(GroupNotFoundException::class);
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
