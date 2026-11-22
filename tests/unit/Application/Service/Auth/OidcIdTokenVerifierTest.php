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
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use Poweradmin\Application\Service\Auth\OidcIdTokenVerifier;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(OidcIdTokenVerifier::class)]
final class OidcIdTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://idp.example';
    private const CLIENT = 'client-1';
    private const NONCE = 'nonce-1';
    private const JWKS = 'https://idp.example/jwks';
    private const NOW = 1_800_000_000;

    private static RSA\PrivateKey $rsa;
    private static EC\PrivateKey $ec;

    /** @var list<array<string, mixed>> */
    private array $served;
    private int $fetches;
    private int $now;
    private string $cacheDir;

    public static function setUpBeforeClass(): void
    {
        self::$rsa = RSA::createKey(2048);
        self::$ec = EC::createKey('secp256r1');
    }

    protected function setUp(): void
    {
        $this->served = [self::jwk(self::$rsa, 'rsa-1'), self::jwk(self::$ec, 'ec-1')];
        $this->fetches = 0;
        $this->now = self::NOW;
        $this->cacheDir = sys_get_temp_dir() . '/pa-jwks-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testAGoodRsaTokenReturnsItsClaims(): void
    {
        $this->assertSame('user-1', $this->verify($this->rsaToken())['sub']);
    }

    public function testAGoodEcTokenReturnsItsClaims(): void
    {
        $token = $this->sign(['alg' => 'ES256', 'kid' => 'ec-1'], $this->claims(), self::$ec);

        $this->assertSame('user-1', $this->verify($token)['sub']);
    }

    public function testATokenWithoutKidUsesTheOnlyKeyOfTheRightType(): void
    {
        $token = $this->sign(['alg' => 'RS256'], $this->claims(), self::$rsa);

        $this->assertSame('user-1', $this->verify($token)['sub']);
    }

    public function testAnotherKeysSignatureIsRefused(): void
    {
        $other = RSA::createKey(2048);

        $this->assertRefused('signature is invalid', $this->sign(['alg' => 'RS256', 'kid' => 'rsa-1'], $this->claims(), $other));
    }

    public function testATamperedPayloadIsRefused(): void
    {
        [$header, , $signature] = explode('.', $this->rsaToken());
        $forged = $header . '.' . self::b64((string)json_encode($this->claims(['sub' => 'admin']))) . '.' . $signature;

        $this->assertRefused('signature is invalid', $forged);
    }

    public function testAlgNoneIsRefused(): void
    {
        $token = self::b64('{"alg":"none"}') . '.' . self::b64((string)json_encode($this->claims())) . '.' . self::b64('x');

        $this->assertRefused('algorithm is not allowed', $token);
    }

    public function testHs256SignedWithThePublicKeyIsRefused(): void
    {
        $secret = self::$rsa->getPublicKey()->toString('PKCS8');
        $input = self::b64('{"alg":"HS256","kid":"rsa-1"}') . '.' . self::b64((string)json_encode($this->claims()));
        $token = $input . '.' . self::b64(hash_hmac('sha256', $input, $secret, true));

        $this->assertRefused('algorithm is not allowed', $token);
    }

    public function testAnRsaKeyDoesNotVerifyAnEsToken(): void
    {
        $this->served = [self::jwk(self::$rsa, 'ec-1')];

        $this->assertRefused('No usable signing key', $this->sign(['alg' => 'ES256', 'kid' => 'ec-1'], $this->claims(), self::$ec));
    }

    public function testWrongIssuerIsRefused(): void
    {
        $this->assertRefused('issuer', $this->rsaToken(['iss' => 'https://evil.example']));
    }

    public function testWrongAudienceIsRefused(): void
    {
        $this->assertRefused('audience', $this->rsaToken(['aud' => 'other-client']));
    }

    public function testAudienceListWithTheClientAndMatchingAzpIsAccepted(): void
    {
        $token = $this->rsaToken(['aud' => [self::CLIENT, 'other'], 'azp' => self::CLIENT]);

        $this->assertSame('user-1', $this->verify($token)['sub']);
    }

    public function testSeveralAudiencesWithoutAzpAreRefused(): void
    {
        $this->assertRefused('authorized party', $this->rsaToken(['aud' => [self::CLIENT, 'other']]));
    }

    public function testExpiredTokenIsRefusedBeyondTheLeeway(): void
    {
        $this->assertRefused('expired', $this->rsaToken(['exp' => self::NOW - 61]));
        $this->assertSame('user-1', $this->verify($this->rsaToken(['exp' => self::NOW - 59]))['sub']);
    }

    public function testTokenIssuedInTheFutureIsRefusedBeyondTheLeeway(): void
    {
        $this->assertRefused('issued in the future', $this->rsaToken(['iat' => self::NOW + 61]));
        $this->assertSame('user-1', $this->verify($this->rsaToken(['iat' => self::NOW + 59]))['sub']);
    }

    public function testNonceMismatchIsRefused(): void
    {
        $this->assertRefused('nonce does not match', $this->rsaToken(['nonce' => 'replayed']));
    }

    public function testMissingNonceInTheTokenIsRefused(): void
    {
        $claims = $this->claims();
        unset($claims['nonce']);

        $this->assertRefused('nonce does not match', $this->sign(['alg' => 'RS256', 'kid' => 'rsa-1'], $claims, self::$rsa));
    }

    public function testMissingStoredNonceIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No nonce was stored');

        $this->verifier()->verify($this->rsaToken(), self::ISSUER, self::CLIENT, null, self::JWKS);
    }

    public function testAnUnknownKidRefetchesOnceAfterTheRateLimit(): void
    {
        $this->assertSame('user-1', $this->verify($this->rsaToken())['sub']);
        $this->assertSame(1, $this->fetches);

        $rotated = RSA::createKey(2048);
        $this->served = [self::jwk($rotated, 'rsa-2')];
        $this->now += 120;
        $token = $this->sign(['alg' => 'RS256', 'kid' => 'rsa-2'], $this->claims(['exp' => $this->now + 300, 'iat' => $this->now]), $rotated);

        $this->assertSame('user-1', $this->verify($token)['sub']);
        $this->assertSame(2, $this->fetches);
    }

    public function testAnUnknownKidWithinTheRateLimitDoesNotRefetch(): void
    {
        $this->verify($this->rsaToken());
        $this->now += 10;
        $token = $this->sign(['alg' => 'RS256', 'kid' => 'rsa-9'], $this->claims(['exp' => $this->now + 300]), self::$rsa);

        $this->assertRefused('No usable signing key', $token);
        $this->assertSame(1, $this->fetches);
    }

    public function testKeysAreServedFromTheCacheUntilTheTtlExpires(): void
    {
        $this->verify($this->rsaToken());
        $this->now += 3000;
        $this->verify($this->rsaToken(['exp' => $this->now + 300, 'iat' => $this->now]));
        $this->assertSame(1, $this->fetches);

        $this->now += 700;
        $this->verify($this->rsaToken(['exp' => $this->now + 300, 'iat' => $this->now]));
        $this->assertSame(2, $this->fetches);
    }

    public function testCriticalHeaderExtensionsAreRefused(): void
    {
        $token = $this->sign(['alg' => 'RS256', 'kid' => 'rsa-1', 'crit' => ['exp']], $this->claims(), self::$rsa);

        $this->assertRefused('critical header', $token);
    }

    public function testAnRsaKeyShorterThan2048BitsIsRefused(): void
    {
        $small = RSA::createKey(1024);
        $this->served = [self::jwk($small, 'small')];

        $this->assertRefused('signature is invalid', $this->sign(['alg' => 'RS256', 'kid' => 'small'], $this->claims(), $small));
    }

    public function testAP384KeyDoesNotVerifyAnEs256Token(): void
    {
        $p384 = EC::createKey('secp384r1');
        $this->served = [self::jwk($p384, 'p384')];

        $this->assertRefused('signature is invalid', $this->sign(['alg' => 'ES256', 'kid' => 'p384'], $this->claims(), $p384));
    }

    public function testAZpThatIsNotTheClientIsRefusedWithASingleAudience(): void
    {
        $this->assertRefused('authorized party', $this->rsaToken(['azp' => 'other-client']));
    }

    public function testAKeyMarkedForEncryptionIsSkipped(): void
    {
        $this->served = [['use' => 'enc'] + self::jwk(self::$rsa, 'rsa-1')];

        $this->assertRefused('No usable signing key', $this->rsaToken());
    }

    public function testATokenWithoutASubjectIsRefused(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        $this->assertRefused('no subject', $this->sign(['alg' => 'RS256', 'kid' => 'rsa-1'], $claims, self::$rsa));
    }

    public function testACachedFetchTimeInTheFutureDoesNotBlockARefetch(): void
    {
        $this->verify($this->rsaToken());
        $this->now -= 5000;
        $this->verify($this->rsaToken(['exp' => $this->now + 300, 'iat' => $this->now]));
        $this->assertSame(2, $this->fetches);
    }

    public function testAMalformedTokenIsRefused(): void
    {
        $this->assertRefused('JWS compact form', 'a.b');
        $this->assertRefused('JWS compact form', 'a.b.');
    }

    public function testAResponseThatIsNotAKeySetIsRefused(): void
    {
        $verifier = new OidcIdTokenVerifier(new NullLogger(), static fn(): string => '<html>', null, fn(): int => $this->now);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a key set');
        $verifier->verify($this->rsaToken(), self::ISSUER, self::CLIENT, self::NONCE, self::JWKS);
    }

    private function assertRefused(string $reason, string $token): void
    {
        try {
            $this->verify($token);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
            $this->assertStringNotContainsString($token, $e->getMessage());
            return;
        }
        $this->fail('The token was accepted');
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $token): array
    {
        return $this->verifier()->verify($token, self::ISSUER, self::CLIENT, self::NONCE, self::JWKS);
    }

    private function verifier(): OidcIdTokenVerifier
    {
        $fetch = function (string $url): string {
            $this->fetches++;
            return (string)json_encode(['keys' => $this->served]);
        };

        return new OidcIdTokenVerifier(new NullLogger(), $fetch, $this->cacheDir, fn(): int => $this->now);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function rsaToken(array $override = []): string
    {
        return $this->sign(['alg' => 'RS256', 'kid' => 'rsa-1'], $this->claims($override), self::$rsa);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function claims(array $override = []): array
    {
        return $override + [
            'iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'user-1', 'nonce' => self::NONCE,
            'iat' => self::NOW, 'exp' => self::NOW + 300,
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private function sign(array $header, array $claims, RSA\PrivateKey|EC\PrivateKey $key): string
    {
        $input = self::b64((string)json_encode($header)) . '.' . self::b64((string)json_encode($claims));
        $signature = $key instanceof RSA\PrivateKey
            ? $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1)->sign($input)
            : $key->withHash('sha256')->withSignatureFormat('IEEE')->sign($input);

        return $input . '.' . self::b64($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jwk(RSA\PrivateKey|EC\PrivateKey $key, string $kid): array
    {
        $set = json_decode($key->getPublicKey()->toString('JWK'), true);

        return $set['keys'][0] + ['kid' => $kid, 'use' => 'sig'];
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
