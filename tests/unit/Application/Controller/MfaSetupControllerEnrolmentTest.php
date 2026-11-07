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
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Only a pending enrolment of the matching type can be verified on the setup page.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerEnrolmentTest extends TestCase
{
    private array $savedSession = [];
    private array $savedPost = [];
    private MfaService&MockObject $mfa;
    private ArrayObject $messages;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedPost = $_POST;
        $_SESSION = ['userid' => 7, 'userlogin' => 'victim'];
        $this->mfa = $this->createMock(MfaService::class);
        $this->messages = new ArrayObject();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_POST = $this->savedPost;
    }

    private function storedMfa(bool $enabled, string $type): UserMfa&MockObject
    {
        $userMfa = $this->createMock(UserMfa::class);
        $userMfa->method('isEnabled')->willReturn($enabled);
        $userMfa->method('getType')->willReturn($type);
        $this->mfa->method('getOrCreateUserMfa')->willReturn($userMfa);
        $this->mfa->method('getUserMfa')->willReturn($userMfa);

        return $userMfa;
    }

    public function testAppVerificationOfAnEnabledFactorNeverShowsItsSecret(): void
    {
        // The failure page printed the secret, so a wrong code used to hand over the live factor
        $userMfa = $this->storedMfa(true, UserMfa::TYPE_APP);
        $userMfa->expects($this->never())->method('getSecret');
        $this->mfa->expects($this->never())->method('verifyCode');

        $this->verify('handleAppVerification');

        $this->assertSame([['info', 'MFA is already enabled.']], $this->messages->getArrayCopy());
    }

    public function testAppVerificationNeverPrintsAPendingEmailCode(): void
    {
        $userMfa = $this->storedMfa(false, UserMfa::TYPE_EMAIL);
        $userMfa->expects($this->never())->method('getSecret');
        $this->mfa->expects($this->never())->method('verifyCode');

        $this->verify('handleAppVerification');

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages->getArrayCopy());
    }

    public function testEmailVerificationOfAnEnabledFactorIsRefused(): void
    {
        $this->storedMfa(true, UserMfa::TYPE_EMAIL);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');

        $this->verify('handleEmailVerification');

        $this->assertSame([['info', 'MFA is already enabled.']], $this->messages->getArrayCopy());
    }

    public function testAPendingAppEnrolmentStillShowsItsSecretAfterAWrongCode(): void
    {
        $userMfa = $this->storedMfa(false, UserMfa::TYPE_APP);
        $userMfa->expects($this->atLeastOnce())->method('getSecret')->willReturn('JBSWY3DPEHPK3PXP');
        $this->mfa->method('verifyCode')->willReturn(false);

        $this->verify('handleAppVerification');

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages->getArrayCopy());
    }

    private function verify(string $handler): void
    {
        $_POST = ['verification_code' => '000000'];
        $controller = $this->controller();
        (new ReflectionMethod(MfaSetupController::class, $handler))->invoke($controller);
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

        return $controller;
    }
}
