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
 * SOA serial handling: whether PowerDNS bumps the serial itself and the zone serial policy metadata.
 */
interface SerialBackendInterface
{
    /**
     * Check whether the zone has soa_edit_api configured in PowerDNS.
     * Returns false for SQL backends (not applicable).
     */
    public function hasSoaEditApi(int $domainId): bool;

    /**
     * Set the zone's SOA serial policy metadata.
     *
     * Keys are the zone-object property names from
     * MetadataDefinitions::SERIAL_POLICY_PROPERTY_KINDS. An empty string clears the
     * policy. API backend updates the zone object; SQL backend replaces the
     * matching domainmetadata rows.
     *
     * @param array<string, string> $properties
     */
    public function setZoneSerialPolicy(int $domainId, string $zoneName, array $properties): bool;
}
