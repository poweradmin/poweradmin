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
use LogicException;
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
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The second factor checked, and the address a code is sent to, belong to the pending
 * login whenever there is one, never to a user id or address left in the session.
 */
#[CoversClass(MfaVerifyController::class)]
class MfaVerifyControllerTest extends TestCase
{
    private const PENDING_ID = 42;

    private array $savedSession = [];
    private array $savedPost = [];
    private ?ConfigurationManager $savedConfigInstance = null;
    private MfaService&MockObject $mfa;
    private ArrayObject $rendered;
    private ArrayObject $redirects;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedPost = $_POST;
        $this->savedConfigInstance = (new ReflectionProperty(ConfigurationManager::class, 'instance'))->getValue();
        $this->mfa = $this->createMock(MfaService::class);
        $this->rendered = new ArrayObject();
        $this->redirects = new ArrayObject();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_POST = $this->savedPost;
        (new ReflectionProperty(ConfigurationManager::class, 'instance'))->setValue(null, $this->savedConfigInstance);
    }

    public function testThePendingLoginIsTheChallengedAccount(): void
    {
        $_SESSION = ['pending_userid' => '42', 'userlogin' => 'victim'];

        $this->assertSame(42, $this->invoke('challengedUserId'));
    }

    public function testTheSignedInAccountIsChallengedWhenNoLoginIsPending(): void
    {
        $_SESSION = ['userid' => 7, 'userlogin' => 'admin'];

        $this->assertSame(7, $this->invoke('challengedUserId'));
    }

    public function testThePendingLoginOfTheSignedInAccountIsChallenged(): void
    {
        $_SESSION = ['userid' => 42, 'pending_userid' => '42', 'userlogin' => 'victim'];

        $this->assertSame(42, $this->invoke('challengedUserId'));
    }

    public function testTheCodeGoesToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $_SESSION = ['pending_userid' => 42, 'pending_email' => 'pending@example.com', 'email' => 'previous@example.com'];

        $this->assertSame('pending@example.com', $this->invoke('challengedEmail'));
    }

    public function testAPendingLoginWithoutAnAddressGetsNoneFromTheSession(): void
    {
        $_SESSION = ['pending_userid' => 42, 'email' => 'previous@example.com'];

        $this->assertNull($this->invoke('challengedEmail'));
    }

    public function testTheSignedInAddressIsUsedWhenNoLoginIsPending(): void
    {
        $_SESSION = ['userid' => 7, 'email' => 'admin@example.com'];

        $this->assertSame('admin@example.com', $this->invoke('challengedEmail'));
    }

    public function testAFactorThatIsNotEnabledIsRefusedBeforeTheCodeIsChecked(): void
    {
        $_SESSION = ['pending_userid' => self::PENDING_ID, 'userlogin' => 'victim', 'mfa_required' => true];
        $_POST = ['mfa_code' => '123456', 'mfa_token' => 'issued'];
        $this->mfa->method('isMfaEnabled')->with(self::PENDING_ID)->willReturn(false);
        // Enrolment saved a secret but was never finished
        $this->mfa->method('getUserMfa')->willReturn(UserMfa::create(self::PENDING_ID, false, 'JBSWY3DPEHPK3PXP'));
        $this->mfa->expects($this->never())->method('verifyCode');

        $controller = $this->controller([]);
        (new ReflectionMethod(MfaVerifyController::class, 'handleMfaVerification'))->invoke($controller);

        $this->assertSame('No MFA record found. Please contact administrator.', $this->rendered[0][1]['msg']);
        $this->assertArrayNotHasKey('userid', $_SESSION);
    }

    public function testTheEmailCodeIsSentToThePendingLoginNotToAnAddressLeftInTheSession(): void
    {
        $_SESSION = [
            'pending_userid' => self::PENDING_ID,
            'userlogin' => 'victim',
            'mfa_required' => true,
            'pending_email' => 'pending@example.com',
            'email' => 'previous@example.com',
        ];
        $this->mfa->method('getMfaType')->willReturn('email');
        $this->mfa->expects($this->once())->method('refreshEmailVerificationCodeIfNeeded')
            ->with(self::PENDING_ID, 'pending@example.com')->willReturn(null);

        $controller = $this->controller(['mail' => ['enabled' => true]]);
        (new ReflectionMethod(MfaVerifyController::class, 'displayMfaForm'))->invoke($controller);

        $this->assertSame('mfa_verify.html', $this->rendered[0][0]);
    }

    public function testAPendingLoginOnAnotherUsersSessionEndsTheSessionWithoutCheckingTheCode(): void
    {
        $_SESSION = ['userid' => 7, 'pending_userid' => self::PENDING_ID, 'userlogin' => 'victim', 'mfa_required' => true];
        $_POST = ['mfa_code' => '123456', 'mfa_token' => 'issued'];
        $this->mfa->method('isMfaEnabled')->willReturn(true);
        $this->mfa->method('getUserMfa')->willReturn(UserMfa::create(7, true, 'JBSWY3DPEHPK3PXP'));
        $this->mfa->expects($this->never())->method('verifyCode');
        // AuthenticationService::logout() redirects and exits; stop it at its config read instead
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willThrowException(new LogicException('redirected to login'));
        (new ReflectionProperty(ConfigurationManager::class, 'instance'))->setValue(null, $config);

        $controller = $this->controller([]);
        try {
            (new ReflectionMethod(MfaVerifyController::class, 'handleMfaVerification'))->invoke($controller);
            $this->fail('The session was not ended');
        } catch (LogicException $e) {
            $this->assertSame('redirected to login', $e->getMessage());
        }

        $this->assertArrayNotHasKey('userid', $_SESSION);
        $this->assertArrayNotHasKey('userlogin', $_SESSION);
        $this->assertArrayNotHasKey('mfa_required', $_SESSION);
    }

    /**
     * @param array<string, array<string, mixed>> $settings
     */
    private function controller(array $settings): MfaVerifyController
    {
        $controller = new class ($this->rendered, $this->redirects) extends MfaVerifyController {
            public function __construct(private readonly ArrayObject $rendered, private readonly ArrayObject $redirects)
            {
            }

            public function redirect(string $url, array $args = []): void
            {
                $this->redirects[] = $url;
                // The real redirect exits
                throw new RuntimeException('redirected');
            }

            public function render(string $template, array $params): void
            {
                $this->rendered[] = [$template, $params];
            }
        };

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $settings[$group][$key] ?? $default
        );
        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->method('validateToken')->willReturn(true);
        $csrf->method('generateToken')->willReturn('next');

        (new ReflectionProperty(BaseController::class, 'config'))->setValue($controller, $config);
        (new ReflectionProperty(MfaVerifyController::class, 'mfaService'))->setValue($controller, $this->mfa);
        (new ReflectionProperty(MfaVerifyController::class, 'csrfTokenService'))->setValue($controller, $csrf);
        (new ReflectionProperty(MfaVerifyController::class, 'userContextService'))->setValue($controller, new UserContextService());

        return $controller;
    }

    private function invoke(string $method): mixed
    {
        $reflection = new ReflectionClass(MfaVerifyController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('userContextService')->setValue($controller, new UserContextService());

        return $reflection->getMethod($method)->invoke($controller);
    }
}
