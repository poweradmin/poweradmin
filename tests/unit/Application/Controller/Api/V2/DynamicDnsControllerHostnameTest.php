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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use Poweradmin\Application\Controller\Api\V2\DynamicDnsController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\DynamicDnsUpdateService;
use Poweradmin\Domain\Service\Dns\DynamicDnsValidationService;
use Poweradmin\Domain\ValueObject\HostnameValue;
use TestHelpers\FakeConfiguration;

/**
 * The v2 dynamic DNS endpoint lowercases the hostname like dynamic_update.php does,
 * so a differently cased update finds the records an earlier one wrote.
 */
final class DynamicDnsControllerHostnameTest extends V2ControllerTestCase
{
    public function testTheHostnameIsLowercasedBeforeTheUpdate(): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $update = $this->createMock(DynamicDnsUpdateService::class);
        $update->expects($this->once())
            ->method('applyForUser')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn(HostnameValue $hostname): bool => $hostname->getValue() === 'host.example.com')
            )
            ->willReturn(['status' => 'good', 'zone_id' => 3, 'applied_ipv4' => ['192.0.2.1'], 'applied_ipv6' => [], 'changed' => true]);

        $controller = $this->bareController(DynamicDnsController::class);
        $this->injectBaseCollaborators($controller, 'POST', ['hostname' => 'Host.Example.COM', 'ipv4' => '192.0.2.1']);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'updateService', $update);
        $this->inject($controller, 'validationService', new DynamicDnsValidationService(new FakeConfiguration()));
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'apiKeyScope', ApiKeyScope::unrestricted());

        $response = $this->callHandler($controller, 'updateRecord');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('host.example.com', $this->decode($response)['data']['hostname']);
    }
}
