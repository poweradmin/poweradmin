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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\LogoutController;
use ReflectionClass;

class LogoutControllerTest extends TestCase
{
    private function getLogoutParameterName(string $logoutUrl): string
    {
        $reflection = new ReflectionClass(LogoutController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getLogoutParameterName');
        $method->setAccessible(true);

        return $method->invoke($controller, $logoutUrl);
    }

    public function testAuth0UsesReturnToParameter(): void
    {
        $this->assertSame(
            'returnTo',
            $this->getLogoutParameterName('https://example.auth0.com/v2/logout')
        );
    }

    public function testKeycloakUsesPostLogoutRedirectUri(): void
    {
        $this->assertSame(
            'post_logout_redirect_uri',
            $this->getLogoutParameterName('https://sso.example.com/realms/master/protocol/openid-connect/logout')
        );
    }

    public function testAzureUsesPostLogoutRedirectUri(): void
    {
        $this->assertSame(
            'post_logout_redirect_uri',
            $this->getLogoutParameterName('https://login.microsoftonline.com/tenant-id/oauth2/v2.0/logout')
        );
    }
}
