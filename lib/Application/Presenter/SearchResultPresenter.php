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

use Poweradmin\Domain\Database\DbCompat;

/**
 * Labels the record search rows for the search page. The gateways return
 * `disabled` as a bool; the translated Yes/No goes into `disabled_label`.
 */
final class SearchResultPresenter
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function records(array $rows): array
    {
        foreach ($rows as &$row) {
            $disabled = (bool)DbCompat::boolFromDb($row['disabled'] ?? false);
            $row['disabled'] = $disabled;
            $row['disabled_label'] = $disabled ? _('Yes') : _('No');
        }
        unset($row);

        return $rows;
    }
}
