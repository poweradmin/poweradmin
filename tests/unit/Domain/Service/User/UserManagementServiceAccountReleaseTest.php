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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

#[CoversClass(UserManagementService::class)]
class UserManagementServiceAccountReleaseTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private ZoneAccountSyncService&MockObject $accountSync;
    private UserManagementService $service;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturn(['id' => 2, 'auth_method' => 'sql']);
        $this->userRepository->method('isLastUberuser')->willReturn(false);
        $this->userRepository->method('getUserZones')->willReturn([
            ['id' => 71, 'domain_id' => 7],
            ['id' => 81, 'domain_id' => 8],
            ['id' => 91, 'domain_id' => 9],
        ]);
        $this->userRepository->method('deleteUser')->willReturnCallback(function (): bool {
            $this->calls[] = 'deleteUser';
            return true;
        });

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canDeleteZoneById')->willReturn(true);
        $zones = $this->createMock(ZoneManagementService::class);
        $zones->method('deleteZone')->willReturn(['success' => true, 'message' => '']);

        $this->accountSync = $this->createMock(ZoneAccountSyncService::class);
        $this->accountSync->method('isEnabled')->willReturn(true);

        $this->service = new UserManagementService(
            $this->userRepository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService('bcrypt', 4),
            $this->createMock(PasswordPolicyService::class),
            false,
            $this->createMock(DomainManagerInterface::class),
            $zones,
            accountSync: $this->accountSync
        );
    }

    #[Test]
    public function testZonesLeftBehindAreResyncedWithoutTheUserBeforeTheRowsGo(): void
    {
        $this->accountSync->expects($this->exactly(2))->method('syncZoneAccount')
            ->willReturnCallback(function (int $zoneId, ?int $exclude): void {
                $this->calls[] = "sync $zoneId excluding $exclude";
            });

        $result = $this->service->deleteUserWithZoneDecisions(1, 2, [['zid' => 7, 'target' => 'delete']]);

        $this->assertTrue($result['success']);
        $this->assertSame(['sync 8 excluding 2', 'sync 9 excluding 2', 'deleteUser'], $this->calls);
    }

    #[Test]
    public function testNothingIsPushedWhenTheSyncIsOff(): void
    {
        $this->accountSync = $this->createMock(ZoneAccountSyncService::class);
        $this->accountSync->method('isEnabled')->willReturn(false);
        $this->accountSync->expects($this->never())->method('syncZoneAccount');
        $service = new UserManagementService(
            $this->userRepository,
            $this->createMock(PermissionService::class),
            new UserProfileAssembler($this->createMock(PermissionService::class), $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService('bcrypt', 4),
            $this->createMock(PasswordPolicyService::class),
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class),
            accountSync: $this->accountSync
        );

        $this->assertTrue($service->deleteUserWithZoneDecisions(1, 2, [])['success']);
    }
}
