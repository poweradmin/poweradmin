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

namespace Poweradmin\Domain\Service\Zone;

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

    /** RFC 8410 algorithm OIDs (DER content bytes) and their seed lengths */
    private const EDDSA_OIDS = [
        "\x2b\x65\x70" => [DnssecAlgorithm::ED25519, 32],
        "\x2b\x65\x71" => [DnssecAlgorithm::ED448, 57],
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
    public static function toIsc(#[\SensitiveParameter] string $privateKey, string $algorithmName): string|DnssecKeyOutcome
    {
        if (strlen($privateKey) > self::MAX_INPUT_BYTES) {
            return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
        }
        $algorithm = self::algorithmNumber($algorithmName);
        if ($algorithm === null) {
            return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
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

        return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
    }

    private static function algorithmNumber(string $algorithmName): ?int
    {
        $name = DnssecAlgorithmName::ALGORITHM_NAMES[$algorithmName] ?? null;
        $number = $name === null ? false : array_search($name, DnssecAlgorithm::ALGORITHMS, true);

        return is_int($number) ? $number : null;
    }

    private static function checkIsc(#[\SensitiveParameter] string $privateKey, int $algorithm): string|DnssecKeyOutcome
    {
        // PowerDNS reads format v1.x only
        if (preg_match('/^Private-key-format:\s*v1\.\d+\s*$/mi', $privateKey) !== 1) {
            return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
        }
        if (preg_match('/^Algorithm:\s*(\d+)/mi', $privateKey, $m) !== 1) {
            return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
        }
        if ((int)$m[1] !== $algorithm) {
            return DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH;
        }

        return $privateKey . "\n";
    }

    private static function fromPem(#[\SensitiveParameter] string $privateKey, int $algorithm): string|DnssecKeyOutcome
    {
        $eddsa = self::eddsaSeed($privateKey);
        if ($eddsa !== null) {
            [$keyAlgorithm, $seed] = $eddsa;
            return $keyAlgorithm === $algorithm
                ? self::isc($algorithm, ['PrivateKey' => $seed])
                : DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH;
        }

        $resource = @openssl_pkey_get_private($privateKey);
        while (openssl_error_string() !== false) {
            // drain the queue so a failed parse does not leak into later openssl calls
        }
        $details = $resource === false ? false : openssl_pkey_get_details($resource);
        if ($details === false) {
            return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
        }

        if (isset($details['rsa']['n'], $details['rsa']['d'])) {
            if (!in_array($algorithm, self::RSA_ALGORITHMS, true)) {
                return DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH;
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
                return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
            }
            if ($curve[0] !== $algorithm) {
                return DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH;
            }
            // PowerDNS stores the scalar left-padded to the curve size
            return self::isc($algorithm, ['PrivateKey' => str_pad($details['ec']['d'], $curve[1], "\0", STR_PAD_LEFT)]);
        }

        return DnssecKeyOutcome::INVALID_PRIVATE_KEY;
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

        // RFC 5958 OneAsymmetricKey: version, AlgorithmIdentifier, OCTET STRING { OCTET STRING seed },
        // [0] attributes OPTIONAL, [1] publicKey OPTIONAL; v1 (INTEGER 0) has no publicKey, v2 (INTEGER 1) has one
        $outer = self::derElement($der, 0, 0x30);
        if ($outer === null || $outer[1] !== strlen($der)) {
            return null;
        }
        $body = $outer[0];
        $version = self::derElement($body, 0, 0x02);
        if ($version === null || ($version[0] !== "\x00" && $version[0] !== "\x01")) {
            return null;
        }
        $algorithmIdentifier = self::derElement($body, $version[1], 0x30);
        $oid = $algorithmIdentifier === null ? null : self::derElement($algorithmIdentifier[0], 0, 0x06);
        // RFC 8410 section 3: no parameters, so the OID is the whole AlgorithmIdentifier
        if ($oid === null || $oid[1] !== strlen($algorithmIdentifier[0]) || !isset(self::EDDSA_OIDS[$oid[0]])) {
            return null;
        }
        [$keyAlgorithm, $length] = self::EDDSA_OIDS[$oid[0]];

        $wrapped = self::derElement($body, $algorithmIdentifier[1], 0x04);
        $seed = $wrapped === null ? null : self::derElement($wrapped[0], 0, 0x04);
        if ($seed === null || $seed[1] !== strlen($wrapped[0]) || strlen($seed[0]) !== $length) {
            return null;
        }

        $offset = $wrapped[1];
        $attributes = self::derElement($body, $offset, 0xa0);
        if ($attributes !== null) {
            $offset = $attributes[1];
        }
        $publicKey = self::derElement($body, $offset, 0x81);
        if ($publicKey !== null) {
            $offset = $publicKey[1];
        }
        // Nothing may follow, and only a v2 key carries the public key
        if ($offset !== strlen($body) || ($publicKey !== null) !== ($version[0] === "\x01")) {
            return null;
        }

        return [$keyAlgorithm, $seed[0]];
    }

    /**
     * Read the DER element with the expected tag at $offset.
     *
     * @return array{0: string, 1: int}|null [content, offset just after the element]
     */
    private static function derElement(#[\SensitiveParameter] string $der, int $offset, int $tag): ?array
    {
        if (!isset($der[$offset + 1]) || ord($der[$offset]) !== $tag) {
            return null;
        }
        $length = ord($der[$offset + 1]);
        $position = $offset + 2;
        if ($length > 0x7f) {
            // Long form; two length bytes are far more than any key needs
            $lengthBytes = $length & 0x7f;
            if ($lengthBytes < 1 || $lengthBytes > 2 || !isset($der[$position + $lengthBytes - 1])) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $lengthBytes; $i++) {
                $length = ($length << 8) | ord($der[$position + $i]);
            }
            $position += $lengthBytes;
            // DER uses the shortest form: below 128 the short form, no leading zero length byte
            if ($length < 0x80 || ($lengthBytes === 2 && $length < 0x100)) {
                return null;
            }
        }
        if ($position + $length > strlen($der)) {
            return null;
        }

        return [substr($der, $position, $length), $position + $length];
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
