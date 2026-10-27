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
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Auth\MfaVerifyController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
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
    private const VICTIM_ID = 42;

    public function testStaleFlowTokenRerendersTheFormThroughTheSharedMfaService(): void
    {
        $config = $this->configure(['security' => ['mfa' => ['enabled' => true]]]);
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, true);
        $this->session->set(AuthFlowSessionKeys::MFA_TOKEN, 'issued');

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

    public function testAPendingLoginOnAnotherUsersSessionEndsTheSessionWithoutCheckingTheCode(): void
    {
        $mfa = $this->pendingLoginOf(self::VICTIM_ID);
        $mfa->expects($this->never())->method('verifyCode');
        $mfa->expects($this->never())->method('consumeRecoveryCode');

        $halt = $this->submitCode();

        $this->assertSame('/login', $halt?->target);
        $this->assertFalse($this->session->has(SessionKeys::USERID));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_USERID));
    }

    public function testTheCodeIsCheckedForThePendingLogin(): void
    {
        $this->session->remove(SessionKeys::USERID);
        $mfa = $this->pendingLoginOf(self::VICTIM_ID);
        $mfa->method('isMfaEnabled')->willReturn(true);
        $mfa->method('getUserMfa')->willReturn(UserMfa::create(self::VICTIM_ID, true, 'JBSWY3DPEHPK3PXP'));
        $mfa->expects($this->once())->method('verifyCode')->with(self::VICTIM_ID, '123456')->willReturn(false);

        $this->submitCode();

        $this->assertSame(self::VICTIM_ID, $this->session->get(SessionKeys::PENDING_USERID));
        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAnArrayCodeIsCheckedAsAnEmptyCode(): void
    {
        $this->session->remove(SessionKeys::USERID);
        $mfa = $this->pendingLoginOf(self::VICTIM_ID);
        $mfa->method('isMfaEnabled')->willReturn(true);
        $mfa->method('getUserMfa')->willReturn(UserMfa::create(self::VICTIM_ID, true, 'JBSWY3DPEHPK3PXP'));
        $mfa->expects($this->once())->method('verifyCode')->with(self::VICTIM_ID, '')->willReturn(false);

        $this->post(['mfa_code' => ['123456'], 'mfa_token' => 'issued']);
        $controller = new MfaVerifyController([], $this->environment($this->configure(['security' => ['mfa' => ['enabled' => true]]])));
        $controller->run();

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAFactorThatIsNotEnabledIsRefusedForCodesAndRecoveryCodes(): void
    {
        $this->session->remove(SessionKeys::USERID);
        $mfa = $this->pendingLoginOf(self::VICTIM_ID);
        $mfa->method('isMfaEnabled')->with(self::VICTIM_ID)->willReturn(false);
        // Enrolment saved a secret but was never finished
        $mfa->method('getUserMfa')->willReturn(UserMfa::create(self::VICTIM_ID, false, 'JBSWY3DPEHPK3PXP'));
        $mfa->expects($this->never())->method('verifyCode');
        $mfa->expects($this->never())->method('consumeRecoveryCode');

        $this->submitCode();

        $this->assertSame('No MFA record found. Please contact administrator.', $this->output->rendered[0][1]['msg']);
        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testTheEmailCodeGoesToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $this->session->remove(SessionKeys::USERID);
        $this->session->set(SessionKeys::EMAIL, 'previous@example.com');
        $this->session->set(SessionKeys::PENDING_EMAIL, 'pending@example.com');
        $mfa = $this->pendingLoginOf(self::VICTIM_ID);
        $mfa->method('getMfaType')->willReturn('email');
        $mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')
            ->with(self::VICTIM_ID, 'pending@example.com')->willReturn(null);

        $controller = new MfaVerifyController([], $this->environment($this->configure([
            'security' => ['mfa' => ['enabled' => true]],
            'mail' => ['enabled' => true],
        ])));
        $controller->run();

        $this->assertSame('mfa_verify.html', $this->output->rendered[0][0]);
    }

    private function pendingLoginOf(int $userId): MfaService&MockObject
    {
        $this->session->set(SessionKeys::PENDING_USERID, $userId);
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, true);
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, 'pending');
        $this->session->set(AuthFlowSessionKeys::MFA_TOKEN, 'issued');

        $mfa = $this->createMock(MfaService::class);
        $this->factory->method('mfaService')->willReturn($mfa);
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false));
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $this->factory->method('loginAttemptService')->willReturn($attempts);

        return $mfa;
    }

    private function submitCode(): ?RequestHalted
    {
        $this->post(['mfa_code' => '123456', 'mfa_token' => 'issued']);
        $controller = new MfaVerifyController([], $this->environment($this->configure(['security' => ['mfa' => ['enabled' => true]]])));
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        return null;
    }
}
