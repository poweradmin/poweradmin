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

namespace Poweradmin\Tests\Unit\Application\Controller\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\System\AddSupermasterController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Zone\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\SupermasterWriteResult;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The add form reads its three fields as strings, so an array posted for any of them
 * is an empty value and not a type error.
 */
#[CoversClass(AddSupermasterController::class)]
class AddSupermasterControllerTest extends SeamControllerTestCase
{
    /** @var SupermasterManager&MockObject */
    private SupermasterManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUsersWithZoneCounts')->willReturn([]);

        $resolver = $this->createMock(ZoneOwnershipFormResolver::class);
        $resolver->method('mayNameAccount')->willReturn(true);
        $resolver->method('selectableOwners')->willReturn([]);

        $this->manager = $this->createMock(SupermasterManager::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('userRepository')->willReturn($users);
        $this->factory->method('zoneOwnershipFormResolver')->willReturn($resolver);
        $this->factory->method('supermasterManager')->willReturn($this->manager);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
    }

    public function testArrayFieldsReachTheManagerAsEmptyStrings(): void
    {
        $this->manager->expects($this->once())->method('addSupermaster')->with('', '', '')
            ->willReturn(SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_IP, 'This is not a valid IPv4 or IPv6 address.'));
        $this->post(['master_ip' => ['192.0.2.1'], 'ns_name' => ['ns1.example.com'], 'account' => ['alice']]);

        (new AddSupermasterController($this->requestData(), true, $this->environment($this->configure())))->run();

        $this->assertSame('add_supermaster.html', $this->renderedTemplate());
        $this->assertSame('', $this->renderedParams()['master_ip']);
    }

    public function testStringFieldsAreAdded(): void
    {
        $this->manager->expects($this->once())->method('addSupermaster')->with('192.0.2.1', 'ns1.example.com', 'alice')
            ->willReturn(SupermasterWriteResult::ok());
        $this->post(['master_ip' => '192.0.2.1', 'ns_name' => 'ns1.example.com', 'account' => 'alice']);

        try {
            (new AddSupermasterController($this->requestData(), true, $this->environment($this->configure())))->run();
            $this->fail('Expected a redirect to the list.');
        } catch (RequestHalted $halt) {
            $this->assertSame('/supermasters', $halt->target);
        }
    }
}
