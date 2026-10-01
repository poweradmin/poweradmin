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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\DnssecAlgorithmName;

/**
 * Turns a DNSSEC private key into the ISC (BIND "Private-key-format") text the
 * PowerDNS API takes. The API reads no PEM; only pdnsutil import-zone-key-pem does.
 */
final class DnssecPrivateKeyConverter
{
    /** Far above an RSA-4096 key; refused before any parsing */
    public const MAX_INPUT_BYTES = 16384;

    /** PKCS#8 v1 prefixes written by `openssl genpkey`, each followed by the raw seed */
    private const EDDSA_PKCS8 = [
        DnssecAlgorithm::ED25519 => ["\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20", 32],
        DnssecAlgorithm::ED448 => ["\x30\x47\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x71\x04\x3b\x04\x39", 57],
    ];

    private const ECDSA_CURVES = [
        'prime256v1' => [DnssecAlgorithm::ECDSAP256SHA256, 32],
        'secp384r1' => [DnssecAlgorithm::ECDSAP384SHA384, 48],
    ];

    private const RSA_ALGORITHMS = [
        DnssecAlgorithm::RSASHA1,
        DnssecAlgorithm::RSASHA1_NSEC3_SHA1,
        DnssecAlgorithm::RSASHA256,
        DnssecAlgorithm::RSASHA512,
    ];

    /**
     * Returns the key as ISC text for the given algorithm (a DnssecAlgorithmName
     * value), or why it cannot be used. Accepts ISC text or an unencrypted RSA,
     * ECDSA, Ed25519 or Ed448 PEM key; nothing derived from the key is returned
     * on refusal.
     */
    public static function toIsc(#[\SensitiveParameter] string $privateKey, string $algorithmName): string|DnssecPrivateKeyRefusal
    {
        if (strlen($privateKey) > self::MAX_INPUT_BYTES) {
            return DnssecPrivateKeyRefusal::UNREADABLE;
        }
        $algorithm = self::algorithmNumber($algorithmName);
        if ($algorithm === null) {
            return DnssecPrivateKeyRefusal::UNREADABLE;
        }

        // Textarea pastes come indented or with CRLF; neither format cares about line whitespace
        $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "\n", $privateKey))), fn(string $l): bool => $l !== '');
        $key = implode("\n", $lines);

        if (preg_match('/^Private-key-format:/mi', $key) === 1) {
            return self::checkIsc($key, $algorithm);
        }
        if (str_contains($key, '-----BEGIN')) {
            return self::fromPem($key, $algorithm);
        }

        return DnssecPrivateKeyRefusal::UNREADABLE;
    }

    private static function algorithmNumber(string $algorithmName): ?int
    {
        $name = DnssecAlgorithmName::ALGORITHM_NAMES[$algorithmName] ?? null;
        $number = $name === null ? false : array_search($name, DnssecAlgorithm::ALGORITHMS, true);

        return is_int($number) ? $number : null;
    }

    private static function checkIsc(#[\SensitiveParameter] string $privateKey, int $algorithm): string|DnssecPrivateKeyRefusal
    {
        // PowerDNS reads format v1.x only
        if (preg_match('/^Private-key-format:\s*v1\.\d+\s*$/mi', $privateKey) !== 1) {
            return DnssecPrivateKeyRefusal::UNREADABLE;
        }
        if (preg_match('/^Algorithm:\s*(\d+)/mi', $privateKey, $m) !== 1) {
            return DnssecPrivateKeyRefusal::UNREADABLE;
        }
        if ((int)$m[1] !== $algorithm) {
            return DnssecPrivateKeyRefusal::ALGORITHM_MISMATCH;
        }

        return $privateKey . "\n";
    }

    private static function fromPem(#[\SensitiveParameter] string $privateKey, int $algorithm): string|DnssecPrivateKeyRefusal
    {
        $eddsa = self::eddsaSeed($privateKey);
        if ($eddsa !== null) {
            [$keyAlgorithm, $seed] = $eddsa;
            return $keyAlgorithm === $algorithm
                ? self::isc($algorithm, ['PrivateKey' => $seed])
                : DnssecPrivateKeyRefusal::ALGORITHM_MISMATCH;
        }

        $resource = @openssl_pkey_get_private($privateKey);
        while (openssl_error_string() !== false) {
            // drain the queue so a failed parse does not leak into later openssl calls
        }
        $details = $resource === false ? false : openssl_pkey_get_details($resource);
        if ($details === false) {
            return DnssecPrivateKeyRefusal::UNREADABLE;
        }

        if (isset($details['rsa']['n'], $details['rsa']['d'])) {
            if (!in_array($algorithm, self::RSA_ALGORITHMS, true)) {
                return DnssecPrivateKeyRefusal::ALGORITHM_MISMATCH;
            }
            $rsa = $details['rsa'];
            return self::isc($algorithm, [
                'Modulus' => $rsa['n'],
                'PublicExponent' => $rsa['e'],
                'PrivateExponent' => $rsa['d'],
                'Prime1' => $rsa['p'],
                'Prime2' => $rsa['q'],
                'Exponent1' => $rsa['dmp1'],
                'Exponent2' => $rsa['dmq1'],
                'Coefficient' => $rsa['iqmp'],
            ]);
        }

        if (isset($details['ec']['curve_name'], $details['ec']['d'])) {
            $curve = self::ECDSA_CURVES[$details['ec']['curve_name']] ?? null;
            if ($curve === null) {
                return DnssecPrivateKeyRefusal::UNREADABLE;
            }
            if ($curve[0] !== $algorithm) {
                return DnssecPrivateKeyRefusal::ALGORITHM_MISMATCH;
            }
            // PowerDNS stores the scalar left-padded to the curve size
            return self::isc($algorithm, ['PrivateKey' => str_pad($details['ec']['d'], $curve[1], "\0", STR_PAD_LEFT)]);
        }

        return DnssecPrivateKeyRefusal::UNREADABLE;
    }

    /**
     * PHP's openssl extension exposes no EdDSA key details before 8.4, so the
     * seed is read from the PKCS#8 structure directly.
     *
     * TODO: once PHP 8.4 is the minimum, read the seed from openssl_pkey_get_details()
     * (OPENSSL_KEYTYPE_ED25519 / OPENSSL_KEYTYPE_ED448, 'ed25519'/'ed448' => 'priv_key') and drop this parser.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function eddsaSeed(#[\SensitiveParameter] string $privateKey): ?array
    {
        if (preg_match('/-----BEGIN PRIVATE KEY-----(.+?)-----END PRIVATE KEY-----/s', $privateKey, $m) !== 1) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === false) {
            return null;
        }
        foreach (self::EDDSA_PKCS8 as $keyAlgorithm => [$prefix, $length]) {
            if (strlen($der) === strlen($prefix) + $length && str_starts_with($der, $prefix)) {
                return [$keyAlgorithm, substr($der, strlen($prefix))];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $fields binary values, base64-encoded here
     */
    private static function isc(int $algorithm, #[\SensitiveParameter] array $fields): string
    {
        $lines = ['Private-key-format: v1.2', sprintf('Algorithm: %d (%s)', $algorithm, DnssecAlgorithm::ALGORITHMS[$algorithm])];
        foreach ($fields as $name => $value) {
            $lines[] = $name . ': ' . base64_encode($value);
        }

        return implode("\n", $lines) . "\n";
    }
}
