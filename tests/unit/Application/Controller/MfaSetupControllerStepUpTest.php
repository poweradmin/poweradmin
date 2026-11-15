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

namespace Poweradmin\Tests\Unit\Application\Controller;

use ArrayObject;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaSetupController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Disabling MFA and regenerating recovery codes need a current second-factor code,
 * so a hijacked session cannot do either with the CSRF token alone.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerStepUpTest extends TestCase
{
    private const USER_ID = 42;

    private array $savedSession = [];
    private array $savedPost = [];
    private MfaService&MockObject $mfa;
    private ArrayObject $messages;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedPost = $_POST;
        $_SESSION = ['userid' => self::USER_ID, 'userlogin' => 'alice', 'email' => 'alice@example.com'];
        $this->mfa = $this->createMock(MfaService::class);
        $this->mfa->method('isMfaEnforced')->willReturn(false);
        $this->messages = new ArrayObject();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_POST = $this->savedPost;
    }

    private function enabledMfa(string $type): void
    {
        $this->mfa->method('getUserMfa')->willReturn(UserMfa::create(self::USER_ID, true, 'JBSWY3DPEHPK3PXP', null, $type));
        $this->mfa->method('getMfaType')->willReturn($type);
    }

    public function testDisableIsRefusedWithAWrongCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->with(self::USER_ID, '000000')->willReturn(false);
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit('handleMfaDisable', ['mfa_code' => '000000']);

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages->getArrayCopy());
    }

    public function testDisableWithoutACodeIsRefusedBeforeAnyCheck(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit('handleMfaDisable', []);

        $this->assertSame([['error', 'Verification code is required.']], $this->messages->getArrayCopy());
    }

    public function testDisableWorksWithAValidCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->with(self::USER_ID, '123456')->willReturn(true);
        $this->mfa->expects($this->once())->method('disableMfa')->with(self::USER_ID);

        $this->submit('handleMfaDisable', ['mfa_code' => '123456']);

        $this->assertSame([['success', 'MFA has been disabled.']], $this->messages->getArrayCopy());
    }

    public function testRegenerateIsRefusedWithAWrongCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->willReturn(false);
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');

        $this->submit('handleRegenerateRecoveryCodes', ['mfa_code' => '000000']);

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages->getArrayCopy());
    }

    public function testRegenerateWorksWithAValidCode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->method('verifyCode')->willReturn(true);
        $this->mfa->method('getOrCreateUserMfa')->willReturn(UserMfa::create(self::USER_ID, true, 'JBSWY3DPEHPK3PXP'));
        $this->mfa->expects($this->once())->method('regenerateRecoveryCodes')->with(self::USER_ID)->willReturn(['abc']);

        $this->submit('handleRegenerateRecoveryCodes', ['mfa_code' => 'recovery-1']);

        $this->assertSame(
            [['success', 'Recovery codes have been regenerated. Please save them in a safe place.']],
            $this->messages->getArrayCopy()
        );
    }

    public function testAnEmptySubmitSendsAnEmailUserACode(): void
    {
        $this->enabledMfa(UserMfa::TYPE_EMAIL);
        $this->mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')
            ->with(self::USER_ID, 'alice@example.com')->willReturn('123456');
        $this->mfa->expects($this->never())->method('disableMfa');

        $this->submit('handleMfaDisable', []);

        $this->assertSame([
            ['info', 'A new verification code has been sent to your email.'],
            ['error', 'Verification code is required.'],
        ], $this->messages->getArrayCopy());
    }

    public function testAnEmptySubmitSendsAnAppUserNothing(): void
    {
        $this->enabledMfa(UserMfa::TYPE_APP);
        $this->mfa->expects($this->never())->method('refreshEmailVerificationCodeIfNeeded');

        $this->submit('handleRegenerateRecoveryCodes', []);

        $this->assertSame([['error', 'Verification code is required.']], $this->messages->getArrayCopy());
    }

    private function submit(string $handler, array $fields): void
    {
        $_POST = $fields;
        (new ReflectionMethod(MfaSetupController::class, $handler))->invoke($this->controller());
    }

    private function controller(): MfaSetupController
    {
        $controller = new class ($this->messages) extends MfaSetupController {
            public function __construct(private readonly ArrayObject $messages)
            {
            }

            public function addSystemMessage(string $type, string $content): void
            {
                $this->messages[] = [$type, $content];
            }

            public function render(string $template, array $params): void
            {
            }
        };

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        (new ReflectionProperty(BaseController::class, 'config'))->setValue($controller, $config);
        (new ReflectionProperty(BaseController::class, 'logger'))->setValue($controller, new NullLogger());
        (new ReflectionProperty(BaseController::class, 'db'))->setValue($controller, $this->createMock(PDO::class));
        (new ReflectionProperty(MfaSetupController::class, 'mfaService'))->setValue($controller, $this->mfa);
        (new ReflectionProperty(MfaSetupController::class, 'userContextService'))->setValue($controller, new UserContextService());
        (new ReflectionProperty(MfaSetupController::class, 'auditLogger'))->setValue($controller, $this->createMock(LegacyLogger::class));
        (new ReflectionProperty(MfaSetupController::class, 'ipAddressRetriever'))->setValue($controller, new IpAddressRetriever(['REMOTE_ADDR' => '192.0.2.10']));

        return $controller;
    }
}
