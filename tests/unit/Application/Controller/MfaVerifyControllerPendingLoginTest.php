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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaVerifyController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * The second factor checked is the pending login's, and a pending login layered on
 * another user's session ends that session instead of promoting either account.
 */
class MfaVerifyControllerPendingLoginTest extends TestCase
{
    private const VICTIM_ID = 42;
    private const ATTACKER_ID = 7;

    private MfaService&MockObject $mfa;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $rendered = [];

    protected function setUp(): void
    {
        UserContextService::clearApiUserContext();
        $_SESSION = [
            SessionKeys::USERLOGIN => 'victim',
            SessionKeys::PENDING_USERID => self::VICTIM_ID,
            SessionKeys::MFA_REQUIRED => true,
            SessionKeys::MFA_TOKEN => 'issued',
        ];
        $_POST = [];
        $_GET = [];
        $this->mfa = $this->createMock(MfaService::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    public function testAPendingLoginOnAnotherUsersSessionEndsTheSessionWithoutCheckingTheCode(): void
    {
        $_SESSION[SessionKeys::USERID] = self::ATTACKER_ID;
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('consumeRecoveryCode');

        $target = $this->submitCode();

        $this->assertSame('/login', $target);
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_USERID, $_SESSION);
        $this->assertSame('Session expired, please login again.', $_SESSION[SessionKeys::LOGIN_MESSAGE]);
    }

    public function testTheCodeIsCheckedForThePendingLogin(): void
    {
        $this->mfa->method('isMfaEnabled')->willReturn(true);
        $this->mfa->method('getUserMfa')->willReturn(UserMfa::create(self::VICTIM_ID, true, 'JBSWY3DPEHPK3PXP'));
        $this->mfa->expects($this->once())->method('verifyCode')->with(self::VICTIM_ID, '123456')->willReturn(false);

        $this->submitCode();

        $this->assertSame(self::VICTIM_ID, $_SESSION[SessionKeys::PENDING_USERID]);
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    public function testAFactorThatIsNotEnabledIsRefusedForCodesAndRecoveryCodes(): void
    {
        $this->mfa->method('isMfaEnabled')->with(self::VICTIM_ID)->willReturn(false);
        // Enrolment saved a secret but was never finished
        $this->mfa->method('getUserMfa')->willReturn(UserMfa::create(self::VICTIM_ID, false, 'JBSWY3DPEHPK3PXP'));
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('consumeRecoveryCode');

        $this->submitCode(locked: true);

        $this->assertSame('No MFA record found. Please contact administrator.', $this->rendered[0][1]['msg']);
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    public function testTheEmailCodeGoesToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $_SESSION[SessionKeys::EMAIL] = 'previous@example.com';
        $_SESSION[SessionKeys::PENDING_EMAIL] = 'pending@example.com';
        $this->mfa->method('getMfaType')->willReturn('email');
        $this->mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')
            ->with(self::VICTIM_ID, 'pending@example.com')->willReturn(null);

        $this->controller(['mail.enabled' => true])->run();

        $this->assertSame('mfa_verify.html', $this->rendered[0][0]);
    }

    /**
     * Posts a code and returns the redirect target, or null when the form was rendered.
     */
    private function submitCode(bool $locked = false): ?string
    {
        $_POST = ['mfa_code' => '123456', 'mfa_token' => 'issued'];
        try {
            $this->controller([], $locked)->run();
        } catch (RuntimeException $redirect) {
            return $redirect->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings keyed as "group.key"
     */
    private function controller(array $settings, bool $locked = false): MfaVerifyController
    {
        $settings += ['security.mfa.enabled' => true];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $settings["$group.$key"] ?? $default
        );

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn($locked);

        $controller = $this->getMockBuilder(MfaVerifyController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render', 'redirect'])
            ->getMock();
        $controller->method('redirect')->willReturnCallback(static function (string $url): void {
            throw new RuntimeException($url);
        });
        $controller->method('render')->willReturnCallback(function (string $template, array $params): void {
            $this->rendered[] = [$template, $params];
        });

        (new ReflectionProperty(BaseController::class, 'config'))->setValue($controller, $config);
        (new ReflectionProperty(BaseController::class, 'logger'))->setValue($controller, new NullLogger());
        $own = [
            'mfaService' => $this->mfa,
            'csrfTokenService' => new CsrfTokenService(),
            'userContextService' => new UserContextService(),
            'auditLogger' => $this->createMock(LegacyLogger::class),
            'ipAddressRetriever' => new IpAddressRetriever(['REMOTE_ADDR' => '203.0.113.9']),
            'loginAttemptService' => $attempts,
            'request' => new Request(),
        ];
        foreach ($own as $name => $value) {
            (new ReflectionProperty(MfaVerifyController::class, $name))->setValue($controller, $value);
        }

        return $controller;
    }
}
