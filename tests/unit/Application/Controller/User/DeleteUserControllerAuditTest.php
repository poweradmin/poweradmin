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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\User\DeleteUserController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Deleting a user logs which zones went to which new owner, then the delete itself.
 */
#[CoversClass(DeleteUserController::class)]
class DeleteUserControllerAuditTest extends SeamControllerTestCase
{
    private const TARGET_ID = 9;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    /** @var UserManagementService&MockObject */
    private UserManagementService $users;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('isAdmin')->willReturn(false);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('getUserById')->willReturn(['id' => self::TARGET_ID, 'username' => 'leaving']);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnMap([[5, 'five.example'], [6, 'six.example']]);

        $this->audit = $this->createMock(AuditService::class);
        $this->users = $this->createMock(UserManagementService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('userRepository')->willReturn($userRepository);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('auditService')->willReturn($this->audit);
        $this->factory->method('userManagementService')->willReturn($this->users);
    }

    private function confirmedDelete(string $zoneJson): ?RequestHalted
    {
        $this->post(['zone' => $zoneJson]);
        $controller = new DeleteUserController(['id' => (string)self::TARGET_ID] + $this->requestData(), true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        return null;
    }

    public function testEachHandedOverZoneIsAuditedBeforeTheUserDelete(): void
    {
        $this->users->method('deleteUserWithZoneDecisions')->willReturn(['success' => true, 'message' => 'ok', 'zones_affected' => 3]);
        $handOvers = [];
        $this->audit->method('logZoneHandOver')->willReturnCallback(function (...$args) use (&$handOvers): void {
            $handOvers[] = $args;
        });
        $this->audit->expects($this->once())->method('logUserDelete')->with('leaving');

        $halt = $this->confirmedDelete('[{"zid":5,"target":"new_owner","newowner":3},{"zid":6,"target":"new_owner","newowner":4},{"zid":7,"target":"delete"}]');

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt?->kind);
        $this->assertSame([[5, 'five.example', 3, self::TARGET_ID], [6, 'six.example', 4, self::TARGET_ID]], $handOvers);
    }

    public function testARefusedDeleteAuditsNothing(): void
    {
        $this->users->method('deleteUserWithZoneDecisions')->willReturn(['success' => false, 'message' => 'no', 'refusal' => \Poweradmin\Domain\Service\Validation\Refusal::FORBIDDEN, 'code' => 'x']);
        $this->audit->expects($this->never())->method('logZoneHandOver');
        $this->audit->expects($this->never())->method('logUserDelete');

        $this->confirmedDelete('[{"zid":5,"target":"new_owner","newowner":3}]');
    }
}
