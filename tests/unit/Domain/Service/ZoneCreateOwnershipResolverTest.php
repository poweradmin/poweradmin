<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\BuildsPermissionService;

#[CoversClass(ZoneCreateOwnershipResolver::class)]
class ZoneCreateOwnershipResolverTest extends TestCase
{
    use BuildsPermissionService;

    private const CALLER_ID = 7;

    private function buildMode(string $mode): ZoneOwnershipModeService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')
            ->with('dns', 'zone_ownership_mode', 'both')
            ->willReturn($mode);
        return new ZoneOwnershipModeService($config);
    }

    /**
     * @param array<string, bool> $perms permissions the caller holds
     * @param int[] $groupMembership groups the caller belongs to
     * @param int[]|null $existingGroups group ids that exist (defaults to every requested id)
     */
    private function buildResolver(string $mode, array $perms = [], array $groupMembership = [], ?array $existingGroups = null): ZoneCreateOwnershipResolver
    {
        $permissions = $this->buildPermissionService(
            permissionsByUser: [self::CALLER_ID => array_keys(array_filter($perms))],
            adminUserIds: !empty($perms['user_is_ueberuser']) ? [self::CALLER_ID] : []
        );
        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('getGroupIdsForUser')->willReturn($groupMembership);
        // By default, treat every requested group_id as existing so unrelated tests
        // don't need to set this up. Pass an explicit list to test the missing path.
        $groups->method('findExistingIds')
            ->willReturnCallback(fn(array $ids) => $existingGroups ?? $ids);

        return new ZoneCreateOwnershipResolver($this->buildMode($mode), $permissions, $groups);
    }

    #[Test]
    public function defaultsOwnerToCallerInBothMode(): void
    {
        $resolver = $this->buildResolver('both');

        $result = $resolver->resolve([], self::CALLER_ID);

        $this->assertFalse($result->hasError());
        $this->assertSame(self::CALLER_ID, $result->owner);
        $this->assertSame([], $result->groupIds);
    }

    #[Test]
    public function rejectsGroupIdsThatAreNotAnArray(): void
    {
        $resolver = $this->buildResolver('both');

        $result = $resolver->resolve(['group_ids' => 'nope'], self::CALLER_ID);

        $this->assertTrue($result->hasError());
        $this->assertSame('group_ids must be an array of integers', $result->error);
        $this->assertSame(400, $result->status);
    }

    #[Test]
    public function rejectsGroupIdsWithNonIntegerEntries(): void
    {
        $resolver = $this->buildResolver('both');

        $result = $resolver->resolve(['group_ids' => [1, 'two']], self::CALLER_ID);

        $this->assertTrue($result->hasError());
        $this->assertSame('group_ids must be an array of integers', $result->error);
        $this->assertSame(400, $result->status);
    }

    #[Test]
    public function deduplicatesGroupIdsAndCoercesNumericStrings(): void
    {
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(['group_ids' => [3, '3', 5]], self::CALLER_ID);

        $this->assertSame([3, 5], $result->groupIds);
    }

    #[Test]
    public function rejectsGroupsInUsersOnlyMode(): void
    {
        $resolver = $this->buildResolver('users_only', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(['group_ids' => [2]], self::CALLER_ID);

        $this->assertSame(400, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('users_only', $result->error);
    }

    #[Test]
    public function rejectsExplicitOwnerInGroupsOnlyMode(): void
    {
        $resolver = $this->buildResolver('groups_only', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(
            ['owner_user_id' => 2, 'group_ids' => [4]],
            self::CALLER_ID
        );

        $this->assertSame(400, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('groups_only', $result->error);
    }

    #[Test]
    public function forcesOwnerNullInGroupsOnlyModeEvenWhenOwnerOmitted(): void
    {
        $resolver = $this->buildResolver('groups_only', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(['group_ids' => [4]], self::CALLER_ID);

        $this->assertFalse($result->hasError());
        $this->assertNull($result->owner);
        $this->assertSame([4], $result->groupIds);
    }

    #[Test]
    public function keepsCallerAsOwnerWhenGroupIdsSuppliedWithoutOwnerField(): void
    {
        // Backward-compat: omitting owner_user_id keeps the existing default
        // (caller is user owner). To create a group-only zone via API, the
        // client must send owner_user_id: null explicitly.
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(['group_ids' => [9]], self::CALLER_ID);

        $this->assertFalse($result->hasError());
        $this->assertSame(self::CALLER_ID, $result->owner);
        $this->assertSame([9], $result->groupIds);
    }

    #[Test]
    public function honorsExplicitNullOwnerWhenGroupsAreSet(): void
    {
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(
            ['owner_user_id' => null, 'group_ids' => [11]],
            self::CALLER_ID
        );

        $this->assertFalse($result->hasError());
        $this->assertNull($result->owner);
        $this->assertSame([11], $result->groupIds);
    }

    #[Test]
    public function rejectsWhenNeitherOwnerNorGroupsResolve(): void
    {
        $resolver = $this->buildResolver('groups_only');

        // groups_only forces owner=null and groups stay empty -> nothing assigned.
        $result = $resolver->resolve([], self::CALLER_ID);

        $this->assertSame(400, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('At least one', $result->error);
    }

    #[Test]
    public function rejectsAssigningOtherUserWithoutPermission(): void
    {
        $resolver = $this->buildResolver('both');

        $result = $resolver->resolve(['owner_user_id' => 99], self::CALLER_ID);

        $this->assertSame(403, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('other users', $result->error);
    }

    #[Test]
    public function allowsUeberuserToAssignAnyGroups(): void
    {
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(
            ['owner_user_id' => self::CALLER_ID, 'group_ids' => [42]],
            self::CALLER_ID
        );

        $this->assertFalse($result->hasError());
        $this->assertSame(self::CALLER_ID, $result->owner);
        $this->assertSame([42], $result->groupIds);
    }

    #[Test]
    public function allowsNonAdminToAssignGroupsTheyBelongTo(): void
    {
        $resolver = $this->buildResolver('both', [], [3, 4, 5]);

        $result = $resolver->resolve(['group_ids' => [3, 4]], self::CALLER_ID);

        // owner_user_id omitted -> caller stays as user owner (backward-compat default)
        $this->assertFalse($result->hasError());
        $this->assertSame(self::CALLER_ID, $result->owner);
        $this->assertSame([3, 4], $result->groupIds);
    }

    #[Test]
    public function treatsOwnerUserIdZeroAsNoUserOwner(): void
    {
        // owner_user_id = 0 must not produce an orphaned zone in either mode.
        $resolver = $this->buildResolver('both');

        $result = $resolver->resolve(['owner_user_id' => 0], self::CALLER_ID);

        $this->assertSame(400, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('At least one', $result->error);
    }

    #[Test]
    public function ownerUserIdZeroWithGroupsBehavesAsExplicitNull(): void
    {
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true]);

        $result = $resolver->resolve(
            ['owner_user_id' => 0, 'group_ids' => [9]],
            self::CALLER_ID
        );

        $this->assertFalse($result->hasError());
        $this->assertNull($result->owner);
        $this->assertSame([9], $result->groupIds);
    }

    #[Test]
    public function rejectsUnknownGroupIds(): void
    {
        $resolver = $this->buildResolver('both', ['user_is_ueberuser' => true], [], [3]);

        $result = $resolver->resolve(['group_ids' => [3, 99]], self::CALLER_ID);

        $this->assertSame(404, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('99', $result->error);
        $this->assertSame(ZoneOwnershipResolution::UNKNOWN_GROUPS, $result->code);
        $this->assertSame([99], $result->ids);
    }

    #[Test]
    public function rejectsNonAdminAssigningForeignGroups(): void
    {
        $resolver = $this->buildResolver('both', [], [3]);

        $result = $resolver->resolve(['group_ids' => [3, 9]], self::CALLER_ID);

        $this->assertSame(403, $result->status);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('9', $result->error);
        $this->assertSame(ZoneOwnershipResolution::GROUPS_NOT_MEMBER, $result->code);
        $this->assertSame([9], $result->ids);
    }

    #[Test]
    public function groupsOnlyModeBlocksUsersWhoCouldNotPickAnyOwner(): void
    {
        $this->assertNull($this->buildResolver('both')->ownerOptionsBlocker(self::CALLER_ID));
        $this->assertSame(ZoneOwnershipResolution::NOT_IN_ANY_GROUP, $this->buildResolver('groups_only')->ownerOptionsBlocker(self::CALLER_ID));
        $this->assertNull($this->buildResolver('groups_only', [], [3])->ownerOptionsBlocker(self::CALLER_ID));
        // An admin may pick any group, so only an empty group list blocks them.
        $this->assertSame(ZoneOwnershipResolution::NO_GROUPS_EXIST, $this->buildResolver('groups_only', ['user_is_ueberuser' => true])->ownerOptionsBlocker(self::CALLER_ID));
    }

    #[Test]
    public function resolveOwnershipAppliesTheSharedRulesToParsedInput(): void
    {
        $resolver = $this->buildResolver('both', [], [3]);

        $this->assertSame(ZoneOwnershipResolution::NO_OWNER, $resolver->resolveOwnership(null, [], self::CALLER_ID)->code);
        $this->assertSame(ZoneOwnershipResolution::OTHER_OWNER_FORBIDDEN, $resolver->resolveOwnership(self::CALLER_ID + 1, [], self::CALLER_ID)->code);

        $ok = $resolver->resolveOwnership(self::CALLER_ID, [3, 3], self::CALLER_ID);
        $this->assertFalse($ok->hasError());
        $this->assertSame([3], $ok->groupIds);
    }
}
