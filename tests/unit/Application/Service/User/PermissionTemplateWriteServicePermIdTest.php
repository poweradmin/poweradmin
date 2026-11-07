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
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;
use Poweradmin\Domain\Service\Auth\PermissionTemplateContentGuard;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;

/**
 * Permission ids reach the repository only as the ints the superuser guard checked;
 * MySQL would otherwise store a posted '52.5' as 53 after the guard read it as 52.
 */
class PermissionTemplateWriteServicePermIdTest extends TestCase
{
    private const CALLER_ID = 7;
    private const TEMPLATE_ID = 9;
    private const UBERUSER_PERM_ID = 53;

    private PermissionTemplateRepositoryInterface&MockObject $templates;

    private function service(bool $callerIsSuperuser): PermissionTemplateWriteService
    {
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $users = $this->createMock(UserPermissionReadInterface::class);
        $users->method('hasAdminPermission')->willReturn($callerIsSuperuser);
        $users->method('templateGrantsUberuser')->willReturn(false);
        $users->method('getPermissionIdsByName')->willReturn([self::UBERUSER_PERM_ID]);
        $users->method('countUberusersAfter')->willReturn(1);

        return new PermissionTemplateWriteService(
            $this->templates,
            $users,
            new LastSuperAdminGuard($users, $this->createMock(TransactionInterface::class))
        );
    }

    /**
     * @param list<mixed> $permIds
     * @return array<string, mixed>
     */
    private function details(array $permIds): array
    {
        return ['templ_name' => 'Delegated', 'templ_descr' => '', 'template_type' => 'user', 'perm_id' => $permIds];
    }

    public function testCreateRefusesADecimalIdThatMysqlWouldRoundToTheSuperuserPermission(): void
    {
        $service = $this->service(callerIsSuperuser: false);
        $this->templates->expects($this->never())->method('addPermissionTemplate');

        $result = $service->create(self::CALLER_ID, $this->details(['4', '52.5']));

        $this->assertFalse($result['success']);
        $this->assertSame(PermissionTemplateContentGuard::INVALID_PERMISSION_ID, $result['message']);
        $this->assertSame(400, $result['status']);
    }

    public function testUpdateRefusesAnExponentIdOnTheCallersOwnTemplate(): void
    {
        $service = $this->service(callerIsSuperuser: false);
        $this->templates->expects($this->never())->method('updatePermissionTemplateDetails');

        $result = $service->update(self::CALLER_ID, self::TEMPLATE_ID, $this->details(['5.25e1']));

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['status'] ?? null);
    }

    public function testSuperusersAreRefusedTooSoTheStoredListIsAlwaysCanonical(): void
    {
        $service = $this->service(callerIsSuperuser: true);
        $this->templates->expects($this->never())->method('addPermissionTemplate');

        $result = $service->create(self::CALLER_ID, $this->details(['52.5']));

        $this->assertSame(400, $result['status']);
    }

    public function testCreatePassesTheCheckedIdsToTheRepositoryAsInts(): void
    {
        $service = $this->service(callerIsSuperuser: false);
        $this->templates->expects($this->once())
            ->method('addPermissionTemplate')
            ->with($this->callback(static fn(array $details): bool => $details['perm_id'] === [4, 12]))
            ->willReturn(3);

        $result = $service->create(self::CALLER_ID, $this->details(['4', '12']));

        $this->assertTrue($result['success']);
    }

    public function testUpdatePassesTheCheckedIdsToTheRepositoryAsInts(): void
    {
        $service = $this->service(callerIsSuperuser: false);
        $this->templates->expects($this->once())
            ->method('updatePermissionTemplateDetails')
            ->with($this->callback(static fn(array $details): bool => $details['perm_id'] === [4, 12]))
            ->willReturn(true);

        $result = $service->update(self::CALLER_ID, self::TEMPLATE_ID, $this->details([4, '12']));

        $this->assertTrue($result['success']);
    }

    public function testARenameStillLeavesThePermissionListUntouched(): void
    {
        $service = $this->service(callerIsSuperuser: false);
        $this->templates->expects($this->once())
            ->method('updatePermissionTemplateDetails')
            ->with($this->callback(static fn(array $details): bool => !array_key_exists('perm_id', $details)))
            ->willReturn(true);

        $result = $service->update(self::CALLER_ID, self::TEMPLATE_ID, ['templ_name' => 'Renamed', 'templ_descr' => '', 'template_type' => 'user']);

        $this->assertTrue($result['success']);
    }
}
