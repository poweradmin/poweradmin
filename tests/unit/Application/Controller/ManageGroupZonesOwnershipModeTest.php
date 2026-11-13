<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ManageGroupZonesController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use ReflectionProperty;

class ManageGroupZonesOwnershipModeTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testGroupGrantsAreRefusedWhenTheModeAllowsOnlyUserOwners(): void
    {
        $_POST = ['action' => 'add', 'domain_ids' => '5,6'];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $g, string $k, $d = null) => $g === 'dns' && $k === 'zone_ownership_mode' ? 'users_only' : $d
        );
        $service = $this->createMock(ZoneGroupService::class);
        $service->expects($this->never())->method('bulkAddZones');

        $probe = new class extends ManageGroupZonesController {
            /** @var list<string> */
            public array $messages = [];
            public function __construct()
            {
            }
            public function setMessage(string $script, string $type, string $content): void
            {
                $this->messages[] = $type . ':' . $content;
            }
        };
        (new ReflectionProperty(\Poweradmin\BaseController::class, 'config'))->setValue($probe, $config);
        (new ReflectionProperty(ManageGroupZonesController::class, 'request'))->setValue($probe, new Request());
        (new ReflectionProperty(ManageGroupZonesController::class, 'zoneGroupService'))->setValue($probe, $service);

        try {
            (new ReflectionClass(ManageGroupZonesController::class))->getMethod('addZones')->invoke($probe, 3);
        } catch (\Throwable) {
            // The form re-render needs a database; the refusal was recorded before it
        }

        $this->assertSame(['error:Group-ownership assignment is disabled by the current zone ownership mode.'], $probe->messages);
    }
}
