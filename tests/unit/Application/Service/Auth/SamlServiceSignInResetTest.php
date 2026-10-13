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

use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\SamlConfigurationService;
use Poweradmin\Application\Service\Auth\SamlService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\PasswordEncryptionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * A SAML sign-in starts from an empty session, so the identity of the user the
 * session belonged to before cannot sit beside the new one.
 */
#[CoversClass(SamlService::class)]
final class SamlServiceSignInResetTest extends TestCase
{
    private const USER_ID = 7;
    private const SESSION_KEY = 'test-session-key';

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $cookie;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;
        // No proxy headers, so the assertion is processed with the server variables as they are
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        // The sign-in was started from this browser
        $_COOKIE = ['__Host-saml_flow' => (new PasswordEncryptionService(self::SESSION_KEY))->encrypt(json_encode([
            'provider' => 'test',
            'request_id' => 'request-1',
        ]))];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookie;
    }

    public function testASignInOwingASecondFactorKeepsNoIdentityOfTheEarlierUser(): void
    {
        $session = $this->sessionOfAnotherUser();

        $this->assertSame('/mfa/verify', $this->signIn($session, true));

        $this->assertFalse($session->has(SessionKeys::USERID));
        $this->assertFalse($session->has(SessionKeys::EMAIL));
        $this->assertFalse($session->has(SessionKeys::USERPWD));
        $this->assertSame(self::USER_ID, $session->get(SessionKeys::PENDING_USERID));
        $this->assertSame('bob@example.com', $session->get(SessionKeys::PENDING_EMAIL));
        $this->assertSame('bob', $session->get(SessionKeys::USERLOGIN));
    }

    public function testACompletedSignInKeepsNoPendingLoginOfTheEarlierUser(): void
    {
        $session = $this->sessionOfAnotherUser();

        $this->assertNull($this->signIn($session, false));

        $this->assertFalse($session->has(SessionKeys::PENDING_USERID));
        $this->assertFalse($session->has(SessionKeys::USERPWD));
        $this->assertSame(self::USER_ID, $session->get(SessionKeys::USERID));
        $this->assertSame('bob@example.com', $session->get(SessionKeys::EMAIL));
        $this->assertSame('bob', $session->get(SessionKeys::USERLOGIN));
        $this->assertTrue($session->get(SessionKeys::SAML_AUTHENTICATED));
    }

    private function sessionOfAnotherUser(): ArraySession
    {
        return new ArraySession([
            SessionKeys::SAML_PROVIDER => 'test',
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::USERPWD => 'encrypted-password-of-alice',
            SessionKeys::EMAIL => 'alice@example.com',
            SessionKeys::PENDING_USERID => 1,
        ]);
    }

    private function signIn(ArraySession $session, bool $mfaEnabled): ?string
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('getErrors')->willReturn([]);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('getAttributes')->willReturn([
            'username' => ['bob'],
            'email' => ['bob@example.com'],
            'displayName' => ['Bob'],
        ]);
        $auth->method('getNameId')->willReturn('bob-name-id');
        $auth->method('getSessionIndex')->willReturn('session-index-1');

        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('provisionUser')->willReturn(self::USER_ID);
        $provisioning->method('isActiveUser')->willReturn(true);
        $provisioning->method('getDatabaseUsername')->willReturn('bob');

        $mfa = $this->createMock(MfaService::class);
        $mfa->method('isMfaEnabled')->willReturn($mfaEnabled);

        $service = new SamlService(
            new FakeConfiguration(['security' => ['mfa' => ['enabled' => true], 'session_key' => self::SESSION_KEY]]),
            $this->createMock(SamlConfigurationService::class),
            $provisioning,
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $mfa,
            $session,
            new Request([], ['SAMLResponse' => 'response'], ['REQUEST_METHOD' => 'POST']),
            $auth
        );

        return $service->handleAssertion();
    }
}
