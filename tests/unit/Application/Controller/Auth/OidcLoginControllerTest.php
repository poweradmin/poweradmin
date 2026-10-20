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

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Auth\OidcLoginController;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Pins the collaborator wiring of the OIDC login endpoint: when OIDC is
 * off, the request is bounced to the login page through the request-scoped
 * AuthenticationService the service factory hands out.
 */
#[CoversClass(OidcLoginController::class)]
class OidcLoginControllerTest extends SeamControllerTestCase
{
    public function testDisabledOidcBouncesToLoginThroughTheSharedAuthenticationService(): void
    {
        $config = $this->configure(['oidc' => ['enabled' => false]]);

        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->once())->method('auth')->with($this->callback(
            static fn(FlashMessage $entity): bool => $entity->getMessage() === 'OIDC authentication is not enabled'
                && $entity->getType() === 'danger'
        ));
        $this->factory->expects($this->atLeastOnce())->method('authenticationService')->willReturn($authentication);

        $controller = new OidcLoginController([], $this->environment($config));
        $controller->run();
    }
}
