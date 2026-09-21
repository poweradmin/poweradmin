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

namespace Poweradmin\Domain\Repository;

/**
 * Where a zone's metadata rows live: the domainmetadata table on the SQL
 * backend, or the PowerDNS API (metadata plus zone-object properties) on the
 * API backend. Rows are [['kind' => string, 'content' => string], ...].
 */
interface ZoneMetadataStoreInterface
{
    /**
     * The zone's metadata rows sorted by kind.
     *
     * @return list<array{kind: string, content: string}>
     */
    public function load(int $zoneId, string $zoneName): array;

    /**
     * Replace the zone's whole metadata set.
     *
     * @param list<array{kind: string, content: string}> $rows The set after the write
     * @param list<array{kind: string, content: string}> $before The set as loaded before it
     */
    public function replaceAll(int $zoneId, string $zoneName, array $rows, array $before): bool;

    /**
     * Set every value of one kind, leaving the other kinds alone.
     *
     * @param list<string> $values Empty removes the kind
     * @param list<array{kind: string, content: string}> $before The set as loaded before the write
     */
    public function replaceKind(int $zoneId, string $zoneName, string $kind, array $values, array $before): bool;
}
