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
 * Shared by the web UI and the REST API so both accept exactly the same
 * combinations.
 */
class DnssecKeySpecValidator
{
    /**
     * Key sizes (in bits) offered for new keys.
     */
    public const VALID_BITS = ['2048', '1024', '768', '384', '256'];

    private const RSA_ALGORITHMS = ['rsasha1', 'rsasha1-nsec3-sha1', 'rsasha256', 'rsasha512'];

    public static function isValidBits(string $bits): bool
    {
        return in_array($bits, self::VALID_BITS, true);
    }

    /**
     * Check that the key size fits the algorithm.
     *
     * @return string|null An error message, or null when the combination is valid
     */
    public static function validateAlgorithmBits(string $algorithm, string $bits): ?string
    {
        // ECDSA algorithms have a fixed curve size
        if ($algorithm === 'ecdsa256' && $bits !== '256') {
            return _('ECDSA P-256 algorithm must use 256 bits');
        }
        if ($algorithm === 'ecdsa384' && $bits !== '384') {
            return _('ECDSA P-384 algorithm must use 384 bits');
        }

        // EdDSA algorithms have fixed bit sizes
        if ($algorithm === 'ed25519' && $bits !== '256') {
            return _('ED25519 algorithm must use 256 bits');
        }
        if ($algorithm === 'ed448' && $bits !== '456') {
            return _('ED448 algorithm must use 456 bits (unsupported in this UI)');
        }

        // RSA algorithms should use appropriate bit lengths
        if (in_array($algorithm, self::RSA_ALGORITHMS, true) && !in_array($bits, ['1024', '2048'], true)) {
            return _('RSA algorithms should use 1024 or 2048 bits for adequate security');
        }

        return null;
    }
}
