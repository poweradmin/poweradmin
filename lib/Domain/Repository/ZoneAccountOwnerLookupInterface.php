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

namespace Poweradmin\Domain\Repository;

/**
 * Resolves the owner whose username the PowerDNS account field mirrors.
 */
interface ZoneAccountOwnerLookupInterface
{
    /**
     * Username of the oldest direct owner of the zone, or null when the zone has none.
     *
     * @param int $domainId Canonical zone id (the PowerDNS domain id, or the zones row id in API mode)
     * @param int|null $excludeUserId A user to leave out, for a zone about to lose them as an owner
     */
    public function oldestOwnerUsername(int $domainId, ?int $excludeUserId = null): ?string;

    /**
     * Whether the zone has any owner, a direct one or a group it is shared with.
     *
     * @param int $domainId Canonical zone id, as for oldestOwnerUsername()
     */
    public function hasOwner(int $domainId): bool;
}
