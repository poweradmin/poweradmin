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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipRefusal;

/**
 * Words a ZoneOwnershipGuard refusal for the zone ownership page.
 */
final class ZoneOwnershipMessages
{
    public static function userOwnerRefusal(ZoneOwnershipRefusal $refusal): string
    {
        return match ($refusal->code) {
            ZoneOwnershipRefusal::LAST_USER_OWNER_USERS_ONLY => _('Cannot remove the last user owner: zone ownership mode is users_only and requires at least one user owner. Add another user owner first.'),
            ZoneOwnershipRefusal::GROUPS_ONLY_NO_GROUPS => _('Cannot remove user owner: zone ownership mode is groups_only and the zone has no group owners. Add a group first.'),
            default => self::lastOwner($refusal->mode),
        };
    }

    public static function groupRefusal(ZoneOwnershipRefusal $refusal): string
    {
        return match ($refusal->code) {
            ZoneOwnershipRefusal::LAST_GROUP_GROUPS_ONLY => _('Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.'),
            ZoneOwnershipRefusal::USERS_ONLY_NO_USER_OWNERS => _('Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.'),
            default => self::lastOwner($refusal->mode),
        };
    }

    private static function lastOwner(string $mode): string
    {
        $hint = match ($mode) {
            ZoneOwnershipModeService::MODE_USERS_ONLY => _('Add another user owner first (zone ownership mode is users_only).'),
            ZoneOwnershipModeService::MODE_GROUPS_ONLY => _('Add a group first (zone ownership mode is groups_only).'),
            default => _('Add another owner or a group first.'),
        };

        return _('Cannot remove the last owner: this would leave the zone with no ownership.') . ' ' . $hint;
    }
}
