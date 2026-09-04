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
 * Persistence boundary for admin-configured default TTLs keyed by record type.
 *
 * Implementations are expected to store and retrieve a single TTL per record
 * type (e.g. `PTR => 300`). The web UI manages the entries; record-creation
 * paths consult this repository before falling back to legacy config.
 */
interface RecordTypeDefaultRepositoryInterface
{
    /**
     * Return the configured default TTL for the given record type, or null
     * when no row exists.
     */
    public function find(string $recordType): ?int;

    /**
     * Return the complete map of record-type to TTL configured by the admin.
     *
     * @return array<string, int>
     */
    public function findAll(): array;

    /**
     * Insert or update the TTL for the given record type.
     */
    public function save(string $recordType, int $ttl): void;

    /**
     * Remove the configured default for the given record type, falling back
     * to legacy behavior.
     */
    public function delete(string $recordType): void;

    /**
     * Whether the backing table is available. False on upgraded installations
     * where the PHP code has been deployed but the 4.5.0 schema update has
     * not yet been applied.
     */
    public function isReady(): bool;
}
