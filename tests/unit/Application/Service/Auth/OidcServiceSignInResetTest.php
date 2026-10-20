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

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * An OIDC sign-in starts from an empty session, so the identity of the user the
 * session belonged to before cannot sit beside the new one.
 */
#[CoversClass(OidcService::class)]
final class OidcServiceSignInResetTest extends TestCase
{
    private const USER_ID = 7;

    /** @var array<string, mixed> */
    private array $cookie;

    protected function setUp(): void
    {
        $this->cookie = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
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
        $this->assertTrue($session->get(SessionKeys::OIDC_AUTHENTICATED));
    }

    private function sessionOfAnotherUser(): ArraySession
    {
        return new ArraySession([
            'oidc_state' => 'state-1',
            SessionKeys::OIDC_PROVIDER => 'test',
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::USERPWD => 'encrypted-password-of-alice',
            SessionKeys::EMAIL => 'alice@example.com',
            SessionKeys::PENDING_USERID => 1,
        ]);
    }

    private function signIn(ArraySession $session, bool $mfaEnabled): ?string
    {
        $owner = $this->createMock(ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn([
            'sub' => 'bob-subject',
            'preferred_username' => 'bob',
            'email' => 'bob@example.com',
            'name' => 'Bob',
        ]);
        $provider = $this->createMock(GenericProvider::class);
        $provider->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'access-token']));
        $provider->method('getResourceOwner')->willReturn($owner);

        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('provisionUser')->willReturn(self::USER_ID);
        $provisioning->method('isActiveUser')->willReturn(true);
        $provisioning->method('getDatabaseUsername')->willReturn('bob');

        $mfa = $this->createMock(MfaService::class);
        $mfa->method('isMfaEnabled')->willReturn($mfaEnabled);

        $service = new OidcService(
            new FakeConfiguration(['security' => ['mfa' => ['enabled' => true]]]),
            $this->createMock(OidcConfigurationService::class),
            $provisioning,
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $mfa,
            $session,
            new Request(['state' => 'state-1', 'code' => 'code-1'], [], ['REQUEST_METHOD' => 'GET']),
            $provider
        );

        return $service->handleCallback();
    }
}
