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
use PHPUnit\Framework\MockObject\MockObject;
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
 * A SAML response is accepted only as the answer to a sign-in this browser started,
 * unless the provider allows IdP-initiated sign-in.
 */
#[CoversClass(SamlService::class)]
final class SamlServiceRequestBindingTest extends TestCase
{
    private const SESSION_KEY = 'test-session-key';

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $cookie;

    private ArraySession $session;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_COOKIE = [];
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookie;
    }

    public function testTheResponseMustAnswerTheRequestInTheFlowCookie(): void
    {
        $this->setFlowCookie('corp', 'request-123');
        $auth = $this->authenticatedAuth();
        $auth->expects($this->once())->method('processResponse')->with('request-123');

        $this->handle($auth, ['corp' => []]);

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testAResponseWithoutAFlowCookieIsRefused(): void
    {
        $auth = $this->authenticatedAuth();
        $auth->expects($this->never())->method('processResponse');

        $this->handle($auth, ['corp' => []], 'corp');

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAFlowCookieThatDoesNotDecryptIsRefused(): void
    {
        $_COOKIE['__Host-saml_flow'] = 'not-an-encrypted-payload';
        $auth = $this->authenticatedAuth();
        $auth->expects($this->never())->method('processResponse');

        $this->handle($auth, ['corp' => []], 'corp');

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAFlowCookieWithoutARequestIdIsRefused(): void
    {
        $_COOKIE['__Host-saml_flow'] = (new PasswordEncryptionService(self::SESSION_KEY))->encrypt(json_encode(['provider' => 'corp']));
        $auth = $this->authenticatedAuth();
        $auth->expects($this->never())->method('processResponse');

        $this->handle($auth, ['corp' => []], 'corp');

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAProviderAllowingIdpInitiatedSignInAcceptsAnUnboundResponse(): void
    {
        $auth = $this->authenticatedAuth();
        $auth->expects($this->once())->method('processResponse')->with(null);

        $this->handle($auth, ['corp' => ['allow_idp_initiated' => true]], 'corp');

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testTheFlowCookieProviderWinsOverTheRelayState(): void
    {
        $this->setFlowCookie('corp', 'request-123');
        $auth = $this->authenticatedAuth();
        $auth->expects($this->once())->method('processResponse')->with('request-123');

        $this->handle($auth, ['corp' => [], 'other' => ['allow_idp_initiated' => true]], 'other');

        $this->assertSame('corp', $this->session->get(SessionKeys::SAML_PROVIDER));
    }

    private function setFlowCookie(string $provider, string $requestId): void
    {
        $_COOKIE['__Host-saml_flow'] = (new PasswordEncryptionService(self::SESSION_KEY))->encrypt(json_encode([
            'provider' => $provider,
            'request_id' => $requestId,
        ]));
    }

    private function authenticatedAuth(): Auth&MockObject
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

        return $auth;
    }

    /**
     * @param array<string, array<string, mixed>> $providers provider configs by id
     */
    private function handle(Auth $auth, array $providers, ?string $relayStateProvider = null): void
    {
        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->method('getProviderConfig')->willReturnCallback(fn(string $id) => $providers[$id] ?? null);

        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('provisionUser')->willReturn(7);
        $provisioning->method('isActiveUser')->willReturn(true);
        $provisioning->method('getDatabaseUsername')->willReturn('bob');

        $post = ['SAMLResponse' => 'response'];
        if ($relayStateProvider !== null) {
            $post['RelayState'] = SamlService::relayStateFor($relayStateProvider);
        }

        $service = new SamlService(
            new FakeConfiguration(['security' => ['session_key' => self::SESSION_KEY]]),
            $samlConfig,
            $provisioning,
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            new Request([], $post, ['REQUEST_METHOD' => 'POST']),
            $auth
        );

        $service->handleAssertion();
    }
}
