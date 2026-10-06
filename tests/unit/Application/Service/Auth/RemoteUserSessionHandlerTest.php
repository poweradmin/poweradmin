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
namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\RemoteUserIdentitySource;
use Poweradmin\Application\Service\Auth\RemoteUserSessionHandler;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Web\RedirectService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\ValueObject\RemoteUserInfo;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\SessionService;
use Psr\Log\NullLogger;
use RuntimeException;
use TestHelpers\FakeConfiguration;

#[CoversClass(RemoteUserSessionHandler::class)]
class RemoteUserSessionHandlerTest extends TestCase
{
    private ArraySession $session;
    private UserProvisioningService&MockObject $provisioning;
    private AuthenticationService&MockObject $authService;
    private AuditService&MockObject $audit;
    private MfaService&MockObject $mfa;
    private RedirectService&MockObject $redirect;
    private array $serverBackup;

    protected function setUp(): void
    {
        // The MFA redirect depends on whether the request looks like an API call
        $this->serverBackup = $_SERVER;
        $_SERVER['REQUEST_URI'] = '/';
        $this->session = new ArraySession();
        $this->provisioning = $this->createMock(UserProvisioningService::class);
        $this->authService = $this->createMock(AuthenticationService::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->mfa = $this->createMock(MfaService::class);
        $this->redirect = $this->createMock(RedirectService::class);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function handler(array $server, array $remoteUser = [], bool $mfaEnabled = false): RemoteUserSessionHandler
    {
        $config = new FakeConfiguration([
            'remote_user' => $remoteUser + ['enabled' => true],
            'security' => ['mfa' => ['enabled' => $mfaEnabled]],
        ]);

        return new RemoteUserSessionHandler(
            new RemoteUserIdentitySource($config, $server, new NullLogger()),
            $this->provisioning,
            $this->session,
            new SessionService($this->session),
            $this->authService,
            $this->audit,
            $this->mfa,
            $config,
            $this->redirect,
            new NullLogger()
        );
    }

    private function provisions(?int $userId, bool $active = true): void
    {
        $this->provisioning->method('provisionUser')->willReturn($userId);
        $this->provisioning->method('isActiveUser')->willReturn($active);
        $this->provisioning->method('accountProfile')->willReturn(['username' => 'alice', 'fullname' => 'Alice Liddell', 'email' => 'alice@example.com']);
    }

    private function startRemoteSession(int $userId, string $identity): void
    {
        $this->session->set(SessionKeys::USERID, $userId);
        $this->session->set(SessionKeys::USERLOGIN, $identity);
        $this->session->set(SessionKeys::AUTH_METHOD_USED, AuthMethod::REMOTE_USER->value);
        $this->session->set(SessionKeys::REMOTE_USER_IDENTITY, $identity);
        $this->session->set(SessionKeys::LASTMOD, time());
    }

    public function testSignsInTheWebServerUser(): void
    {
        $this->provisions(7);
        $this->provisioning->expects($this->once())->method('provisionUser')
            ->with($this->callback(fn(RemoteUserInfo $info): bool => $info->getUsername() === 'alice'), 'web server');
        $this->audit->expects($this->once())->method('logLoginSuccess')->with(AuthMethod::REMOTE_USER);

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
        $this->assertSame('alice', $this->session->get(SessionKeys::USERLOGIN));
        $this->assertSame('remote_user', $this->session->get(SessionKeys::AUTH_METHOD_USED));
        $this->assertSame('remote_user', $this->session->get(SessionKeys::AUTH_USED));
        $this->assertSame('alice', $this->session->get(SessionKeys::REMOTE_USER_IDENTITY));
        $this->assertNotEmpty($this->session->get(SessionKeys::CSRF_TOKEN));
        $this->assertSame('Alice Liddell', $this->session->get(SessionKeys::NAME), 'the stored account fills the session when the web server sends no name');
        $this->assertSame('alice@example.com', $this->session->get(SessionKeys::EMAIL));
    }

    public function testNothingHappensWithoutAWebServerUser(): void
    {
        $this->provisioning->expects($this->never())->method('provisionUser');

        $this->handler([])->apply(1800, '/');

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testSignedOutSessionIsNotSignedBackIn(): void
    {
        $this->session->set(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT, true);
        $this->provisioning->expects($this->never())->method('provisionUser');

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testSessionFromAnotherSignInMethodIsLeftAlone(): void
    {
        $this->session->set(SessionKeys::USERID, 3);
        $this->session->set(SessionKeys::USERLOGIN, 'bob');
        $this->provisioning->expects($this->never())->method('provisionUser');
        $this->authService->expects($this->never())->method('logout');

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertSame(3, $this->session->get(SessionKeys::USERID));
        $this->assertSame('bob', $this->session->get(SessionKeys::USERLOGIN));
    }

    public function testSameWebServerUserKeepsTheSession(): void
    {
        $this->startRemoteSession(7, 'alice');
        $this->provisioning->expects($this->never())->method('provisionUser');

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testSessionEndsWhenTheWebServerNoLongerSignsAnyoneIn(): void
    {
        $this->startRemoteSession(7, 'alice');
        $this->authService->expects($this->once())->method('logout')->willThrowException(new RuntimeException('halted'));
        $this->expectExceptionMessage('halted');

        $this->handler([])->apply(1800, '/');
    }

    public function testSessionEndsWhenTheFeatureIsTurnedOff(): void
    {
        $this->startRemoteSession(7, 'alice');
        $this->authService->expects($this->once())->method('logout')->willThrowException(new RuntimeException('halted'));
        $this->expectExceptionMessage('halted');

        $this->handler(['REMOTE_USER' => 'alice'], ['enabled' => false])->apply(1800, '/');
    }

    public function testDifferentWebServerUserReplacesTheSession(): void
    {
        $this->startRemoteSession(7, 'alice');
        $this->provisioning->method('provisionUser')->willReturn(8);
        $this->provisioning->method('isActiveUser')->willReturn(true);
        $this->provisioning->method('accountProfile')->willReturn(['username' => 'mallory', 'fullname' => '', 'email' => '']);

        $this->handler(['REMOTE_USER' => 'mallory'])->apply(1800, '/');

        $this->assertSame(8, $this->session->get(SessionKeys::USERID));
        $this->assertSame('mallory', $this->session->get(SessionKeys::REMOTE_USER_IDENTITY));
    }

    public function testIdleSessionIsRenewedWhileTheWebServerStillVouchesForTheUser(): void
    {
        $this->startRemoteSession(7, 'alice');
        $this->session->set(SessionKeys::LASTMOD, time() - 3600);
        $this->provisions(7);
        $this->provisioning->expects($this->once())->method('provisionUser');

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
        $this->assertGreaterThan(time() - 5, $this->session->get(SessionKeys::LASTMOD));
    }

    public function testRefusedAccountIsSentToLoginMarkedSignedOut(): void
    {
        $this->provisions(null);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::REMOTE_USER, null);
        $this->authService->expects($this->once())->method('auth')->willThrowException(new RuntimeException('halted'));

        try {
            $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');
            $this->fail('the refusal halts the request');
        } catch (RuntimeException) {
        }

        $this->assertFalse($this->session->has(SessionKeys::USERID));
        $this->assertTrue($this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT));
    }

    public function testDisabledAccountIsRefused(): void
    {
        $this->provisions(7, active: false);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::REMOTE_USER, LoginFailureReason::ACCOUNT_DISABLED);
        $this->authService->expects($this->once())->method('auth')->willThrowException(new RuntimeException('halted'));

        try {
            $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');
            $this->fail('the refusal halts the request');
        } catch (RuntimeException) {
        }

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testMfaAccountWaitsForTheSecondFactorWithoutAUserId(): void
    {
        $this->provisions(7);
        $this->mfa->method('isMfaEnabled')->with(7)->willReturn(true);
        $this->redirect->expects($this->once())->method('redirectTo')->with('/mfa/verify')->willThrowException(new RuntimeException('halted'));

        try {
            $this->handler(['REMOTE_USER' => 'alice'], [], mfaEnabled: true)->apply(1800, '/');
            $this->fail('the MFA redirect halts the request');
        } catch (RuntimeException) {
        }

        $this->assertFalse($this->session->has(SessionKeys::USERID));
        $this->assertSame(7, $this->session->get(SessionKeys::PENDING_USERID));
        $this->assertSame('remote_user', $this->session->get(SessionKeys::PENDING_AUTH_METHOD_USED));
        $this->assertSame('alice@example.com', $this->session->get(SessionKeys::PENDING_EMAIL), 'email MFA needs the stored address');
    }

    public function testPendingMfaSessionIsSentBackToVerification(): void
    {
        $this->session->set(SessionKeys::PENDING_USERID, 7);
        $this->session->set(SessionKeys::PENDING_AUTH_METHOD_USED, 'remote_user');
        $this->session->set(SessionKeys::REMOTE_USER_IDENTITY, 'alice');
        $this->provisioning->expects($this->never())->method('provisionUser');
        $this->redirect->expects($this->once())->method('redirectTo')->with('/mfa/verify');

        $this->handler(['REMOTE_USER' => 'alice'], [], mfaEnabled: true)->apply(1800, '/');
    }

    public function testLogoutStaysReachableWhileTheSecondFactorIsPending(): void
    {
        $this->session->set(SessionKeys::PENDING_USERID, 7);
        $this->session->set(SessionKeys::PENDING_AUTH_METHOD_USED, 'remote_user');
        $this->session->set(SessionKeys::REMOTE_USER_IDENTITY, 'alice');
        $this->redirect->expects($this->never())->method('redirectTo');

        $this->handler(['REMOTE_USER' => 'alice'], [], mfaEnabled: true)->apply(1800, '/logout');

        $this->assertSame(7, $this->session->get(SessionKeys::PENDING_USERID));
    }

    /**
     * A pending session can only be promoted by /mfa/verify, which no longer asks
     * once MFA is off, so the user is signed in afresh instead of bouncing forever.
     */
    public function testPendingSessionIsSignedInAgainOnceMfaIsSwitchedOff(): void
    {
        $this->session->set(SessionKeys::PENDING_USERID, 7);
        $this->session->set(SessionKeys::PENDING_AUTH_METHOD_USED, 'remote_user');
        $this->session->set(SessionKeys::REMOTE_USER_IDENTITY, 'alice');
        $this->provisions(7);
        $this->redirect->expects($this->never())->method('redirectTo');

        $this->handler(['REMOTE_USER' => 'alice'], [], mfaEnabled: false)->apply(1800, '/');

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_USERID));
    }

    public function testSignInStartsFromACleanSessionWithAFreshCsrfToken(): void
    {
        $this->session->set(SessionKeys::USERPWD, 'left over from a password attempt');
        $this->session->set(SessionKeys::CSRF_TOKEN, 'planted');
        $this->provisions(7);

        $this->handler(['REMOTE_USER' => 'alice'])->apply(1800, '/');

        $this->assertFalse($this->session->has(SessionKeys::USERPWD));
        $this->assertNotSame('planted', $this->session->get(SessionKeys::CSRF_TOKEN));
    }
}
