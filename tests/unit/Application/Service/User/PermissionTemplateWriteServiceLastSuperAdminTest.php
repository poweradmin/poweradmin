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

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\User\PermissionTemplateWriteService;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * A template edit that drops user_is_ueberuser is refused when the template is all that
 * keeps an active super admin, deciding and writing in one transaction.
 */
class PermissionTemplateWriteServiceLastSuperAdminTest extends TestCase
{
    private const TEMPLATE_ID = 1;
    private const UBERUSER_PERM_ID = 53;

    private PermissionTemplateRepositoryInterface&MockObject $templates;
    private UserPermissionReadInterface&MockObject $users;
    private TransactionInterface&MockObject $transaction;
    private PermissionTemplateWriteService $service;

    protected function setUp(): void
    {
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $this->users = $this->createMock(UserPermissionReadInterface::class);
        $this->users->method('hasAdminPermission')->willReturn(true);
        $this->users->method('getPermissionIdsByName')->willReturn([self::UBERUSER_PERM_ID]);
        $this->transaction = $this->createMock(TransactionInterface::class);
        $this->service = new PermissionTemplateWriteService(
            $this->templates,
            $this->users,
            new LastSuperAdminGuard($this->users, $this->transaction)
        );
    }

    private function givenTheTemplateHoldsTheLastSuperAdmin(): void
    {
        $this->users->method('countUberusersAfter')->willReturnCallback(
            static fn(UberuserChange $change): int => $change->droppedTemplateId === self::TEMPLATE_ID ? 0 : 1
        );
    }

    /**
     * @param list<string> $permIds
     * @return array<string, mixed>
     */
    private function details(array $permIds): array
    {
        return ['templ_name' => 'Administrator', 'templ_descr' => '', 'template_type' => 'user', 'perm_id' => $permIds];
    }

    public function testDroppingTheSuperAdminPermissionFromTheLastGrantIsRefused(): void
    {
        $this->givenTheTemplateHoldsTheLastSuperAdmin();
        $this->templates->expects($this->never())->method('updatePermissionTemplateDetails');
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->once())->method('rollBack');
        $this->transaction->expects($this->never())->method('commit');

        $result = $this->service->update(1, self::TEMPLATE_ID, $this->details(['7']));

        $this->assertFalse($result['success']);
        $this->assertSame(LastSuperAdminGuard::MESSAGE, $result['message']);
        $this->assertSame(Refusal::CONFLICT, $result['refusal'] ?? null);
        $this->assertSame(LastSuperAdminGuard::ERR_LAST_ADMIN_DEMOTE, $result['code'] ?? null);
    }

    public function testKeepingTheSuperAdminPermissionWritesWithoutTheCheck(): void
    {
        $this->users->expects($this->never())->method('countUberusersAfter');
        $this->templates->expects($this->once())->method('updatePermissionTemplateDetails')->willReturn(true);

        $result = $this->service->update(1, self::TEMPLATE_ID, $this->details(['7', (string)self::UBERUSER_PERM_ID]));

        $this->assertTrue($result['success']);
    }

    public function testARenameLeavesThePermissionsAndSkipsTheCheck(): void
    {
        $this->users->expects($this->never())->method('countUberusersAfter');
        $this->templates->expects($this->once())->method('updatePermissionTemplateDetails')->willReturn(true);

        $result = $this->service->update(1, self::TEMPLATE_ID, ['templ_name' => 'Renamed', 'templ_descr' => '', 'template_type' => 'user']);

        $this->assertTrue($result['success']);
    }

    public function testDroppingItWhileAnotherSuperAdminRemainsCommits(): void
    {
        $this->users->method('countUberusersAfter')->willReturn(1);
        $this->templates->expects($this->once())->method('updatePermissionTemplateDetails')->willReturn(true);
        $this->transaction->expects($this->once())->method('commit');

        $result = $this->service->update(1, self::TEMPLATE_ID, $this->details(['7']));

        $this->assertTrue($result['success']);
    }
}
