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
 * Wildcard search over zones and records in the DNS backend.
 */
interface SearchBackendInterface
{
    /**
     * Search for zones and records matching a query.
     *
     * Returns separate arrays for zone matches and record matches.
     * Results contain only DNS data - callers must enrich with
     * Poweradmin metadata (ownership, permissions).
     *
     * @param string $query Search query (supports wildcards)
     * @param string $objectType Filter: 'all', 'zone', 'record'
     * @param int $max Maximum results
     * @return array{zones: array, records: array}
     */
    public function searchDnsData(string $query, string $objectType = 'all', int $max = 100): array;
}
