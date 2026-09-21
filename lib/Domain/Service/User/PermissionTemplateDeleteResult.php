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

namespace Poweradmin\Domain\Service\User;

/**
 * Outcome of deleting a permission template: removed, or kept because a user,
 * a group, or both still hold it. Callers word the refusal for their audience.
 */
enum PermissionTemplateDeleteResult: string
{
    case DELETED = 'deleted';
    case IN_USE_BY_USERS = 'in_use_by_users';
    case IN_USE_BY_GROUPS = 'in_use_by_groups';
    case IN_USE_BY_BOTH = 'in_use_by_both';

    public static function inUse(bool $byUsers, bool $byGroups): self
    {
        return match (true) {
            $byUsers && $byGroups => self::IN_USE_BY_BOTH,
            $byUsers => self::IN_USE_BY_USERS,
            default => self::IN_USE_BY_GROUPS,
        };
    }

    public function isDeleted(): bool
    {
        return $this === self::DELETED;
    }
}
