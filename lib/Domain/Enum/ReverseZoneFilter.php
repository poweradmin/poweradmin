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

namespace Poweradmin\Domain\Enum;

/**
 * Which address family the reverse zone list is filtered to.
 *
 * An unrecognised value reaches the zone queries as an empty `AND ()` clause,
 * so request and session input must be normalised through here.
 */
enum ReverseZoneFilter: string
{
    case ALL = 'all';
    case IPV4 = 'ipv4';
    case IPV6 = 'ipv6';

    /**
     * Normalise arbitrary input, falling back to ALL.
     *
     * Takes mixed rather than ?string because `?reverse_type[]=` hands an array
     * straight from the query string.
     */
    public static function fromRequest(mixed $value, self $default = self::ALL): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? $default) : $default;
    }

    /**
     * Whether the filter includes IPv4 (`.in-addr.arpa`) zones.
     */
    public function includesIpv4(): bool
    {
        return $this === self::ALL || $this === self::IPV4;
    }

    /**
     * Whether the filter includes IPv6 (`.ip6.arpa`) zones.
     */
    public function includesIpv6(): bool
    {
        return $this === self::ALL || $this === self::IPV6;
    }
}
