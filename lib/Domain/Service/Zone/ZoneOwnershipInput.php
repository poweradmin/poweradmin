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

/**
 * The owner choice a zone creation request made, as ZoneCreateOwnershipResolver
 * reads it: the user owner may be omitted (the caller becomes owner), given as
 * null (no user owner) or given as an id; the groups may be omitted or listed.
 */
final readonly class ZoneOwnershipInput
{
    /**
     * @param bool $ownerSupplied Whether the request named a user owner at all
     * @param int|null $ownerUserId The user owner named; null when omitted or explicitly none
     * @param list<int>|null $groupIds The group owners named; null when omitted
     */
    public function __construct(
        public bool $ownerSupplied = false,
        public ?int $ownerUserId = null,
        public ?array $groupIds = null,
    ) {
    }

    /**
     * @param list<int>|null $groupIds
     */
    public static function ownerOmitted(?array $groupIds = null): self
    {
        return new self(false, null, $groupIds);
    }

    /**
     * @param list<int>|null $groupIds
     */
    public static function owner(?int $ownerUserId, ?array $groupIds = null): self
    {
        return new self(true, $ownerUserId, $groupIds);
    }
}
