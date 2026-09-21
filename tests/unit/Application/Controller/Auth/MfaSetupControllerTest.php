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
use Poweradmin\Application\Controller\Auth\MfaSetupController;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Pins the collaborator wiring of the MFA setup page: the app setup POST
 * goes through the request-scoped MfaService the service factory hands out,
 * and a missing record falls back to the setup page with an error.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerTest extends SeamControllerTestCase
{
    public function testAppSetupUsesTheSharedMfaService(): void
    {
        $config = $this->configure(['security' => ['mfa' => ['enabled' => true]]]);

        $mfa = $this->createMock(MfaService::class);
        $mfa->expects($this->once())->method('getOrCreateUserMfa')->with(self::USER_ID)->willReturn(null);
        $mfa->method('getUserMfa')->willReturn(null);
        $mfa->method('isMfaEnforced')->willReturn(false);
        $this->factory->expects($this->once())->method('mfaService')->willReturn($mfa);

        $this->post(['setup_app' => '1']);
        $controller = new TestableMfaSetupController([], $this->environment($config));
        $controller->run();

        $this->assertSame('mfa_setup.html', $controller->rendered[0][0]);
        $this->assertSame([['error', 'Failed to create MFA record.']], $this->messagesFor('system'));
    }
}
