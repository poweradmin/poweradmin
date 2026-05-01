<?php

namespace unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

#[CoversClass(HybridPermissionService::class)]
class HybridPermissionServiceTest extends TestCase
{
    private MockObject&PDO $db;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private MockObject&UserGroupMemberRepositoryInterface $memberRepo;
    private HybridPermissionService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(PDO::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->memberRepo = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->service = new HybridPermissionService(
            $this->db,
            $this->groupRepo,
            $this->memberRepo
        );
    }

    private function mockDirectPermissions(array $permissions): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn($permissions);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function (string $sql) use ($stmt) {
                // Direct user permissions query vs group permissions query
                if (str_contains($sql, 'z.owner = :user_id AND z.domain_id = :domain_id')) {
                    return $stmt;
                }
                // Group permissions query - return empty by default
                $emptyStmt = $this->createMock(PDOStatement::class);
                $emptyStmt->method('fetchAll')->willReturn([]);
                $emptyStmt->method('execute')->willReturn(true);
                return $emptyStmt;
            });
    }

    private function mockGroupPermissions(array $rows): void
    {
        $groupStmt = $this->createMock(PDOStatement::class);
        $groupStmt->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($rows);
        $groupStmt->method('execute')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function (string $sql) use ($groupStmt) {
                if (str_contains($sql, 'user_group_members')) {
                    return $groupStmt;
                }
                // Direct permissions - return empty
                $emptyStmt = $this->createMock(PDOStatement::class);
                $emptyStmt->method('fetchAll')->willReturn([]);
                $emptyStmt->method('execute')->willReturn(true);
                return $emptyStmt;
            });
    }

    private function mockBothPermissions(array $directPerms, array $groupRows): void
    {
        $directStmt = $this->createMock(PDOStatement::class);
        $directStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn($directPerms);
        $directStmt->method('execute')->willReturn(true);

        $groupStmt = $this->createMock(PDOStatement::class);
        $groupStmt->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn($groupRows);
        $groupStmt->method('execute')->willReturn(true);

        $callCount = 0;
        $this->db->method('prepare')
            ->willReturnCallback(function () use ($directStmt, $groupStmt, &$callCount) {
                $callCount++;
                return $callCount === 1 ? $directStmt : $groupStmt;
            });
    }

    // --- getUserPermissionsForZone ---

    #[Test]
    public function getUserPermissionsDirectOwnershipOnly(): void
    {
        $this->mockBothPermissions(
            ['zone_content_edit_own', 'zone_content_view_own'],
            []
        );

        $result = $this->service->getUserPermissionsForZone(1, 100);

        $this->assertContains('zone_content_edit_own', $result['permissions']);
        $this->assertContains('zone_content_view_own', $result['permissions']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('user', $result['sources'][0]['type']);
    }

    #[Test]
    public function getUserPermissionsGroupOnly(): void
    {
        $this->mockBothPermissions(
            [],
            [
                ['group_id' => 5, 'group_name' => 'Editors', 'permission' => 'zone_content_edit_own'],
                ['group_id' => 5, 'group_name' => 'Editors', 'permission' => 'zone_content_view_own'],
            ]
        );

        $result = $this->service->getUserPermissionsForZone(1, 100);

        $this->assertContains('zone_content_edit_own', $result['permissions']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('group', $result['sources'][0]['type']);
        $this->assertSame('Editors', $result['sources'][0]['name']);
    }

    #[Test]
    public function getUserPermissionsBothSourcesMerged(): void
    {
        $this->mockBothPermissions(
            ['zone_content_view_own'],
            [
                ['group_id' => 5, 'group_name' => 'Editors', 'permission' => 'zone_content_edit_own'],
                ['group_id' => 5, 'group_name' => 'Editors', 'permission' => 'zone_content_view_own'],
            ]
        );

        $result = $this->service->getUserPermissionsForZone(1, 100);

        // Should be union (deduplicated)
        $this->assertContains('zone_content_view_own', $result['permissions']);
        $this->assertContains('zone_content_edit_own', $result['permissions']);
        $this->assertCount(2, $result['permissions']);
        $this->assertCount(2, $result['sources']);
    }

    #[Test]
    public function getUserPermissionsNoPermissions(): void
    {
        $this->mockBothPermissions([], []);

        $result = $this->service->getUserPermissionsForZone(1, 100);

        $this->assertEmpty($result['permissions']);
        $this->assertEmpty($result['sources']);
    }

    #[Test]
    public function getUserPermissionsMultipleGroupsDedup(): void
    {
        $this->mockBothPermissions(
            [],
            [
                ['group_id' => 1, 'group_name' => 'Group A', 'permission' => 'zone_content_view_own'],
                ['group_id' => 1, 'group_name' => 'Group A', 'permission' => 'zone_content_edit_own'],
                ['group_id' => 2, 'group_name' => 'Group B', 'permission' => 'zone_content_view_own'],
                ['group_id' => 2, 'group_name' => 'Group B', 'permission' => 'zone_meta_edit_own'],
            ]
        );

        $result = $this->service->getUserPermissionsForZone(1, 100);

        $this->assertCount(3, $result['permissions']);
        $this->assertContains('zone_content_view_own', $result['permissions']);
        $this->assertContains('zone_content_edit_own', $result['permissions']);
        $this->assertContains('zone_meta_edit_own', $result['permissions']);
        $this->assertCount(2, $result['sources']);
    }

    // --- canUserPerformAction ---

    #[Test]
    public function canUserPerformActionTrueWhenPermissionExists(): void
    {
        $this->mockBothPermissions(['zone_content_edit_own'], []);

        $this->assertTrue($this->service->canUserPerformAction(1, 100, 'zone_content_edit_own'));
    }

    #[Test]
    public function canUserPerformActionFalseWhenPermissionMissing(): void
    {
        $this->mockBothPermissions(['zone_content_view_own'], []);

        $this->assertFalse($this->service->canUserPerformAction(1, 100, 'zone_content_edit_own'));
    }

    // --- isUserZoneOwner ---

    #[Test]
    public function isUserZoneOwnerTrueForDirectOwner(): void
    {
        $this->mockBothPermissions(['zone_content_view_own'], []);

        $this->assertTrue($this->service->isUserZoneOwner(1, 100));
    }

    #[Test]
    public function isUserZoneOwnerTrueForGroupMember(): void
    {
        $this->mockBothPermissions(
            [],
            [['group_id' => 1, 'group_name' => 'Test', 'permission' => 'zone_content_view_own']]
        );

        $this->assertTrue($this->service->isUserZoneOwner(1, 100));
    }

    #[Test]
    public function isUserZoneOwnerFalseForNeither(): void
    {
        $this->mockBothPermissions([], []);

        $this->assertFalse($this->service->isUserZoneOwner(1, 100));
    }

    // --- getUserAccessibleZones ---

    #[Test]
    public function getUserAccessibleZonesReturnsSeparateArrays(): void
    {
        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['100', '101']);
        $userStmt->method('execute')->willReturn(true);

        $groupStmt = $this->createMock(PDOStatement::class);
        $groupStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['102', '103']);
        $groupStmt->method('execute')->willReturn(true);

        $callCount = 0;
        $this->db->method('prepare')
            ->willReturnCallback(function () use ($userStmt, $groupStmt, &$callCount) {
                $callCount++;
                return $callCount === 1 ? $userStmt : $groupStmt;
            });

        $result = $this->service->getUserAccessibleZones(1);

        $this->assertSame([100, 101], $result['user_zones']);
        $this->assertSame([102, 103], $result['group_zones']);
    }

    #[Test]
    public function getUserAccessibleZonesEmptyResults(): void
    {
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn([]);
        $emptyStmt->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturn($emptyStmt);

        $result = $this->service->getUserAccessibleZones(1);

        $this->assertEmpty($result['user_zones']);
        $this->assertEmpty($result['group_zones']);
    }

    // --- getPermissionSourcesForUser ---

    #[Test]
    public function getPermissionSourcesForUserReturnsDirectAndGroups(): void
    {
        $directStmt = $this->createMock(PDOStatement::class);
        $directStmt->method('execute')->willReturn(true);
        $directStmt->method('fetchColumn')->willReturn(1);

        $groupStmt = $this->createMock(PDOStatement::class);
        $groupStmt->method('execute')->willReturn(true);
        $groupStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['7', '9']);

        $callCount = 0;
        $this->db->method('prepare')
            ->willReturnCallback(function () use ($directStmt, $groupStmt, &$callCount) {
                $callCount++;
                return $callCount === 1 ? $directStmt : $groupStmt;
            });

        $result = $this->service->getPermissionSourcesForUser(1, 'zone_delete_own');

        $this->assertTrue($result['has_direct']);
        $this->assertSame([7, 9], $result['group_ids']);
    }

    #[Test]
    public function getPermissionSourcesForUserNoDirectGroupsOnly(): void
    {
        $directStmt = $this->createMock(PDOStatement::class);
        $directStmt->method('execute')->willReturn(true);
        $directStmt->method('fetchColumn')->willReturn(false);

        $groupStmt = $this->createMock(PDOStatement::class);
        $groupStmt->method('execute')->willReturn(true);
        $groupStmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['2']);

        $callCount = 0;
        $this->db->method('prepare')
            ->willReturnCallback(function () use ($directStmt, $groupStmt, &$callCount) {
                $callCount++;
                return $callCount === 1 ? $directStmt : $groupStmt;
            });

        $result = $this->service->getPermissionSourcesForUser(5, 'zone_delete_own');

        $this->assertFalse($result['has_direct']);
        $this->assertSame([2], $result['group_ids']);
    }

    #[Test]
    public function getPermissionSourcesForUserNoSources(): void
    {
        $emptyDirect = $this->createMock(PDOStatement::class);
        $emptyDirect->method('execute')->willReturn(true);
        $emptyDirect->method('fetchColumn')->willReturn(false);

        $emptyGroup = $this->createMock(PDOStatement::class);
        $emptyGroup->method('execute')->willReturn(true);
        $emptyGroup->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn([]);

        $callCount = 0;
        $this->db->method('prepare')
            ->willReturnCallback(function () use ($emptyDirect, $emptyGroup, &$callCount) {
                $callCount++;
                return $callCount === 1 ? $emptyDirect : $emptyGroup;
            });

        $result = $this->service->getPermissionSourcesForUser(99, 'zone_delete_own');

        $this->assertFalse($result['has_direct']);
        $this->assertSame([], $result['group_ids']);
    }
}
