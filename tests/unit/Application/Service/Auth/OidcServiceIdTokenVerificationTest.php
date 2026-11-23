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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcIdTokenVerifier;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * With verify_id_token on, the sign-in carries a single-use nonce and the claims
 * come from a verified ID token; with it off the flow is as it was.
 */
#[CoversClass(OidcService::class)]
#[CoversClass(OidcConfigurationService::class)]
final class OidcServiceIdTokenVerificationTest extends TestCase
{
    private const PROVIDER = [
        'client_id' => 'client-1',
        'client_secret' => 'secret',
        'authorize_url' => 'https://idp.example/authorize',
        'token_url' => 'https://idp.example/token',
        'userinfo_url' => 'https://idp.example/userinfo',
        'jwks_url' => 'https://idp.example/jwks',
        'issuer' => 'https://idp.example',
    ];

    private static RSA\PrivateKey $key;

    /** @var array<string, mixed> */
    private array $cookie;
    private ArraySession $session;
    private AuthenticationService $auth;
    private ?OidcUserInfo $provisioned = null;

    public static function setUpBeforeClass(): void
    {
        self::$key = RSA::createKey(2048);
    }

    protected function setUp(): void
    {
        $this->cookie = $_COOKIE;
        $_COOKIE = [];
        $this->session = new ArraySession();
        $this->provisioned = null;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookie;
    }

    public function testTheAuthorizationRequestCarriesAFreshNonceWhenVerificationIsOn(): void
    {
        $url = $this->service(['verify_id_token' => true], null)->initiateAuthFlow('p');

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertGreaterThanOrEqual(43, strlen($query['nonce']));
        $this->assertSame($query['nonce'], $this->session->get('oidc_nonce'));
    }

    public function testTheAuthorizationRequestHasNoNonceWhenVerificationIsOff(): void
    {
        $url = $this->service([], null)->initiateAuthFlow('p');

        $this->assertStringNotContainsString('nonce', $url);
        $this->assertFalse($this->session->has('oidc_nonce'));
    }

    public function testAProviderSettingOverridesTheGlobalOne(): void
    {
        $url = $this->service(['verify_id_token' => false], null, ['verify_id_token' => true])->initiateAuthFlow('p');

        $this->assertStringContainsString('nonce=', $url);
    }

    public function testAVerifiedTokenSuppliesTheClaimsAndTheNonceIsSpent(): void
    {
        $this->session->set('oidc_nonce', 'n-1');
        $token = $this->token(['groups' => ['dns-admins'], 'nonce' => 'n-1']);

        $this->service(['verify_id_token' => true], $token)->handleCallback();

        $this->assertSame(['dns-admins'], $this->provisioned?->getGroups());
        $this->assertFalse($this->session->has('oidc_nonce'));
    }

    public function testATokenWithAnotherNonceRefusesTheLogin(): void
    {
        $this->session->set('oidc_nonce', 'n-1');

        $this->service(['verify_id_token' => true], $this->token(['nonce' => 'n-2']))->handleCallback();

        $this->assertNull($this->provisioned);
        $this->assertFalse($this->session->has('oidc_nonce'));
    }

    public function testAMissingIdTokenRefusesTheLoginWhenVerificationIsOn(): void
    {
        $this->session->set('oidc_nonce', 'n-1');

        $this->service(['verify_id_token' => true], new AccessToken(['access_token' => 'a']))->handleCallback();

        $this->assertNull($this->provisioned);
    }

    public function testAForgedPayloadIsNotReadWhenVerificationIsOn(): void
    {
        $this->session->set('oidc_nonce', 'n-1');
        [$header, , $signature] = explode('.', $this->jwt(['nonce' => 'n-1']));
        $forged = $header . '.' . $this->b64((string)json_encode($this->claims(['nonce' => 'n-1', 'groups' => ['root']]))) . '.' . $signature;

        $this->service(['verify_id_token' => true], new AccessToken(['access_token' => 'a', 'id_token' => $forged]))->handleCallback();

        $this->assertNull($this->provisioned);
    }

    public function testAUserinfoSubjectThatDiffersFromTheTokenRefusesTheLogin(): void
    {
        $this->session->set('oidc_nonce', 'n-1');

        $this->service(['verify_id_token' => true], $this->token(['nonce' => 'n-1', 'sub' => 'someone-else']))->handleCallback();

        $this->assertNull($this->provisioned);
    }

    #[DataProvider('jwksUrlProvider')]
    public function testTheJwksUrlMustBeHttpsExceptForLoopback(string $jwksUrl, bool $accepted): void
    {
        $this->session->set('oidc_nonce', 'n-1');
        $token = $this->token(['nonce' => 'n-1']);

        $this->service(['verify_id_token' => true], $token, ['jwks_url' => $jwksUrl])->handleCallback();

        $this->assertSame($accepted, $this->provisioned !== null);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function jwksUrlProvider(): array
    {
        return [
            'https' => ['https://idp.example/jwks', true],
            'plain http' => ['http://idp.example/jwks', false],
            'ftp' => ['ftp://idp.example/jwks', false],
            'no scheme' => ['//idp.example/jwks', false],
            'http localhost' => ['http://localhost:8080/jwks', true],
            'http loopback' => ['http://127.0.0.1/jwks', true],
            'http ipv6 loopback' => ['http://[::1]/jwks', true],
            'http lookalike' => ['http://localhost.evil.example/jwks', false],
        ];
    }

    public function testWithVerificationOffTheUnverifiedPayloadIsStillRead(): void
    {
        $token = new AccessToken(['access_token' => 'a', 'id_token' => 'x.' . $this->b64((string)json_encode(['groups' => ['g']])) . '.y']);

        $this->service([], $token)->handleCallback();

        $this->assertSame(['g'], $this->provisioned?->getGroups());
    }

    public function testVerificationNeedsAnIssuerAndJwksUrlWithoutDiscovery(): void
    {
        $config = new OidcConfigurationService(new FakeConfiguration(['oidc' => ['verify_id_token' => true, 'providers' => ['p' => [
            'client_id' => 'c', 'client_secret' => 's', 'authorize_url' => 'a', 'token_url' => 't', 'userinfo_url' => 'u',
        ]]]]), new NullLogger());

        $this->assertStringContainsString("'jwks_url'", (string)$config->describeProviderConfigError('p'));
    }

    /**
     * @param array<string, mixed> $oidc
     * @param array<string, mixed> $providerOverride
     */
    private function service(array $oidc, ?AccessToken $token, array $providerOverride = []): OidcService
    {
        $configuration = new FakeConfiguration([
            'interface' => ['application_url' => 'https://pa.example'],
            'oidc' => $oidc + ['providers' => ['p' => $providerOverride + self::PROVIDER]],
        ]);
        $configService = new OidcConfigurationService($configuration, new NullLogger());

        $provider = $this->createMock(GenericProvider::class);
        $provider->method('getAuthorizationUrl')->willReturnCallback(
            static fn(array $options): string => 'https://idp.example/authorize?' . http_build_query($options)
        );
        $owner = $this->createMock(ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn(['sub' => 'user-1']);
        $provider->method('getResourceOwner')->willReturn($owner);
        if ($token !== null) {
            $provider->method('getAccessToken')->willReturn($token);
        }

        $provisioning = $this->createMock(UserProvisioningService::class);
        $provisioning->method('provisionUser')->willReturnCallback(function (OidcUserInfo $info): ?int {
            $this->provisioned = $info;
            return null;
        });
        $this->auth = $this->createMock(AuthenticationService::class);

        $verifier = new OidcIdTokenVerifier(
            new NullLogger(),
            static fn(): string => (string)json_encode(['keys' => [self::jwk()]]),
            null,
            static fn(): int => 1_800_000_000
        );

        $this->session->set('oidc_state', 's-1');
        $this->session->set('oidc_provider', 'p');

        return new OidcService(
            $configuration,
            $configService,
            $provisioning,
            new NullLogger(),
            $this->auth,
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            new Request(['state' => 's-1', 'code' => 'code-1'], [], ['REQUEST_METHOD' => 'GET']),
            $provider,
            $verifier
        );
    }

    /**
     * @param array<string, mixed> $override
     */
    private function token(array $override): AccessToken
    {
        return new AccessToken(['access_token' => 'a', 'id_token' => $this->jwt($override)]);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function claims(array $override): array
    {
        return $override + ['iss' => 'https://idp.example', 'aud' => 'client-1', 'sub' => 'user-1', 'iat' => 1_800_000_000, 'exp' => 1_800_000_300];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function jwt(array $override): string
    {
        $input = $this->b64('{"alg":"RS256","kid":"k1"}') . '.' . $this->b64((string)json_encode($this->claims($override)));

        return $input . '.' . $this->b64(self::$key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1)->sign($input));
    }

    /**
     * @return array<string, mixed>
     */
    private static function jwk(): array
    {
        $set = json_decode(self::$key->getPublicKey()->toString('JWK'), true);

        return $set['keys'][0] + ['kid' => 'k1', 'use' => 'sig'];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
