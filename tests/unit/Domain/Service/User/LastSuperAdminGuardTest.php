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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use RuntimeException;

class LastSuperAdminGuardTest extends TestCase
{
    private function guardCounting(int $now, int $after, ?TransactionInterface $transaction = null): LastSuperAdminGuard
    {
        $users = $this->createMock(UserPermissionReadInterface::class);
        $users->method('countUberusersAfter')->willReturnCallback(
            static fn(UberuserChange $change): int => $change == UberuserChange::none() ? $now : $after
        );

        return new LastSuperAdminGuard($users, $transaction);
    }

    public function testAChangeLeavingNoSuperAdminIsRefusedAndRolledBack(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects($this->once())->method('begin');
        $transaction->expects($this->once())->method('rollBack');
        $transaction->expects($this->never())->method('commit');

        $written = false;
        $result = $this->guardCounting(1, 0, $transaction)->guard(
            UberuserChange::groupDeleted(1),
            function () use (&$written): bool {
                $written = true;
                return true;
            }
        );

        $this->assertIsArray($result);
        $this->assertSame(LastSuperAdminGuard::MESSAGE, $result['message']);
        $this->assertFalse($written);
    }

    public function testAChangeLeavingASuperAdminWritesAndCommits(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects($this->once())->method('commit');
        $transaction->expects($this->never())->method('rollBack');

        $this->assertSame('saved', $this->guardCounting(2, 1, $transaction)->guard(UberuserChange::groupDeleted(1), static fn(): string => 'saved'));
    }

    public function testWithNoSuperAdminLeftAlreadyTheChangeIsNotBlocked(): void
    {
        $this->assertTrue($this->guardCounting(0, 0)->guard(UberuserChange::memberRemoved(1, 2), static fn(): bool => true));
    }

    public function testAnOuterTransactionKeepsItsOwnCommit(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->method('inTransaction')->willReturn(true);
        $transaction->expects($this->never())->method('begin');
        $transaction->expects($this->never())->method('commit');
        $transaction->expects($this->never())->method('rollBack');

        $this->assertIsArray($this->guardCounting(1, 0, $transaction)->guard(UberuserChange::groupDeleted(1), static fn(): bool => true));
    }

    public function testAFailingWriteRollsBackAndRethrows(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $transaction->expects($this->once())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->guardCounting(2, 1, $transaction)->guard(UberuserChange::groupDeleted(1), static function (): bool {
            throw new RuntimeException('write failed');
        });
    }
}
