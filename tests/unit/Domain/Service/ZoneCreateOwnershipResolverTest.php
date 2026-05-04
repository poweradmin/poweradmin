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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

#[CoversClass(ZoneCreateOwnershipResolver::class)]
class ZoneCreateOwnershipResolverTest extends TestCase
{
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
     * @return ApiPermissionService&MockObject
     */
    private function buildPermissions(
        array $perms = [],
        array $groupMembership = [],
        ?array $existingGroups = null
    ): ApiPermissionService {
        $service = $this->createMock(ApiPermissionService::class);
        $service->method('userHasPermission')
            ->willReturnCallback(fn(int $uid, string $name) => $perms[$name] ?? false);
        $service->method('getUserGroupIds')->willReturn($groupMembership);
        // By default, treat every requested group_id as existing so unrelated tests
        // don't need to set this up. Pass an explicit list to test the missing path.
        $service->method('getExistingGroupIds')
            ->willReturnCallback(fn(array $ids) => $existingGroups ?? $ids);
        return $service;
    }

    #[Test]
    public function defaultsOwnerToCallerInBothMode(): void
    {
        $resolver = new ZoneCreateOwnershipResolver($this->buildMode('both'), $this->buildPermissions());

        $result = $resolver->resolve([], self::CALLER_ID);

        $this->assertSame(['owner' => self::CALLER_ID, 'group_ids' => []], $result);
    }

    #[Test]
    public function rejectsGroupIdsThatAreNotAnArray(): void
    {
        $resolver = new ZoneCreateOwnershipResolver($this->buildMode('both'), $this->buildPermissions());

        $result = $resolver->resolve(['group_ids' => 'nope'], self::CALLER_ID);

        $this->assertSame(['error' => 'group_ids must be an array of integers', 'status' => 400], $result);
    }

    #[Test]
    public function rejectsGroupIdsWithNonIntegerEntries(): void
    {
        $resolver = new ZoneCreateOwnershipResolver($this->buildMode('both'), $this->buildPermissions());

        $result = $resolver->resolve(['group_ids' => [1, 'two']], self::CALLER_ID);

        $this->assertSame(['error' => 'group_ids must be an array of integers', 'status' => 400], $result);
    }

    #[Test]
    public function deduplicatesGroupIdsAndCoercesNumericStrings(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(['group_ids' => [3, '3', 5]], self::CALLER_ID);

        $this->assertSame([3, 5], $result['group_ids']);
    }

    #[Test]
    public function rejectsGroupsInUsersOnlyMode(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('users_only'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(['group_ids' => [2]], self::CALLER_ID);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('users_only', $result['error']);
    }

    #[Test]
    public function rejectsExplicitOwnerInGroupsOnlyMode(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('groups_only'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(
            ['owner_user_id' => 2, 'group_ids' => [4]],
            self::CALLER_ID
        );

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('groups_only', $result['error']);
    }

    #[Test]
    public function forcesOwnerNullInGroupsOnlyModeEvenWhenOwnerOmitted(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('groups_only'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(['group_ids' => [4]], self::CALLER_ID);

        $this->assertSame(['owner' => null, 'group_ids' => [4]], $result);
    }

    #[Test]
    public function keepsCallerAsOwnerWhenGroupIdsSuppliedWithoutOwnerField(): void
    {
        // Backward-compat: omitting owner_user_id keeps the existing default
        // (caller is user owner). To create a group-only zone via API, the
        // client must send owner_user_id: null explicitly.
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(['group_ids' => [9]], self::CALLER_ID);

        $this->assertSame(['owner' => self::CALLER_ID, 'group_ids' => [9]], $result);
    }

    #[Test]
    public function honorsExplicitNullOwnerWhenGroupsAreSet(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(
            ['owner_user_id' => null, 'group_ids' => [11]],
            self::CALLER_ID
        );

        $this->assertSame(['owner' => null, 'group_ids' => [11]], $result);
    }

    #[Test]
    public function rejectsWhenNeitherOwnerNorGroupsResolve(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('groups_only'),
            $this->buildPermissions()
        );

        // groups_only forces owner=null and groups stay empty -> nothing assigned.
        $result = $resolver->resolve([], self::CALLER_ID);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('At least one', $result['error']);
    }

    #[Test]
    public function rejectsAssigningOtherUserWithoutPermission(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions()
        );

        $result = $resolver->resolve(['owner_user_id' => 99], self::CALLER_ID);

        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('other users', $result['error']);
    }

    #[Test]
    public function allowsUeberuserToAssignAnyGroups(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(
            ['owner_user_id' => self::CALLER_ID, 'group_ids' => [42]],
            self::CALLER_ID
        );

        $this->assertSame(['owner' => self::CALLER_ID, 'group_ids' => [42]], $result);
    }

    #[Test]
    public function allowsNonAdminToAssignGroupsTheyBelongTo(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions([], [3, 4, 5])
        );

        $result = $resolver->resolve(['group_ids' => [3, 4]], self::CALLER_ID);

        // owner_user_id omitted -> caller stays as user owner (backward-compat default)
        $this->assertSame(['owner' => self::CALLER_ID, 'group_ids' => [3, 4]], $result);
    }

    #[Test]
    public function treatsOwnerUserIdZeroAsNoUserOwner(): void
    {
        // owner_user_id = 0 must not produce an orphaned zone in either mode.
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions()
        );

        $result = $resolver->resolve(['owner_user_id' => 0], self::CALLER_ID);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('At least one', $result['error']);
    }

    #[Test]
    public function ownerUserIdZeroWithGroupsBehavesAsExplicitNull(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true])
        );

        $result = $resolver->resolve(
            ['owner_user_id' => 0, 'group_ids' => [9]],
            self::CALLER_ID
        );

        $this->assertSame(['owner' => null, 'group_ids' => [9]], $result);
    }

    #[Test]
    public function rejectsUnknownGroupIds(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions(['user_is_ueberuser' => true], [], [3])
        );

        $result = $resolver->resolve(['group_ids' => [3, 99]], self::CALLER_ID);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('99', $result['error']);
    }

    #[Test]
    public function rejectsNonAdminAssigningForeignGroups(): void
    {
        $resolver = new ZoneCreateOwnershipResolver(
            $this->buildMode('both'),
            $this->buildPermissions([], [3])
        );

        $result = $resolver->resolve(['group_ids' => [3, 9]], self::CALLER_ID);

        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('9', $result['error']);
    }
}
