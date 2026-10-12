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

use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaVerifyController;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The second factor checked is the pending login's, and only once that login has
 * finished enrolling it. Rendering the form is cut short by getMfaType throwing.
 */
#[CoversClass(MfaVerifyController::class)]
class MfaVerifyControllerTest extends TestCase
{
    private const PENDING_ID = 42;

    private array $sessionBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $this->postBackup = $_POST;
        $_SESSION = [
            'userlogin' => 'victim',
            'pending_userid' => self::PENDING_ID,
            'pending_email' => 'pending@example.com',
            'mfa_required' => true,
            'mfa_token' => 'issued',
        ];
        $_POST = ['mfa_code' => '123456', 'mfa_token' => 'issued'];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    public function testAFactorThatIsNotEnabledIsRefusedBeforeTheCodeIsChecked(): void
    {
        $mfa = $this->createMock(MfaService::class);
        $mfa->method('isMfaEnabled')->with(self::PENDING_ID)->willReturn(false);
        // Enrolment saved a secret but was never finished
        $mfa->method('getUserMfa')->willReturn(UserMfa::create(self::PENDING_ID, false, 'JBSWY3DPEHPK3PXP'));
        $mfa->expects($this->never())->method('verifyCode');
        $mfa->method('getMfaType')->willThrowException(new Error('form rendered'));

        $this->expectExceptionMessage('form rendered');
        $this->invoke($mfa, 'handleMfaVerification');
    }

    public function testTheCodeIsCheckedForThePendingLogin(): void
    {
        $mfa = $this->createMock(MfaService::class);
        $mfa->method('isMfaEnabled')->willReturn(true);
        $mfa->method('getUserMfa')->willReturn(UserMfa::create(self::PENDING_ID, true, 'JBSWY3DPEHPK3PXP'));
        $mfa->expects($this->once())->method('verifyCode')->with(self::PENDING_ID, '123456')->willReturn(false);
        $mfa->method('getMfaType')->willThrowException(new Error('form rendered'));

        $this->expectExceptionMessage('form rendered');
        $this->invoke($mfa, 'handleMfaVerification');
    }

    public function testTheEmailCodeGoesToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $_SESSION['email'] = 'previous@example.com';
        $sentTo = null;
        $mfa = $this->createMock(MfaService::class);
        $mfa->method('getMfaType')->willReturn('email');
        $mfa->method('refreshEmailVerificationCodeIfNeeded')->willReturnCallback(
            function (int $userId, string $email) use (&$sentTo): never {
                $sentTo = $email;
                throw new Error('code sent');
            }
        );

        try {
            $this->invoke($mfa, 'displayMfaForm');
            $this->fail('The email code was not refreshed');
        } catch (Error $e) {
            $this->assertSame('code sent', $e->getMessage());
        }

        $this->assertSame('pending@example.com', $sentTo);
    }

    private function invoke(MfaService&MockObject $mfa, string $method): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $group === 'mail' && $key === 'enabled' ? true : $default
        );

        $reflection = new ReflectionClass(MfaVerifyController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $base = new ReflectionClass(BaseController::class);
        $properties = [
            'mfaService' => $mfa,
            'csrfTokenService' => new CsrfTokenService(),
            'userContextService' => new UserContextService(),
            'config' => $config,
            'logger' => new NullLogger(),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($controller, $value);
            if ($base->hasProperty($name) && $base->getProperty($name)->isPrivate()) {
                $base->getProperty($name)->setValue($controller, $value);
            }
        }

        $reflection->getMethod($method)->invoke($controller);
    }
}
