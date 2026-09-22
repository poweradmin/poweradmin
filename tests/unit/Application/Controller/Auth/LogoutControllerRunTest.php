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
use Poweradmin\Application\Controller\Auth\LogoutController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Pins the collaborator wiring of a plain (non-SSO) logout: the audit line is
 * written first, then the session is ended through the request-scoped
 * AuthenticationService the service factory hands out.
 */
#[CoversClass(LogoutController::class)]
class LogoutControllerRunTest extends SeamControllerTestCase
{
    public function testStandardLogoutAuditsThenEndsTheSessionThroughTheSharedAuthenticationService(): void
    {
        $config = $this->configure();

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logLogout');
        $this->factory->method('auditService')->willReturn($audit);

        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->once())->method('logout')->with($this->callback(
            static fn(FlashMessage $entity): bool => $entity->getMessage() === 'You have logged out.'
                && $entity->getType() === 'success'
        ));
        $this->factory->expects($this->once())->method('authenticationService')->willReturn($authentication);

        $controller = new LogoutController([], true, $this->environment($config));
        $controller->run();
    }
}
