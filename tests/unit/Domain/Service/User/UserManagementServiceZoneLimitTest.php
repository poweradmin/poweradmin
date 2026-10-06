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

namespace Poweradmin\Tests\Unit\Domain\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RefusalStatus;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;

/**
 * Deleting a user never hands their zones to someone past their zone limit (#72).
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceZoneLimitTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PermissionService&MockObject $permissionService;
    private DomainManagerInterface&MockObject $domainManager;
    private ZoneManagementService&MockObject $zones;
    private ZoneOwnershipLimit&MockObject $limit;
    private UserManagementService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->domainManager = $this->createMock(DomainManagerInterface::class);
        $this->zones = $this->createMock(ZoneManagementService::class);
        $this->limit = $this->createMock(ZoneOwnershipLimit::class);

        $this->userRepository->method('getUserById')->willReturnCallback(fn(int $id): array => ['id' => $id]);
        $this->userRepository->method('isLastUberuser')->willReturn(false);

        $this->service = new UserManagementService(
            $this->userRepository,
            $this->permissionService,
            new UserProfileAssembler($this->permissionService, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService('bcrypt', 4),
            $this->createMock(PasswordPolicyService::class),
            false,
            $this->domainManager,
            $this->zones,
            false,
            $this->limit
        );
    }

    public function testATransferPastTheReceiversLimitIsRefusedBeforeAnyWrite(): void
    {
        $breach = new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, 'bob', 9, 10);
        $this->userRepository->method('getUserZones')->with(1)->willReturn([['id' => 5, 'domain_id' => 12], ['id' => 6, 'domain_id' => 13]]);
        $this->limit->expects($this->once())->method('transferBreach')->with(1, 2)->willReturn($breach);
        $this->userRepository->expects($this->never())->method('transferUserZones');
        $this->userRepository->expects($this->never())->method('deleteUser');

        $result = $this->service->deleteUser(1, 2);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_ZONE_LIMIT, $result['code']);
        $this->assertSame(Refusal::CONFLICT, $result['refusal']);
        $this->assertSame($breach, $result['zone_limit']);
        $this->assertSame('Zone limit reached: user bob owns 9 of 10 zones.', $result['message']);
        $this->assertSame(409, RefusalStatus::ofResult($result));
    }

    public function testATransferWithinTheLimitGoesAhead(): void
    {
        $this->userRepository->method('getUserZones')->willReturn([['id' => 5, 'domain_id' => 12]]);
        $this->limit->method('transferBreach')->willReturn(null);
        $this->userRepository->expects($this->once())->method('transferUserZones')->with(1, 2)->willReturn(true);
        $this->userRepository->method('deleteUser')->willReturn(true);

        $this->assertTrue($this->service->deleteUser(1, 2)['success']);
    }

    public function testAUserWithoutZonesNeedsNoLimitCheck(): void
    {
        $this->userRepository->method('getUserZones')->willReturn([]);
        $this->limit->expects($this->never())->method('transferBreach');
        $this->userRepository->method('deleteUser')->willReturn(true);

        $this->assertTrue($this->service->deleteUser(1)['success']);
    }

    public function testPerZoneDecisionsAreCheckedPerNewOwnerBeforeAnyWrite(): void
    {
        $this->permissionService->method('canEditZoneMeta')->willReturn(true);
        $this->permissionService->method('canDeleteZoneById')->willReturn(true);
        $checked = [];
        $this->limit->method('zonesBreach')->willReturnCallback(
            function (int $userId, array $zoneIds) use (&$checked): ?ZoneLimitBreach {
                $checked[$userId] = $zoneIds;
                return $userId === 3 ? new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, 'carol', 1, 1) : null;
            }
        );
        $this->domainManager->expects($this->never())->method('addOwnerToZone');
        $this->zones->expects($this->never())->method('deleteZone');
        $this->userRepository->expects($this->never())->method('deleteUser');

        $result = $this->service->deleteUserWithZoneDecisions(9, 1, [
            ['zid' => 10, 'target' => 'delete'],
            ['zid' => 11, 'target' => 'new_owner', 'newowner' => 2],
            ['zid' => 12, 'target' => 'new_owner', 'newowner' => 3],
            ['zid' => 13, 'target' => 'new_owner', 'newowner' => 2],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_ZONE_LIMIT, $result['code']);
        $this->assertSame('Zone limit reached: user carol owns 1 of 1 zones.', $result['message']);
        $this->assertSame([2 => [11, 13], 3 => [12]], $checked);
    }

    public function testPerZoneDecisionsWithinTheLimitsAreApplied(): void
    {
        $this->permissionService->method('canEditZoneMeta')->willReturn(true);
        $this->limit->method('zonesBreach')->willReturn(null);
        $this->domainManager->expects($this->exactly(2))->method('addOwnerToZone')
            ->willReturnCallback(fn(int $zoneId): ZoneWriteResult => ZoneWriteResult::ok($zoneId));
        $this->userRepository->method('deleteUser')->willReturn(true);

        $result = $this->service->deleteUserWithZoneDecisions(9, 1, [
            ['zid' => 11, 'target' => 'new_owner', 'newowner' => 2],
            ['zid' => 12, 'target' => 'new_owner', 'newowner' => 2],
        ]);

        $this->assertTrue($result['success']);
    }
}
