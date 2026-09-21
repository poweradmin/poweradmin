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
 * Catalog zone membership reads and writes against the DNS backend.
 */
interface CatalogBackendInterface
{
    /**
     * Zones carrying the given catalog.
     *
     * PowerDNS only publishes a member whose kind is MASTER or PRODUCER and which
     * has an enabled apex SOA, so the kind is returned for callers to flag the rest.
     *
     * @param string $catalogName Producer zone name, lowercase and without a trailing dot
     * @return array<int, array{id: int, name: string, kind: string}>
     */
    public function getCatalogMembers(string $catalogName): array;

    /**
     * Zones of one kind, with the catalog each currently belongs to.
     *
     * @param string $kind A ZoneType constant
     * @return array<int, array{id: int, name: string, catalog: string}>
     */
    public function getZonesByKind(string $kind): array;

    /**
     * Catalog the zone belongs to, lowercase and without a trailing dot, or '' when
     * it is not a member.
     *
     * @param int $domainId Domain ID
     * @return string
     */
    public function getZoneCatalog(int $domainId): string;

    /**
     * Join $catalogName, or leave the current catalog when it is ''.
     *
     * @param int $domainId Domain ID
     * @param string $catalogName Producer zone name in canonical form, or '' to clear
     * @return bool
     */
    public function updateZoneCatalog(int $domainId, string $catalogName): bool;
}
