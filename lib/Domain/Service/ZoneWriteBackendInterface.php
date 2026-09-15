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
 * Zone create, delete, kind, primary, account and AXFR-retrieve writes against the DNS backend.
 */
interface ZoneWriteBackendInterface
{
    /**
     * Create a new zone in the DNS backend.
     *
     * @param string $domain Zone name (without trailing dot)
     * @param string $type Zone type: NATIVE, MASTER, SLAVE, PRODUCER, or CONSUMER
     * @param string $slaveMaster Master IP for kinds that replicate from a primary (SLAVE, CONSUMER)
     * @return int|false The new domain ID, or false on failure
     */
    public function createZone(string $domain, string $type, string $slaveMaster = ''): int|false;

    /**
     * Delete a zone and all its associated DNS data (records, metadata, cryptokeys).
     *
     * Does NOT delete Poweradmin-internal data (zones table, records_zone_templ, etc.)
     * - those are handled by the caller.
     *
     * @param int $domainId Domain ID
     * @param string $zoneName Zone name (needed for API calls)
     * @return bool
     */
    public function deleteZone(int $domainId, string $zoneName): bool;

    /**
     * Change zone type (NATIVE, MASTER, SLAVE, PRODUCER, CONSUMER).
     *
     * Masters are cleared unless the new kind replicates from a primary.
     *
     * @param int $domainId Domain ID
     * @param string $type New zone type
     * @return bool
     */
    public function updateZoneType(int $domainId, string $type): bool;

    /**
     * Update slave zone's master IP address.
     *
     * @param int $domainId Domain ID
     * @param string $masterIp Master IP address
     * @return bool
     */
    public function updateZoneMaster(int $domainId, string $masterIp): bool;

    /**
     * Request an immediate AXFR transfer of a secondary (slave) zone from its master.
     *
     * Only the API backend can trigger this. The SQL backend returns false,
     * since PowerDNS pulls secondaries on its own refresh schedule there.
     *
     * @param int $domainId Domain ID
     * @return bool
     */
    public function retrieveZone(int $domainId): bool;

    /**
     * Update zone account field.
     *
     * @param int $domainId Domain ID
     * @param string $account Account value
     * @return bool
     */
    public function updateZoneAccount(int $domainId, string $account): bool;
}
