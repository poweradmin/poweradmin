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

namespace Poweradmin\Application\Service\Auth;

use Closure;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Checks an OIDC ID token against the provider's JWKS: signature, issuer, audience, expiry and nonce.
 * Every refusal is a RuntimeException whose message names the reason and never carries token contents.
 */
final class OidcIdTokenVerifier
{
    private const LEEWAY = 60;
    private const JWKS_TTL = 3600;
    private const REFETCH_INTERVAL = 60;
    private const MAX_JWKS_BYTES = 1048576;
    private const MIN_RSA_BITS = 2048;

    /** alg => [key type, hash, EC curve] */
    private const ALGORITHMS = [
        'RS256' => ['RSA', 'sha256', null],
        'RS384' => ['RSA', 'sha384', null],
        'RS512' => ['RSA', 'sha512', null],
        'ES256' => ['EC', 'sha256', 'secp256r1'],
        'ES384' => ['EC', 'sha384', 'secp384r1'],
    ];

    /** @var Closure(string): string */
    private Closure $fetch;
    /** @var Closure(): int */
    private Closure $clock;
    private static bool $cacheWarned = false;

    /**
     * @param Closure(string): string $fetch returns the body of a JWKS URL or throws
     * @param string|null $cacheDir where fetched key sets are kept; null keeps them for this request only
     * @param (Closure(): int)|null $clock unix time, for tests
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        Closure $fetch,
        private readonly ?string $cacheDir = null,
        ?Closure $clock = null
    ) {
        $this->fetch = $fetch;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @return array<string, mixed> the verified claims
     * @throws RuntimeException when the token cannot be trusted
     */
    public function verify(string $idToken, string $issuer, string $clientId, ?string $expectedNonce, string $jwksUrl): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new RuntimeException('ID token is not in JWS compact form');
        }

        $header = $this->decodeJson($parts[0], 'header');
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !isset(self::ALGORITHMS[$alg])) {
            throw new RuntimeException('ID token signature algorithm is not allowed');
        }
        if (isset($header['crit'])) {
            throw new RuntimeException('ID token carries critical header extensions');
        }
        $kid = $header['kid'] ?? null;
        if ($kid !== null && !is_string($kid)) {
            throw new RuntimeException('ID token key id is not a string');
        }

        $signature = $this->base64UrlDecode($parts[2]);
        if ($signature === null) {
            throw new RuntimeException('ID token signature is not base64url');
        }

        $this->checkSignature($parts[0] . '.' . $parts[1], $signature, $alg, $kid, $jwksUrl);

        $claims = $this->decodeJson($parts[1], 'payload');
        $this->checkClaims($claims, $issuer, $clientId, $expectedNonce);

        return $claims;
    }

    private function checkSignature(string $signingInput, string $signature, string $alg, ?string $kid, string $jwksUrl): void
    {
        [$keys, $fetchedAt] = $this->loadKeys($jwksUrl, false);
        $jwk = $this->selectKey($keys, $alg, $kid);

        // A key rotation shows up as an unknown kid; refetch once, but not more often than every minute
        if ($jwk === null && $kid !== null && ($this->clock)() - $fetchedAt >= self::REFETCH_INTERVAL) {
            [$keys] = $this->loadKeys($jwksUrl, true);
            $jwk = $this->selectKey($keys, $alg, $kid);
        }
        if ($jwk === null) {
            throw new RuntimeException('No usable signing key in the JWKS for this ID token');
        }

        if (!$this->signatureMatches($jwk, $alg, $signingInput, $signature)) {
            throw new RuntimeException('ID token signature is invalid');
        }
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return array<string, mixed>|null
     */
    private function selectKey(array $keys, string $alg, ?string $kid): ?array
    {
        $kty = self::ALGORITHMS[$alg][0];
        $usable = [];
        foreach ($keys as $key) {
            if (($key['kty'] ?? null) !== $kty) {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if (isset($key['alg']) && $key['alg'] !== $alg) {
                continue;
            }
            if ($kid !== null) {
                if (($key['kid'] ?? null) === $kid) {
                    $usable[] = $key;
                }
                continue;
            }
            $usable[] = $key;
        }

        // Without a kid the key is only unambiguous when the set holds exactly one candidate
        if ($usable === [] || ($kid === null && count($usable) > 1)) {
            return null;
        }

        return $usable[0];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function signatureMatches(array $jwk, string $alg, string $signingInput, string $signature): bool
    {
        [, $hash, $curve] = self::ALGORITHMS[$alg];

        try {
            $key = PublicKeyLoader::load((string)json_encode($jwk));

            if ($key instanceof RSA\PublicKey && $curve === null) {
                if ($key->getLength() < self::MIN_RSA_BITS) {
                    $this->logger->warning('Refusing an RSA signing key shorter than {bits} bits', ['bits' => self::MIN_RSA_BITS]);
                    return false;
                }
                return $key->withHash($hash)->withPadding(RSA::SIGNATURE_PKCS1)->verify($signingInput, $signature);
            }

            // JWS carries ECDSA signatures as raw r||s, which phpseclib calls the IEEE format
            if ($key instanceof EC\PublicKey && $curve !== null && $key->getCurve() === $curve) {
                return $key->withHash($hash)->withSignatureFormat('IEEE')->verify($signingInput, $signature);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not check the ID token signature: {error}', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkClaims(array $claims, string $issuer, string $clientId, ?string $expectedNonce): void
    {
        $now = ($this->clock)();

        if (($claims['iss'] ?? null) !== $issuer) {
            throw new RuntimeException(sprintf('ID token issuer does not match the expected %s', $issuer));
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException('ID token has no subject');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($clientId, $audiences, true)) {
            throw new RuntimeException('ID token audience does not include this client');
        }
        $azp = $claims['azp'] ?? null;
        if (($azp !== null || count($audiences) > 1) && $azp !== $clientId) {
            throw new RuntimeException('ID token authorized party does not match this client');
        }

        $exp = $claims['exp'] ?? null;
        if ((!is_int($exp) && !is_float($exp)) || $exp <= $now - self::LEEWAY) {
            throw new RuntimeException('ID token has expired or has no expiry');
        }
        $iat = $claims['iat'] ?? null;
        if ((!is_int($iat) && !is_float($iat)) || $iat > $now + self::LEEWAY) {
            throw new RuntimeException('ID token was issued in the future or has no issue time');
        }

        $nonce = $claims['nonce'] ?? null;
        if ($expectedNonce === null || $expectedNonce === '') {
            throw new RuntimeException('No nonce was stored for this login');
        }
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new RuntimeException('ID token nonce does not match');
        }
    }

    /**
     * @return array{list<array<string, mixed>>, int} the keys and when they were fetched
     */
    private function loadKeys(string $jwksUrl, bool $forceFresh): array
    {
        $now = ($this->clock)();

        if (!$forceFresh) {
            $cached = $this->readCache($jwksUrl);
            // A fetch time in the future (clock stepped back) counts as stale
            if ($cached !== null && $now - $cached[1] >= 0 && $now - $cached[1] < self::JWKS_TTL) {
                return $cached;
            }
        }

        try {
            $body = ($this->fetch)($jwksUrl);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not fetch the JWKS: ' . $e->getMessage(), 0, $e);
        }

        $keys = $this->parseJwks($body);
        $this->writeCache($jwksUrl, $keys, $now);

        return [$keys, $now];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJwks(string $body): array
    {
        $data = strlen($body) <= self::MAX_JWKS_BYTES ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            throw new RuntimeException('The JWKS response is not a key set');
        }

        return array_values(array_filter($data['keys'], 'is_array'));
    }

    /**
     * @return array{list<array<string, mixed>>, int}|null
     */
    private function readCache(string $jwksUrl): ?array
    {
        $path = $this->cachePath($jwksUrl);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data) || !is_int($data['fetched_at'] ?? null) || !is_array($data['keys'] ?? null)) {
            return null;
        }

        return [array_values(array_filter($data['keys'], 'is_array')), $data['fetched_at']];
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function writeCache(string $jwksUrl, array $keys, int $fetchedAt): void
    {
        $path = $this->cachePath($jwksUrl);
        if ($path === null) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            $this->warnCacheUnwritable($dir);
            return;
        }

        // Write then rename, so a concurrent request never reads half a file
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(['fetched_at' => $fetchedAt, 'keys' => $keys])) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            $this->warnCacheUnwritable($dir);
        }
    }

    private function warnCacheUnwritable(string $dir): void
    {
        if (self::$cacheWarned) {
            return;
        }
        self::$cacheWarned = true;
        $this->logger->warning('The OIDC key cache {dir} is not writable; the signing keys are fetched on every login', ['dir' => $dir]);
    }

    private function cachePath(string $jwksUrl): ?string
    {
        return $this->cacheDir === null ? null : rtrim($this->cacheDir, '/') . '/' . hash('sha256', $jwksUrl) . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment, string $what): array
    {
        $json = $this->base64UrlDecode($segment);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException("ID token {$what} is not a JSON object");
        }

        return $data;
    }

    private function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
