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

/**
 * Validates the algorithm and key size of a new DNSSEC key.
 *
 * The web UI and the REST API share the per-algorithm size rules; the UI
 * additionally limits sizes to the ones its form offers.
 */
class DnssecKeySpecValidator
{
    /**
     * Key sizes (in bits) offered for new keys.
     */
    public const VALID_BITS = ['2048', '1024', '384', '256'];

    private const RSA_ALGORITHMS = ['rsasha1', 'rsasha1-nsec3-sha1', 'rsasha256', 'rsasha512'];

    public static function isValidBits(string $bits): bool
    {
        return in_array($bits, self::VALID_BITS, true);
    }

    /**
     * Check that the key size fits the algorithm.
     *
     * @return string|null A translated error message, or null when the combination is valid
     */
    public static function validateAlgorithmBits(string $algorithm, string $bits): ?string
    {
        $problem = self::checkAlgorithmBits($algorithm, $bits);

        return match ($problem) {
            null => null,
            'ecdsa256' => _('ECDSA P-256 algorithm must use 256 bits'),
            'ecdsa384' => _('ECDSA P-384 algorithm must use 384 bits'),
            'ed25519' => _('ED25519 algorithm must use 256 bits'),
            'ed448' => _('ED448 algorithm must use 456 bits (unsupported in this UI)'),
            default => _('RSA algorithms should use 1024 or 2048 bits for adequate security'),
        };
    }

    /**
     * The same check with fixed English wording, for the API where messages are part of the contract.
     */
    public static function apiErrorForAlgorithmBits(string $algorithm, string $bits): ?string
    {
        $problem = self::checkAlgorithmBits($algorithm, $bits);

        return match ($problem) {
            null => null,
            'ecdsa256' => 'ecdsa256 requires 256 bits',
            'ecdsa384' => 'ecdsa384 requires 384 bits',
            'ed25519' => 'ed25519 requires 256 bits',
            'ed448' => 'ed448 requires 456 bits',
            default => $algorithm . ' requires 1024 or 2048 bits',
        };
    }

    /**
     * @return string|null The algorithm group whose size rule is broken, or null when the combination is valid
     */
    private static function checkAlgorithmBits(string $algorithm, string $bits): ?string
    {
        // ECDSA and EdDSA algorithms have a fixed key size
        $fixed = ['ecdsa256' => '256', 'ecdsa384' => '384', 'ed25519' => '256', 'ed448' => '456'];
        if (isset($fixed[$algorithm])) {
            return $bits === $fixed[$algorithm] ? null : $algorithm;
        }

        if (in_array($algorithm, self::RSA_ALGORITHMS, true) && !in_array($bits, ['1024', '2048'], true)) {
            return 'rsa';
        }

        return null;
    }
}
