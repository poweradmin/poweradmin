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
 * Why ZoneOwnershipGuard refused an owner removal. Callers word the code for
 * their own audience; the mode tells them what kind of replacement to suggest.
 */
final readonly class ZoneOwnershipRefusal
{
    /** The removal would leave the zone with no user owner and no group. */
    public const LAST_OWNER = 'last_owner';

    /** users_only mode: the last user owner cannot go even though groups remain. */
    public const LAST_USER_OWNER_USERS_ONLY = 'last_user_owner_users_only';

    /** groups_only mode: no user owner can go while the zone has no group. */
    public const GROUPS_ONLY_NO_GROUPS = 'groups_only_no_groups';

    /** groups_only mode: the last group cannot go even though user owners remain. */
    public const LAST_GROUP_GROUPS_ONLY = 'last_group_groups_only';

    /** users_only mode: no group can go while the zone has no user owner. */
    public const USERS_ONLY_NO_USER_OWNERS = 'users_only_no_user_owners';

    /**
     * @param string $code One of the class constants
     * @param string $mode The ZoneOwnershipModeService mode the refusal was decided under
     */
    public function __construct(
        public string $code,
        public string $mode
    ) {
    }
}
