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

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaSetupController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Disabling MFA and regenerating recovery codes need a current second-factor code,
 * so a hijacked session cannot do either with the CSRF token alone.
 */
class MfaSetupControllerStepUpTest extends TestCase
{
    private const USER_ID = 42;
    private const USERNAME = 'alice';
    private const IP = '192.0.2.10';

    private MfaService&MockObject $mfa;
    private LoginAttemptService&MockObject $attempts;

    protected function setUp(): void
    {
        UserContextService::clearApiUserContext();
        $_SESSION = [
            SessionKeys::USERID => self::USER_ID,
            SessionKeys::USERLOGIN => self::USERNAME,
            SessionKeys::EMAIL => 'alice@example.com',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_GET = [];

        $this->mfa = $this->createMock(MfaService::class);
        $this->mfa->method('isMfaEnforced')->willReturn(false);
        $this->attempts = $this->createMock(LoginAttemptService::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testDisableIsRefusedWithAWrongCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->with(self::USER_ID, '000000')->willReturn(false);
        $this->mfa->expects($this->never())->method('disableMfa');
        $this->attempts->expects($this->once())->method('recordAttempt')
            ->with(self::USERNAME, self::IP, false, LoginAttemptService::STAGE_MFA_STEP_UP, self::USER_ID);

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '000000']);

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->systemMessages());
    }

    public function testDisableWorksWithAValidCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->with(self::USER_ID, '123456')->willReturn(true);
        $this->mfa->expects($this->once())->method('disableMfa')->with(self::USER_ID);

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '123456']);

        $this->assertSame([['success', 'MFA has been disabled.']], $this->systemMessages());
    }

    public function testRegenerateWithoutACodeIsRefusedBeforeAnyCheck(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');
        $this->mfa->expects($this->never())->method('refreshEmailVerificationCodeIfNeeded');

        $this->submit(['regenerate_codes' => '1']);

        $this->assertSame([['error', 'Verification code is required.']], $this->systemMessages());
    }

    public function testRegenerateWorksWithAValidCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->willReturn(true);
        $this->mfa->expects($this->once())->method('regenerateRecoveryCodes')->with(self::USER_ID)->willReturn(['abc']);
        $this->mfa->method('getOrCreateUserMfa')->willReturn(UserMfa::create(self::USER_ID, true, 'JBSWY3DPEHPK3PXP'));

        $this->submit(['regenerate_codes' => '1', 'mfa_code' => 'recovery-1']);

        $this->assertSame(
            [['success', 'Recovery codes have been regenerated. Please save them in a safe place.']],
            $this->systemMessages()
        );
    }

    public function testAThrottledAccountIsRefusedWithoutCheckingTheCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->attempts->method('isAccountLocked')
            ->with(self::USERNAME, self::IP, LoginAttemptService::STAGE_MFA_STEP_UP, self::USER_ID)
            ->willReturn(true);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');

        $this->submit(['regenerate_codes' => '1', 'mfa_code' => '123456']);

        $this->assertSame([['error', 'Too many failed attempts. Please try again later.']], $this->systemMessages());
    }

    public function testAnEmptySubmitSendsAnEmailUserACode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')
            ->with(self::USER_ID, 'alice@example.com')->willReturn('123456');
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit(['disable_mfa' => '1']);

        $this->assertSame([
            ['info', 'A new verification code has been sent to your email.'],
            ['error', 'Verification code is required.'],
        ], $this->systemMessages());
    }

    public function testAPendingEmailCodeIsNotReplacedByAnotherEmptySubmit(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->method('refreshEmailVerificationCodeIfNeeded')->willReturn(null);
        $this->mfa->expects($this->never())->method('sendEmailVerificationCode');

        $this->submit(['regenerate_codes' => '1']);

        $this->assertSame([['error', 'Verification code is required.']], $this->systemMessages());
    }

    public function testTheFailureThatTripsTheLimitBurnsThePendingEmailCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->attempts->method('isAccountLocked')->willReturnOnConsecutiveCalls(false, true);
        $this->mfa->method('verifyCode')->willReturn(false);
        $this->mfa->expects($this->once())->method('invalidatePendingEmailCode')->with(self::USER_ID);
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit(['disable_mfa' => '1', 'mfa_code' => '000000']);
    }

    private function enabledMfa(string $type): void
    {
        $userMfa = UserMfa::create(self::USER_ID, true, 'JBSWY3DPEHPK3PXP', null, $type);
        $this->mfa->method('getUserMfa')->willReturn($userMfa);
        $this->mfa->method('getMfaType')->willReturn($type);
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields): void
    {
        $_POST = $fields;
        $this->controller()->run();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function systemMessages(): array
    {
        $messages = $_SESSION[SessionKeys::MESSAGES]['system'] ?? [];

        return array_map(static fn(array $m): array => [$m['type'], $m['content']], $messages);
    }

    private function controller(): MfaSetupController
    {
        $settings = ['security.mfa.enabled' => true, 'security.global_token_validation' => false];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $settings["$group.$key"] ?? $default
        );

        $controller = $this->getMockBuilder(MfaSetupController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render'])
            ->getMock();

        $base = [
            'config' => $config,
            'logger' => new NullLogger(),
            'db' => $this->createMock(PDO::class),
            'requestData' => [],
            'messageService' => new MessageService(),
        ];
        foreach ($base as $name => $value) {
            (new ReflectionProperty(BaseController::class, $name))->setValue($controller, $value);
        }

        $own = [
            'mfaService' => $this->mfa,
            'userContextService' => new UserContextService(),
            'auditLogger' => $this->createMock(LegacyLogger::class),
            'ipAddressRetriever' => new IpAddressRetriever(['REMOTE_ADDR' => self::IP]),
            'loginAttemptService' => $this->attempts,
            'request' => new Request(),
        ];
        foreach ($own as $name => $value) {
            (new ReflectionProperty(MfaSetupController::class, $name))->setValue($controller, $value);
        }

        return $controller;
    }
}
