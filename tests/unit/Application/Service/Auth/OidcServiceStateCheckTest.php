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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * The callback goes on to the token exchange only when its state is the one this
 * session started the sign-in with.
 */
#[CoversClass(OidcService::class)]
final class OidcServiceStateCheckTest extends TestCase
{
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

    #[DataProvider('refusedStateProvider')]
    public function testACallbackWithoutTheSessionStateIsRefusedBeforeTheTokenExchange(mixed $receivedState, ?string $sessionState): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->never())->method('getAccessToken');

        $this->assertNull($this->serviceFor($receivedState, $sessionState, $provider)->handleCallback());
    }

    public function testACallbackWithTheSessionStateGoesOnToTheTokenExchange(): void
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())->method('getAccessToken')->willThrowException(new \RuntimeException('stop after the state check'));

        $this->assertNull($this->serviceFor('state-1', 'state-1', $provider)->handleCallback());
    }

    private function serviceFor(mixed $receivedState, ?string $sessionState, GenericProvider $provider): OidcService
    {
        return new OidcService(
            new FakeConfiguration(),
            $this->createMock(OidcConfigurationService::class),
            $this->createMock(UserProvisioningService::class),
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            new ArraySession(array_filter(['oidc_state' => $sessionState, SessionKeys::OIDC_PROVIDER => 'test'], fn($value) => $value !== null)),
            new Request(['state' => $receivedState, 'code' => 'code-1'], [], ['REQUEST_METHOD' => 'GET']),
            $provider
        );
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function refusedStateProvider(): array
    {
        return [
            'different state' => ['state-2', 'state-1'],
            'empty state' => ['', 'state-1'],
            'state sent as an array' => [['state-1'], 'state-1'],
            'no state in the session' => ['state-1', null],
        ];
    }
}
