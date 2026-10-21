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
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

#[CoversClass(UserManagementService::class)]
class UserManagementServiceLastAdminTransactionTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private TransactionInterface&MockObject $transaction;
    private UserManagementService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserById')->willReturn(['id' => 2, 'auth_method' => 'sql']);
        $this->userRepository->method('permissionTemplateExists')->willReturn(true);
        $this->transaction = $this->createMock(TransactionInterface::class);

        $permissions = $this->createMock(PermissionService::class);
        $this->service = new UserManagementService(
            $this->userRepository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService('bcrypt', 4),
            $this->createMock(PasswordPolicyService::class),
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class),
            transaction: $this->transaction
        );
    }

    #[Test]
    public function testDisableChecksAndWritesInOneCommittedTransaction(): void
    {
        $this->userRepository->method('isLastUberuser')->willReturn(false);
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->once())->method('commit');
        $this->transaction->expects($this->never())->method('rollBack');

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(active: false))['success']);
    }

    #[Test]
    public function testRefusedDisableRollsBackWithoutWriting(): void
    {
        $this->userRepository->method('isLastUberuser')->willReturn(true);
        $this->userRepository->expects($this->never())->method('updateUser');
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->never())->method('commit');
        $this->transaction->expects($this->once())->method('rollBack');

        $result = $this->service->updateUser(2, new UpdateUserCommand(active: false));

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_LAST_ADMIN, $result['code']);
    }

    #[Test]
    public function testDeleteRechecksTheGuardInsideTheDeletingTransaction(): void
    {
        // The early check passes, as it does for the loser of a race; the one under the lock refuses
        $this->userRepository->method('isLastUberuser')->willReturnOnConsecutiveCalls(false, true);
        $this->userRepository->method('getUserZones')->willReturn([]);
        $this->userRepository->expects($this->never())->method('deleteUser');
        $this->transaction->expects($this->once())->method('rollBack');

        $result = $this->service->deleteUser(2);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_LAST_ADMIN, $result['code']);
    }

    #[Test]
    public function testAFailedWriteRollsBackAndTheExceptionPropagates(): void
    {
        $this->userRepository->method('isLastUberuser')->willReturn(false);
        $this->userRepository->method('templateGrantsUberuser')->willReturn(false);
        $this->userRepository->method('assignPermissionTemplate')->willThrowException(new \PDOException('deadlock'));
        $this->transaction->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $this->transaction->expects($this->once())->method('rollBack');

        $this->expectException(\PDOException::class);
        $this->service->assignPermissionTemplate(2, 5);
    }

    #[Test]
    public function testAnOuterTransactionIsJoinedNotCommitted(): void
    {
        $this->userRepository->method('isLastUberuser')->willReturn(false);
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->never())->method('begin');
        $this->transaction->expects($this->never())->method('commit');

        $this->assertTrue($this->service->updateUser(2, new UpdateUserCommand(active: false))['success']);
    }
}
