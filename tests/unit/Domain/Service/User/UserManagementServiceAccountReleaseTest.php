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
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

#[CoversClass(UserManagementService::class)]
class UserManagementServiceAccountReleaseTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private ZoneAccountSyncService&MockObject $accountSync;
    private SupermasterManager&MockObject $supermasters;
    private UserManagementService $service;

    private string $storedUsername = 'someone';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturnCallback(fn(): array => ['id' => 2, 'username' => $this->storedUsername, 'auth_method' => 'sql']);
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

        $this->accountSync = $this->createMock(ZoneAccountSyncService::class);
        $this->accountSync->method('isEnabled')->willReturn(true);
        $this->supermasters = $this->createMock(SupermasterManager::class);
        $this->rebuild();
    }

    private function rebuild(?DomainManagerInterface $domainManager = null, bool $adopts = false): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canDeleteZoneById')->willReturn(true);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userOwnsZone')->willReturn(true);
        $permissions->method('isAdmin')->willReturnCallback(fn(int $userId): bool => in_array($userId, $this->adminIds, true));
        $zones = $this->createMock(ZoneManagementService::class);
        $zones->method('deleteZone')->willReturn(['success' => true, 'message' => '']);

        $this->service = new UserManagementService(
            $this->userRepository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService('bcrypt', 4),
            $this->createMock(PasswordPolicyService::class),
            false,
            $domainManager ?? $this->createMock(DomainManagerInterface::class),
            $zones,
            accountSync: $this->accountSync,
            supermasters: $this->supermasters,
            adoptsZoneOwnerFromAccount: $adopts
        );
    }

    #[Test]
    public function testANewUserCannotTakeTheAccountOfAnOwnerlessZone(): void
    {
        $this->rebuild(adopts: true);
        $this->accountSync->method('hasOwnerlessZoneWithAccount')->willReturnCallback(fn(string $account): bool => $account === 'pending');
        $this->userRepository->expects($this->never())->method('createUser');

        $result = $this->service->createUser(new CreateUserCommand('pending', 'Secret123!', 'Mallory', 'm@example.com', permissionTemplateId: 3));

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_USERNAME_CLAIMED, $result['code']);
    }

    #[Test]
    public function testASuperuserCanCreateTheUserWhoseAccountAnOwnerlessZoneCarries(): void
    {
        $this->rebuild(adopts: true);
        $this->adminIds = [1];
        $this->accountSync->method('hasOwnerlessZoneWithAccount')->willReturn(true);
        $this->userRepository->method('permissionTemplateExists')->willReturn(true);
        $this->userRepository->method('createUser')->willReturn(9);
        $command = new CreateUserCommand('pending', 'Secret123!', 'Alice', 'a@example.com', permissionTemplateId: 3);

        $this->assertTrue($this->service->createUser($command, 1)['success']);
        $this->assertSame(UserManagementService::ERR_USERNAME_CLAIMED, $this->service->createUser($command, 2)['code']);
    }

    #[Test]
    public function testASuperuserCanRenameAUserToAPendingAccountName(): void
    {
        $this->rebuild(adopts: true);
        $this->adminIds = [1];
        $this->storedUsername = 'old';
        $this->accountSync->method('hasOwnerlessZoneWithAccount')->willReturn(true);
        $this->userRepository->method('updateUser')->willReturn(true);

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'pending'), 1)['success']);
        $this->assertSame(UserManagementService::ERR_USERNAME_CLAIMED, $this->service->updateUser(2, new UpdateUserCommand(username: 'pending'), 3)['code']);
    }

    #[Test]
    public function testANewUserCannotTakeTheAccountOfAnAutoprimary(): void
    {
        $this->rebuild(adopts: true);
        $this->supermasters->method('getSupermasters')->willReturn([['master_ip' => '192.0.2.1', 'ns_name' => 'ns.example.com', 'account' => 'tenant', 'fullname' => '']]);
        $this->userRepository->expects($this->never())->method('createUser');

        $result = $this->service->createUser(new CreateUserCommand('tenant', 'Secret123!', 'Mallory', 'm@example.com', permissionTemplateId: 3));

        $this->assertSame(UserManagementService::ERR_USERNAME_CLAIMED, $result['code']);
    }

    #[Test]
    public function testARenameCannotTakeAPendingAccountName(): void
    {
        $this->rebuild(adopts: true);
        $this->storedUsername = 'old';
        $this->accountSync->method('hasOwnerlessZoneWithAccount')->willReturn(true);
        $this->userRepository->expects($this->never())->method('updateUser');

        $result = $this->service->updateUser(2, new UpdateUserCommand(username: 'pending'));

        $this->assertSame(UserManagementService::ERR_USERNAME_CLAIMED, $result['code']);
    }

    #[Test]
    public function testAKeptNameIsNotCheckedAndTheCheckIsOffWithoutAdoption(): void
    {
        $this->accountSync->expects($this->never())->method('hasOwnerlessZoneWithAccount');
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->storedUsername = 'old';

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'pending'))['success']);

        $this->rebuild(adopts: true);
        $this->storedUsername = 'same';
        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'same', fullname: 'Someone'))['success']);
    }

    #[Test]
    public function testARenameMovesTheZonesAndSupermastersCarryingTheOldName(): void
    {
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->storedUsername = 'old';
        $this->accountSync->expects($this->once())->method('followRenamedUsername')->with('old', 'new');
        $this->supermasters->expects($this->once())->method('renameAccount')->with('old', 'new');

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'new'))['success']);
    }

    #[Test]
    public function testAnEditThatKeepsTheNameMovesNothing(): void
    {
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->storedUsername = 'same';
        $this->accountSync->expects($this->never())->method('followRenamedUsername');
        $this->supermasters->expects($this->never())->method('renameAccount');

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'same', fullname: 'Someone'))['success']);
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
    public function testAHandedOverZoneIsResyncedWithoutTheUserToo(): void
    {
        $this->accountSync->expects($this->exactly(2))->method('syncZoneAccount')
            ->willReturnCallback(function (int $zoneId, ?int $exclude): void {
                $this->calls[] = "sync $zoneId excluding $exclude";
            });
        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->method('handOverZone')->willReturn(ZoneWriteResult::ok(8));
        $this->rebuild($domainManager);

        $this->service->deleteUserWithZoneDecisions(1, 2, [['zid' => 7, 'target' => 'delete'], ['zid' => 8, 'target' => 'new_owner', 'newowner' => 5]]);

        $this->assertSame(['sync 8 excluding 2', 'sync 9 excluding 2', 'deleteUser'], $this->calls);
    }

    #[Test]
    public function testARefusedDeleteCountsTheUserAsOwnerAgain(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserById')->willReturn(['id' => 2, 'auth_method' => 'sql']);
        $repository->method('isLastUberuser')->willReturnOnConsecutiveCalls(false, true);
        $repository->method('getUserZones')->willReturn([['id' => 91, 'domain_id' => 9]]);
        $this->userRepository = $repository;
        $this->rebuild();
        $this->accountSync->method('syncZoneAccount')->willReturnCallback(function (int $zoneId, ?int $exclude = null): void {
            $this->calls[] = "sync $zoneId excluding " . var_export($exclude, true);
        });

        $result = $this->service->deleteUserWithZoneDecisions(1, 2, []);

        $this->assertFalse($result['success']);
        $this->assertSame(['sync 9 excluding 2', 'sync 9 excluding NULL'], $this->calls);
    }

    #[Test]
    public function testAThrowingDeleteCountsTheUserAsOwnerAgainAndRethrows(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('getUserById')->willReturn(['id' => 2, 'auth_method' => 'sql']);
        $repository->method('isLastUberuser')->willReturn(false);
        $repository->method('getUserZones')->willReturn([['id' => 91, 'domain_id' => 9]]);
        $repository->method('deleteUser')->willThrowException(new \RuntimeException('deadlock'));
        $this->userRepository = $repository;
        $this->rebuild();
        $this->accountSync->method('syncZoneAccount')->willReturnCallback(function (int $zoneId, ?int $exclude = null): void {
            $this->calls[] = "sync $zoneId excluding " . var_export($exclude, true);
        });

        try {
            $this->service->deleteUserWithZoneDecisions(1, 2, []);
            $this->fail('expected the exception');
        } catch (\RuntimeException) {
            $this->assertSame(['sync 9 excluding 2', 'sync 9 excluding NULL'], $this->calls);
        }
    }

    #[Test]
    public function testAFailingZoneStepDoesNotSkipTheSupermasterStep(): void
    {
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->storedUsername = 'old';
        $this->accountSync->method('followRenamedUsername')->willThrowException(new \RuntimeException('down'));
        $this->supermasters->expects($this->once())->method('renameAccount')->with('old', 'new');

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(username: 'new'))['success']);
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
