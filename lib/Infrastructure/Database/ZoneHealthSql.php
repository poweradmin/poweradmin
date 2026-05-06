<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Database;

/**
 * Reusable SQL fragments that derive zone-health flags (Disabled / No SOA)
 * from the PowerDNS records table. Used by SQL-mode repositories to render
 * status badges in the zone list without an extra round-trip.
 */
final class ZoneHealthSql
{
    /**
     * SELECT-list fragment yielding `is_disabled` and `is_missing_soa` columns.
     * SLAVE zones legitimately lack SOA so they are never flagged as missing.
     *
     * @param string $domainsTable Domains-table reference (prefixed if needed)
     * @param string $recordsTable Records-table reference
     * @return string Two comma-separated SQL columns, no trailing comma.
     */
    public static function soaHealthColumns(string $domainsTable, string $recordsTable): string
    {
        // The SOA must live at the zone apex (records.name = domains.name).
        // A stray SOA under a subname doesn't make the zone served, and an
        // off-apex disabled SOA doesn't disable it either.
        return "CASE WHEN EXISTS (SELECT 1 FROM $recordsTable r_soa WHERE r_soa.domain_id = $domainsTable.id AND r_soa.type = 'SOA' AND r_soa.name = $domainsTable.name AND r_soa.disabled) THEN 1 ELSE 0 END AS is_disabled,
                CASE WHEN ($domainsTable.type IS NULL OR UPPER($domainsTable.type) <> 'SLAVE') AND NOT EXISTS (SELECT 1 FROM $recordsTable r_soa_any WHERE r_soa_any.domain_id = $domainsTable.id AND r_soa_any.type = 'SOA' AND r_soa_any.name = $domainsTable.name) THEN 1 ELSE 0 END AS is_missing_soa";
    }
}
