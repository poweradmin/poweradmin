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

namespace Poweradmin\Domain\Port;

/**
 * Capability probe telling callers which kind of DNS backend they hold.
 */
interface BackendCapabilitiesInterface
{
    /**
     * Check if this is the API backend.
     *
     * Callers can use this to adjust behavior (e.g., skip manual SOA serial
     * updates, show notices about search limitations).
     *
     * @return bool
     */
    public function isApiBackend(): bool;

    /**
     * Whether backend writes can be wrapped in a transaction on the Poweradmin database.
     *
     * True for the SQL backend, where the PowerDNS tables share the connection. False
     * for the API backend: it writes over HTTP and then reads the local zones table for
     * the new ids, and an open transaction would hide those rows.
     *
     * @return bool
     */
    public function supportsLocalWriteTransaction(): bool;

    /**
     * Whether record ids are the integer records.id values.
     *
     * True for the SQL backend. False for the API backend, whose record ids are
     * encoded composite keys (see RecordIdentifier) that do not fit an INT column.
     *
     * @return bool
     */
    public function recordIdsAreNumeric(): bool;

    /**
     * Whether the backend writes and maintains the apex SOA of the zones it creates.
     *
     * True for the API backend, where PowerDNS seeds the SOA on zone creation and
     * template SOA records are skipped. False for the SQL backend, which stores
     * whatever SOA Poweradmin writes.
     *
     * @return bool
     */
    public function managesSoaRecord(): bool;

    /**
     * Whether zone ids are allocated from the local zones table rather than the
     * PowerDNS domains table.
     *
     * True for the API backend: createZone() inserts the zones row itself and a zone
     * is identified by that row alone. False for the SQL backend, where the id is
     * domains.id, the caller adds the zones row and a zone without a domains row is stale.
     *
     * @return bool
     */
    public function allocatesZoneIdsLocally(): bool;

    /**
     * Whether the zone list can carry the serial PowerDNS serves for signed zones.
     *
     * True for the API backend, whose zone list reports the signed serial. False
     * for the SQL backend, which only sees the stored SOA serial.
     *
     * @return bool
     */
    public function providesSignedSerial(): bool;

    /**
     * Whether zone lists can be ordered by record count across every page.
     *
     * True for the SQL backend, which counts in the query. False for the API
     * backend, where counts are resolved per page and a sort would only order
     * the rows already on screen.
     *
     * @return bool
     */
    public function supportsRecordCountSort(): bool;

    /**
     * Whether zone lists can be ordered by owning group.
     *
     * True for the SQL backend, which joins the Poweradmin ownership tables in
     * the list query. False for the API backend, whose zone list comes from
     * PowerDNS and cannot join them.
     *
     * @return bool
     */
    public function supportsGroupSort(): bool;

    /**
     * Whether a secondary zone can be pulled from its primary on request.
     *
     * True for the API backend, which asks PowerDNS to transfer the zone. False
     * for the SQL backend, which has no way to trigger a transfer.
     *
     * @return bool
     */
    public function supportsZoneRetrieve(): bool;

    /**
     * Whether the local zone list is a mirror that can be refreshed from the server.
     *
     * True for the API backend, where the zones table is synced from PowerDNS.
     * False for the SQL backend, where the domains table is the source itself.
     *
     * @return bool
     */
    public function syncsZoneListFromServer(): bool;
}
