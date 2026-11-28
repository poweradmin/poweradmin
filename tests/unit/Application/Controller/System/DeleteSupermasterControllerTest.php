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
use Poweradmin\Application\Controller\System\DeleteSupermasterController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\SupermasterWriteResult;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * One autoprimary IP can carry several NS names, so the confirmation page and the
 * delete are keyed by the pair, not the IP.
 */
#[CoversClass(DeleteSupermasterController::class)]
class DeleteSupermasterControllerTest extends SeamControllerTestCase
{
    /** @var SupermasterManager&MockObject */
    private SupermasterManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $this->manager = $this->createMock(SupermasterManager::class);
        $this->manager->method('getSupermasters')->willReturn([
            ['master_ip' => '192.0.2.1', 'ns_name' => 'ns1.example.com', 'account' => 'alice', 'fullname' => ''],
            ['master_ip' => '192.0.2.1', 'ns_name' => 'ns2.example.com', 'account' => 'bob', 'fullname' => ''],
        ]);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('supermasterManager')->willReturn($this->manager);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
    }

    private function runController(): ?RequestHalted
    {
        $controller = new DeleteSupermasterController($this->requestData(), true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        return null;
    }

    public function testTheConfirmationPageShowsTheEntryOfTheGivenPair(): void
    {
        $this->query(['master_ip' => '192.0.2.1', 'ns_name' => 'ns2.example.com']);

        $this->assertNull($this->runController());
        $this->assertSame('delete_supermaster.html', $this->renderedTemplate());
        $this->assertSame('bob', $this->renderedParams()['info']['account']);
        $this->assertSame('ns2.example.com', $this->renderedParams()['info']['ns_name']);
    }

    public function testAPairThatDoesNotExistIsRefused(): void
    {
        $this->query(['master_ip' => '192.0.2.1', 'ns_name' => 'ns3.example.com']);

        $halt = $this->runController();

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt?->kind);
        $this->assertNull($this->renderedTemplate());
        $this->assertSame([['error', 'Super master does not exist.']], $this->messagesFor('list_supermasters'));
    }

    public function testAnIpWithoutANsNameIsRefused(): void
    {
        $this->query(['master_ip' => '192.0.2.1']);

        $this->assertSame(RequestHalted::KIND_REDIRECT, $this->runController()?->kind);
        $this->assertNull($this->renderedTemplate());
    }

    public function testTheConfirmedDeleteRemovesTheSubmittedPair(): void
    {
        $this->post(['confirm' => '1', 'master_ip' => '192.0.2.1', 'ns_name' => 'ns2.example.com']);
        $this->manager->method('supermasterIpNameExists')->with('192.0.2.1', 'ns2.example.com')->willReturn(true);
        $this->manager->expects($this->once())->method('deleteSupermaster')->with('192.0.2.1', 'ns2.example.com')
            ->willReturn(SupermasterWriteResult::ok());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $this->runController()?->kind);
    }
}
