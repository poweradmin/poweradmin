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

namespace Poweradmin\Domain\Service;

/**
 * Central catalogue of $_SESSION keys.
 *
 * Search and list views deliberately use different sort buckets so a column
 * picked in one view cannot leak into a query that does not support it.
 */
final class SessionKeys
{
    public const LIST_ZONE_SORT_BY = 'list_zone_sort_by';
    public const SEARCH_ZONE_SORT_BY = 'zone_sort_by';
    public const SEARCH_RECORD_SORT_BY = 'record_sort_by';
    public const REVERSE_ZONE_TYPE = 'reverse_zone_type';

    private function __construct()
    {
    }
}
