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

namespace Poweradmin\Infrastructure\Utility;

/**
 * An explicit list of proxy addresses. Unlike IpAddressRetriever's peer check, it
 * trusts nothing implicitly: private and loopback addresses match only when listed.
 */
final class TrustedProxyList
{
    /**
     * @param array<int, mixed> $entries IPs, CIDR ranges or IPv4 wildcards; non-strings are ignored
     */
    public function __construct(private readonly array $entries)
    {
    }

    /**
     * Whether the address matches an entry: an exact IP, a CIDR range (IPv4 or
     * IPv6), or an IPv4 wildcard (e.g. 203.0.113.*).
     */
    public function matches(string $ip): bool
    {
        foreach ($this->entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }

            if ($this->ipsEqual($entry, $ip)) {
                return true;
            }

            if (str_contains($entry, '/')) {
                if ($this->ipMatchesCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }

            if (str_contains($entry, '*')) {
                // A wildcard copied from logs in the ::ffff: form must match the unmapped IPv4 peer
                $entry = (string)preg_replace('/^::ffff:/i', '', $entry);
                $pattern = '/^' . str_replace(['.', '*'], ['\\.', '[0-9]+'], $entry) . '$/';
                if (preg_match($pattern, $ip) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compare two IP addresses by binary value so equivalent IPv6 forms
     * (compressed vs expanded) match regardless of textual representation.
     */
    private function ipsEqual(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $aBin = @inet_pton($a);
        $bBin = @inet_pton($b);
        if ($aBin === false || $bBin === false) {
            return false;
        }

        return self::unmapped($aBin) === self::unmapped($bBin);
    }

    /** The 4-byte form of an IPv4-mapped IPv6 address; other packed addresses unchanged. */
    private static function unmapped(string $binary): string
    {
        return strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff") ? substr($binary, 12) : $binary;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        $prefix = (int) $prefix;
        // A range written in the mapped form applies to the IPv4 address; shorter prefixes never match
        if (strlen($ipBin) === 4 && strlen($netBin) === 16 && self::unmapped($netBin) !== $netBin && $prefix >= 96) {
            $netBin = substr($netBin, 12);
            $prefix -= 96;
        }
        if (strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }
        if ($prefix === 0) {
            return true;
        }

        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
        return (ord($ipBin[$fullBytes]) & ord($mask)) === (ord($netBin[$fullBytes]) & ord($mask));
    }
}
