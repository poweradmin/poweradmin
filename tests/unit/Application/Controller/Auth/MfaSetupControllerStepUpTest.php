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
use Poweradmin\Application\Controller\Auth\MfaSetupController;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Disabling MFA and regenerating recovery codes need a current second-factor code,
 * so a hijacked session cannot do either with the CSRF token alone.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerStepUpTest extends SeamControllerTestCase
{
    private MfaService&MockObject $mfa;
    private LoginAttemptService&MockObject $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mfa = $this->createMock(MfaService::class);
        $this->mfa->method('isMfaEnforced')->willReturn(false);
        $this->attempts = $this->createMock(LoginAttemptService::class);
        $this->factory->method('mfaService')->willReturn($this->mfa);
        $this->factory->method('loginAttemptService')->willReturn($this->attempts);
        $this->factory->method('clientContext')->willReturn(new ClientContext('192.0.2.10', 'test', 'test', false));
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
    }

    private function enabledMfa(string $type): void
    {
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('isEnabled')->willReturn(true);
        $userMfa->method('getType')->willReturn($type);
        $this->mfa->method('getUserMfa')->willReturn($userMfa);
        $this->mfa->method('getMfaType')->willReturn($type);
    }

    private function submit(array $fields): void
    {
        $config = $this->configure(['security' => ['mfa' => ['enabled' => true]]]);
        $this->post($fields);
        (new MfaSetupController([], true, $this->environment($config)))->run();
    }

    public function testDisableIsRefusedWithAWrongCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->with(self::USER_ID, '000000')->willReturn(false);
        $this->mfa->expects($this->never())->method('disableMfa');
        $this->attempts->expects($this->once())->method('recordAttempt')
            ->with(self::USERNAME, '192.0.2.10', false, LoginAttemptService::STAGE_MFA_STEP_UP, self::USER_ID);

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '000000']);

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messagesFor('system'));
    }

    public function testDisableWorksWithAValidCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->willReturn(true);
        $this->mfa->expects($this->once())->method('disableMfa')->with(self::USER_ID);

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '123456']);

        $this->assertSame([['success', 'MFA has been disabled.']], $this->messagesFor('system'));
    }

    public function testRegenerateWithoutACodeIsRefusedBeforeAnyCheck(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');
        $this->mfa->expects($this->never())->method('refreshEmailVerificationCodeIfNeeded');

        $this->submit(['regenerate_codes' => '1']);

        $this->assertSame([['error', 'Verification code is required.']], $this->messagesFor('system'));
    }

    public function testAThrottledAccountIsRefusedWithoutCheckingTheCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->attempts->method('isAccountLocked')->willReturn(true);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');

        $this->submit(['regenerate_codes' => '1', 'mfa_code' => '123456']);

        $this->assertSame([['error', 'Too many failed attempts. Please try again later.']], $this->messagesFor('system'));
    }

    public function testAnEmptySubmitSendsAnEmailUserACode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')->willReturn('123456');
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit(['disable_mfa' => '1']);

        $this->assertSame([
            ['info', 'A new verification code has been sent to your email.'],
            ['error', 'Verification code is required.'],
        ], $this->messagesFor('system'));
    }

    public function testAPendingEmailCodeIsNotReplacedByAnotherEmptySubmit(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->method('refreshEmailVerificationCodeIfNeeded')->willReturn(null);
        $this->mfa->expects($this->never())->method('sendEmailVerificationCode');

        $this->submit(['regenerate_codes' => '1']);

        $this->assertSame([['error', 'Verification code is required.']], $this->messagesFor('system'));
    }

    public function testTheFailureThatTripsTheLimitBurnsThePendingEmailCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->attempts->method('isAccountLocked')->willReturnOnConsecutiveCalls(false, true);
        $this->mfa->method('verifyCode')->willReturn(false);
        $this->mfa->expects($this->once())->method('invalidatePendingEmailCode')->with(self::USER_ID);

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '000000']);
    }

    public function testAFailedEmailSendIsShownWithTheRecoveryCodeHint(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->method('refreshEmailVerificationCodeIfNeeded')->willThrowException(new \RuntimeException('Could not send the verification code by email.'));
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit(['disable_mfa' => '1']);

        $this->assertSame([
            ['warning', 'Could not send verification code to your email. Please use a recovery code instead.'],
            ['error', 'Verification code is required.'],
        ], $this->messagesFor('system'));
    }
}
