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

namespace Poweradmin\Application\Service\Zone;

/**
 * Web wording for zone limits on the user, group and add-zone pages.
 */
final class ZoneLimitMessages
{
    /**
     * The warning after saving a limit below what the user or group already owns, or null.
     */
    public static function belowUsage(string $name, int $owned, ?int $limit): ?string
    {
        if ($limit === null || $owned <= $limit) {
            return null;
        }

        return sprintf(
            _('%1$s owns %2$d zones, more than the zone limit of %3$d. The existing zones are kept; new ones are refused.'),
            $name,
            $owned,
            $limit
        );
    }

    /**
     * The note on the add-zone pages, or null when the acting user is not limited.
     */
    public static function remaining(?int $remaining): ?string
    {
        if ($remaining === null) {
            return null;
        }

        return $remaining === 0
            ? _('You have reached your zone limit and cannot own more zones.')
            : sprintf(ngettext('You can own %d more zone.', 'You can own %d more zones.', $remaining), $remaining);
    }
}
