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
}
