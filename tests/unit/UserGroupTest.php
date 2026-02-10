<?php

namespace unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserGroup;

#[CoversClass(UserGroup::class)]
class UserGroupTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $group = new UserGroup(1, 'Admins', 'Admin group', 5, 10, '2025-01-01 00:00:00', '2025-06-01 12:00:00');

        $this->assertSame(1, $group->getId());
        $this->assertSame('Admins', $group->getName());
        $this->assertSame('Admin group', $group->getDescription());
        $this->assertSame(5, $group->getPermTemplId());
        $this->assertSame(10, $group->getCreatedBy());
        $this->assertSame('2025-01-01 00:00:00', $group->getCreatedAt());
        $this->assertSame('2025-06-01 12:00:00', $group->getUpdatedAt());
    }

    #[Test]
    public function constructorWithNullableDefaults(): void
    {
        $group = new UserGroup(null, 'Group', null, 1);

        $this->assertNull($group->getId());
        $this->assertSame('Group', $group->getName());
        $this->assertNull($group->getDescription());
        $this->assertSame(1, $group->getPermTemplId());
        $this->assertNull($group->getCreatedBy());
        $this->assertNull($group->getCreatedAt());
        $this->assertNull($group->getUpdatedAt());
    }

    #[Test]
    public function createReturnsEntityWithNullId(): void
    {
        $group = UserGroup::create('New Group', 3, 'A new group', 7);

        $this->assertNull($group->getId());
        $this->assertSame('New Group', $group->getName());
        $this->assertSame('A new group', $group->getDescription());
        $this->assertSame(3, $group->getPermTemplId());
        $this->assertSame(7, $group->getCreatedBy());
        $this->assertNull($group->getCreatedAt());
        $this->assertNull($group->getUpdatedAt());
    }

    #[Test]
    public function createWithOptionalDefaults(): void
    {
        $group = UserGroup::create('Minimal', 1);

        $this->assertNull($group->getId());
        $this->assertSame('Minimal', $group->getName());
        $this->assertNull($group->getDescription());
        $this->assertSame(1, $group->getPermTemplId());
        $this->assertNull($group->getCreatedBy());
    }

    #[Test]
    public function updateReturnsNewInstanceWithUpdatedFields(): void
    {
        $original = new UserGroup(5, 'Old Name', 'Old desc', 2, 1, '2025-01-01 00:00:00', '2025-01-02 00:00:00');

        $updated = $original->update('New Name', 'New desc', 8);

        $this->assertNotSame($original, $updated);
        $this->assertSame(5, $updated->getId());
        $this->assertSame('New Name', $updated->getName());
        $this->assertSame('New desc', $updated->getDescription());
        $this->assertSame(8, $updated->getPermTemplId());
        $this->assertSame(1, $updated->getCreatedBy());
        $this->assertSame('2025-01-01 00:00:00', $updated->getCreatedAt());
        $this->assertNull($updated->getUpdatedAt());
    }

    #[Test]
    public function updatePreservesOriginalValues(): void
    {
        $original = new UserGroup(5, 'Old Name', 'Old desc', 2, 1, '2025-01-01 00:00:00', null);

        $this->assertSame('Old Name', $original->getName());
        $this->assertSame('Old desc', $original->getDescription());
        $this->assertSame(2, $original->getPermTemplId());
    }

    #[Test]
    public function updateWithNullParamsKeepsOriginalValues(): void
    {
        $original = new UserGroup(5, 'Keep Name', 'Keep desc', 2, 1, '2025-01-01 00:00:00', null);

        $updated = $original->update(null, null, null);

        $this->assertSame('Keep Name', $updated->getName());
        $this->assertSame('Keep desc', $updated->getDescription());
        $this->assertSame(2, $updated->getPermTemplId());
    }

    #[Test]
    public function updateDescriptionWithEmptyString(): void
    {
        $original = new UserGroup(5, 'Name', 'Has description', 2, 1, null, null);

        $updated = $original->update(null, '', null);

        $this->assertSame('', $updated->getDescription());
    }
}
