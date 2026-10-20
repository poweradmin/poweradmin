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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * The zone view gate the delete pages consult before asking the repository for
 * zone details: "none" flashes the refusal the repository used to queue itself.
 */
#[CoversClass(BaseController::class)]
class BaseControllerCanViewZonesTest extends SeamControllerTestCase
{
    private function controllerWithViewLevel(string $level): CanViewZonesTestController
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->willReturn($level);
        $this->factory->method('permissionService')->willReturn($permissions);

        return new CanViewZonesTestController([], true, $this->environment($this->configure()));
    }

    /** @return array<string, array{0: string}> */
    public static function viewingLevelProvider(): array
    {
        return ['own zones' => ['own'], 'all zones' => ['all']];
    }

    #[DataProvider('viewingLevelProvider')]
    public function testAViewingUserPassesWithoutAMessage(string $level): void
    {
        $this->assertTrue($this->controllerWithViewLevel($level)->canViewZonesForTest());
        $this->assertSame([], $this->messagesFor('system'));
    }

    public function testNoViewLevelIsRefusedWithTheZoneMessage(): void
    {
        $this->assertFalse($this->controllerWithViewLevel('none')->canViewZonesForTest());
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }

    public function testWithoutALoggedInUserTheGateIsClosed(): void
    {
        $this->session->remove(SessionKeys::USERID);

        $this->assertFalse($this->controllerWithViewLevel('all')->canViewZonesForTest());
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }
}
