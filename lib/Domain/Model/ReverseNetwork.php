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

namespace Poweradmin\Domain\Model;

/**
 * A parsed network for batch PTR creation: the address range to walk and the reverse name used to probe for its zone.
 */
final readonly class ReverseNetwork
{
    /**
     * @param string $prefix IPv4 dotted network address, or the four-hextet IPv6 /64 prefix
     * @param int $networkAddress IPv4 network address as a long; 0 for IPv6
     * @param int $hostCount Number of addresses to walk, including the skipped network/broadcast slots
     * @param string $probeReverseName Reverse name of the first address, resolved once to confirm a reverse zone exists
     */
    public function __construct(
        public string $prefix,
        public int $networkAddress,
        public int $hostCount,
        public string $probeReverseName,
    ) {
    }
}
