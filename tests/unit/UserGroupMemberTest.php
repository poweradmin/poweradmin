<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserGroupMember;

#[CoversClass(UserGroupMember::class)]
class UserGroupMemberTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $member = new UserGroupMember(1, 10, 20, '2025-01-01 00:00:00', 'admin', 'Admin User', 'admin@example.com');

        $this->assertSame(1, $member->getId());
        $this->assertSame(10, $member->getGroupId());
        $this->assertSame(20, $member->getUserId());
        $this->assertSame('2025-01-01 00:00:00', $member->getCreatedAt());
        $this->assertSame('admin', $member->getUsername());
        $this->assertSame('Admin User', $member->getFullname());
        $this->assertSame('admin@example.com', $member->getEmail());
    }

    #[Test]
    public function constructorWithOptionalDefaults(): void
    {
        $member = new UserGroupMember(null, 5, 15);

        $this->assertNull($member->getId());
        $this->assertSame(5, $member->getGroupId());
        $this->assertSame(15, $member->getUserId());
        $this->assertNull($member->getCreatedAt());
        $this->assertNull($member->getUsername());
        $this->assertNull($member->getFullname());
        $this->assertNull($member->getEmail());
    }

    #[Test]
    public function createFactorySetsOnlyGroupIdAndUserId(): void
    {
        $member = UserGroupMember::create(3, 7);

        $this->assertNull($member->getId());
        $this->assertSame(3, $member->getGroupId());
        $this->assertSame(7, $member->getUserId());
        $this->assertNull($member->getCreatedAt());
        $this->assertNull($member->getUsername());
        $this->assertNull($member->getFullname());
        $this->assertNull($member->getEmail());
    }

    #[Test]
    public function constructorWithDenormalizedUserData(): void
    {
        $member = new UserGroupMember(42, 1, 2, '2025-06-15 10:30:00', 'jdoe', 'John Doe', 'jdoe@example.com');

        $this->assertSame(42, $member->getId());
        $this->assertSame('jdoe', $member->getUsername());
        $this->assertSame('John Doe', $member->getFullname());
        $this->assertSame('jdoe@example.com', $member->getEmail());
    }

    #[Test]
    public function constructorWithNullDenormalizedData(): void
    {
        $member = new UserGroupMember(1, 5, 10, '2025-01-01 00:00:00', null, null, null);

        $this->assertNull($member->getUsername());
        $this->assertNull($member->getFullname());
        $this->assertNull($member->getEmail());
    }
}
