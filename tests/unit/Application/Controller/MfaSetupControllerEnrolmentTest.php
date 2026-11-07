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
use ReflectionClass;

/**
 * The setup page verifies a code only against a pending enrolment of the matching type, and a
 * refused attempt never re-displays a stored secret.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerEnrolmentTest extends TestCase
{
    private array $sessionBackup = [];
    private array $postBackup = [];
    private MfaService&MockObject $mfa;
    /** @var list<array{string, string}> */
    private array $messages = [];
    /** @var list<string> */
    private array $rendered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $this->postBackup = $_POST;
        $_SESSION = ['userid' => 7, 'userlogin' => 'victim', 'email' => 'victim@example.com'];
        $this->mfa = $this->createMock(MfaService::class);
        $this->messages = [];
        $this->rendered = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
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

        $this->submit('handleAppVerification');

        $this->assertSame([['info', 'MFA is already enabled.']], $this->messages);
        $this->assertSame(['mfa_setup.html'], $this->rendered);
    }

    public function testAppVerificationNeverPrintsAPendingEmailCode(): void
    {
        $userMfa = $this->storedMfa(false, UserMfa::TYPE_EMAIL);
        $userMfa->expects($this->never())->method('getSecret');
        $this->mfa->expects($this->never())->method('verifyCode');

        $this->submit('handleAppVerification');

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages);
        $this->assertSame(['mfa_setup.html'], $this->rendered);
    }

    public function testEmailVerificationOfAnEnabledFactorIsRefused(): void
    {
        $this->storedMfa(true, UserMfa::TYPE_EMAIL);
        $this->mfa->expects($this->never())->method('verifyCode');
        $this->mfa->expects($this->never())->method('regenerateRecoveryCodes');

        $this->submit('handleEmailVerification');

        $this->assertSame([['info', 'MFA is already enabled.']], $this->messages);
    }

    public function testAPendingAppEnrolmentStillShowsItsSecretAfterAWrongCode(): void
    {
        $userMfa = $this->storedMfa(false, UserMfa::TYPE_APP);
        $userMfa->expects($this->atLeastOnce())->method('getSecret')->willReturn('JBSWY3DPEHPK3PXP');
        $this->mfa->method('verifyCode')->willReturn(false);
        $this->mfa->method('generateQrCodeSvg')->willReturn('');

        $this->submit('handleAppVerification');

        $this->assertSame([['error', 'Invalid verification code. Please try again.']], $this->messages);
        $this->assertSame(['mfa_verify_app.html'], $this->rendered);
    }

    private function submit(string $method): void
    {
        $_POST = ['verification_code' => '000000'];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        $controller = $this->getMockBuilder(MfaSetupController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render', 'addSystemMessage'])
            ->getMock();
        $controller->method('render')->willReturnCallback(function (string $template): void {
            $this->rendered[] = $template;
        });
        $controller->method('addSystemMessage')->willReturnCallback(function (string $type, string $content): void {
            $this->messages[] = [$type, $content];
        });

        $reflection = new ReflectionClass(MfaSetupController::class);
        $base = new ReflectionClass(BaseController::class);
        $properties = [
            'mfaService' => $this->mfa,
            'userContextService' => new UserContextService(),
            'config' => $config,
            'logger' => new NullLogger(),
            'db' => $this->createMock(PDO::class),
        ];
        foreach ($properties as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue($controller, $value);
            }
            if ($base->hasProperty($name)) {
                $base->getProperty($name)->setValue($controller, $value);
            }
        }

        $reflection->getMethod($method)->invoke($controller);
    }
}
