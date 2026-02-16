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
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;

#[CoversClass(HybridPermissionService::class)]
class HybridPermissionServiceTest extends TestCase
{
    private MockObject&PDO $db;
    private MockObject&UserGroupRepositoryInterface $groupRepo;
    private MockObject&UserGroupMemberRepositoryInterface $memberRepo;
    private MockObject&ZoneGroupRepositoryInterface $zoneGroupRepo;
    private HybridPermissionService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(PDO::class);
        $this->groupRepo = $this->createMock(UserGroupRepositoryInterface::class);
        $this->memberRepo = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->zoneGroupRepo = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->service = new HybridPermissionService(
            $this->db,
            $this->groupRepo,
            $this->memberRepo,
            $this->zoneGroupRepo
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
}
