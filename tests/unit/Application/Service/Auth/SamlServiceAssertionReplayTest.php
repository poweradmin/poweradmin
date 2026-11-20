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
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Domain\Repository\SamlAssertionStoreInterface;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * A SAML response signs in once: the assertion ID is recorded and a second response with the
 * same ID is refused.
 */
#[CoversClass(SamlService::class)]
final class SamlServiceAssertionReplayTest extends TestCase
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

    public function testTheAssertionIdAndItsExpiryAreRecordedBeforeSignIn(): void
    {
        $notOnOrAfter = time() + 120;
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->with('_assertion-1', $this->callback(fn(int $expires): bool => $expires > $notOnOrAfter && $expires <= $notOnOrAfter + 600))
            ->willReturn(true);

        $this->handle($this->auth('_assertion-1', $notOnOrAfter), $store);

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testAnAssertionWithoutNotOnOrAfterIsRememberedForAFallbackPeriod(): void
    {
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->with('_assertion-1', $this->callback(fn(int $expires): bool => $expires > time() + 500 && $expires < time() + 1500))
            ->willReturn(true);

        $this->handle($this->auth('_assertion-1', null), $store);

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testTheConditionsExpiryIsUsedWhenTheSubjectConfirmationHasNone(): void
    {
        $conditionsEnd = time() + 3600;
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">'
            . '<saml:Assertion ID="_assertion-1"><saml:Conditions NotOnOrAfter="' . gmdate('Y-m-d\\TH:i:s\\Z', $conditionsEnd) . '"/></saml:Assertion></samlp:Response>';
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->with('_assertion-1', $this->callback(fn(int $expires): bool => $expires >= $conditionsEnd + 299 && $expires <= $conditionsEnd + 301))
            ->willReturn(true);

        $this->handle($this->auth('_assertion-1', null, $xml), $store);

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
    }

    public function testAnAssertionThatWasAlreadyUsedIsRefused(): void
    {
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->method('consume')->willReturn(false);

        $this->handle($this->auth('_assertion-1', time() + 120), $store);

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAnAssertionWithoutAnIdIsRefused(): void
    {
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->expects($this->never())->method('consume');

        $this->handle($this->auth(null, time() + 120), $store);

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    public function testAStoreFailureRefusesTheSignIn(): void
    {
        $store = $this->createMock(SamlAssertionStoreInterface::class);
        $store->method('consume')->willThrowException(new \PDOException('no such table'));

        $this->handle($this->auth('_assertion-1', time() + 120), $store);

        $this->assertFalse($this->session->has(SessionKeys::USERID));
    }

    private function auth(?string $assertionId, ?int $notOnOrAfter, ?string $responseXml = null): Auth&MockObject
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('getErrors')->willReturn([]);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('getLastAssertionId')->willReturn($assertionId);
        $auth->method('getLastAssertionNotOnOrAfter')->willReturn($notOnOrAfter);
        $auth->method('getLastResponseXML')->willReturn($responseXml);
        $auth->method('getAttributes')->willReturn([
            'username' => ['bob'],
            'email' => ['bob@example.com'],
            'displayName' => ['Bob'],
        ]);
        $auth->method('getNameId')->willReturn('bob-name-id');
        $auth->method('getSessionIndex')->willReturn('session-index-1');

        return $auth;
    }

    private function handle(Auth $auth, SamlAssertionStoreInterface $store): void
    {
        $providers = ['corp' => ['allow_idp_initiated' => true]];
        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->method('getProviderConfig')->willReturnCallback(fn(string $id) => $providers[$id] ?? null);

        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('provisionUser')->willReturn(7);
        $provisioning->method('isActiveUser')->willReturn(true);
        $provisioning->method('getDatabaseUsername')->willReturn('bob');

        $service = new SamlService(
            new FakeConfiguration(['security' => ['session_key' => self::SESSION_KEY], 'saml' => ['providers' => ['corp' => $providers['corp'] + ['entity_id' => 'https://idp.example.com', 'sso_url' => 'https://idp.example.com/sso']]]]),
            $samlConfig,
            $provisioning,
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            new Request([], ['SAMLResponse' => 'response', 'RelayState' => SamlService::relayStateFor('corp')], ['REQUEST_METHOD' => 'POST']),
            $auth,
            $store
        );

        $service->handleAssertion();
    }
}
