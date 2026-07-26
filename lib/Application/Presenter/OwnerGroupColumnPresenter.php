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

namespace Poweradmin\Application\Presenter;

/**
 * Prepares the zone-list owner and group cells: the first entries stay visible,
 * the rest collapse into a "+N more" tooltip label.
 */
final class OwnerGroupColumnPresenter
{
    private const VISIBLE_LIMIT = 2;

    /**
     * @param array $owners usernames keyed as in the repository result
     * @param array $fullNames full names sharing the owners' keys
     * @return array{visible: list<array{name: string, full_name: string}>, remaining_count: int, remaining_label: string}
     */
    public static function presentOwners(array $owners, array $fullNames): array
    {
        $visible = [];
        $remaining = [];
        $index = 0;
        foreach ($owners as $key => $owner) {
            $fullName = $fullNames[$key] ?? '';
            if ($index < self::VISIBLE_LIMIT) {
                $visible[] = ['name' => $owner, 'full_name' => $fullName];
            } else {
                $remaining[] = $fullName ? $fullName . ' (' . $owner . ')' : $owner;
            }
            $index++;
        }

        return [
            'visible' => $visible,
            'remaining_count' => count($remaining),
            'remaining_label' => implode(', ', $remaining),
        ];
    }

    /**
     * @param array $groups group names
     * @return array{count: int, visible: list<string>, remaining_count: int, remaining_label: string}
     */
    public static function presentGroups(array $groups): array
    {
        $names = array_values($groups);
        $remaining = array_slice($names, self::VISIBLE_LIMIT);

        return [
            'count' => count($names),
            'visible' => array_slice($names, 0, self::VISIBLE_LIMIT),
            'remaining_count' => count($remaining),
            'remaining_label' => implode(', ', $remaining),
        ];
    }
}
