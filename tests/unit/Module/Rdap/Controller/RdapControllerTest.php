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

namespace Poweradmin\Tests\Unit\Module\Rdap\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Module\Rdap\Controller\RdapController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * With the lookup open to every user, a zone id prefills the domain only when the
 * user may view that zone, so the page never names a foreign zone.
 */
#[CoversClass(RdapController::class)]
class RdapControllerTest extends SeamControllerTestCase
{
    /** @return array<string, array{string}> */
    public static function zoneParams(): array
    {
        return ['id' => ['id'], 'zone_id' => ['zone_id']];
    }

    #[DataProvider('zoneParams')]
    public function testAForeignZoneIdLeavesTheDomainEmpty(string $param): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->with(self::USER_ID, 12)->willReturn(false);
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->never())->method('getDomainNameById');
        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);

        $controller = new RdapController([$param => '12'], true, $this->environment($this->configure(['rdap' => ['restrict_to_admin' => false]])));
        $controller->run();

        $this->assertSame('', $this->renderedParams()['domain']);
    }
}
