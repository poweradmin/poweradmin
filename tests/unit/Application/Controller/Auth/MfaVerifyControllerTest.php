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
use Poweradmin\Application\Controller\Auth\MfaVerifyController;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Pins the collaborator wiring of the MFA verification page: the form is
 * described through the request-scoped MfaService the service factory hands
 * out, and a stale flow token re-renders it instead of verifying anything.
 */
#[CoversClass(MfaVerifyController::class)]
class MfaVerifyControllerTest extends SeamControllerTestCase
{
    public function testStaleFlowTokenRerendersTheFormThroughTheSharedMfaService(): void
    {
        $config = $this->configure(['security' => ['mfa' => ['enabled' => true]]]);
        $_SESSION[AuthFlowSessionKeys::MFA_REQUIRED] = true;
        $_SESSION[AuthFlowSessionKeys::MFA_TOKEN] = 'issued';

        $mfa = $this->createMock(MfaService::class);
        $mfa->expects($this->never())->method('verifyCode');
        $mfa->expects($this->once())->method('getMfaType')->with(self::USER_ID)->willReturn('app');
        $this->factory->expects($this->once())->method('mfaService')->willReturn($mfa);
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false));

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $this->factory->method('loginAttemptService')->willReturn($attempts);

        $this->post(['mfa_code' => '123456', 'mfa_token' => 'stale']);
        $controller = new MfaVerifyController([], $this->environment($config));
        $controller->run();

        $this->assertSame('mfa_verify.html', $this->output->rendered[0][0]);
        $this->assertSame('Invalid security token. Please try again.', $this->output->rendered[0][1]['msg']);
    }
}
