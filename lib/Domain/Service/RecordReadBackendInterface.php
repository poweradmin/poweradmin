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
 * Record lookups by id, name or zone read from the DNS backend.
 */
interface RecordReadBackendInterface
{
    /**
     * Get a single record by ID.
     *
     * @param int|string $recordId Record ID (int for SQL mode, encoded string for API mode)
     * @return array|null Record data or null
     */
    public function getRecordById(int|string $recordId): ?array;

    /**
     * Get zone ID from a record ID.
     *
     * @param int|string $recordId Record ID (int for SQL mode, encoded string for API mode)
     * @return int Zone ID (0 if not found)
     */
    public function getZoneIdFromRecordId(int|string $recordId): int;

    /**
     * Check if a record with given attributes exists.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return bool
     */
    public function recordExists(int $domainId, string $name, string $type, string $content): bool;

    /**
     * Get records by zone ID, optionally filtered by type.
     *
     * @param int $domainId Domain ID
     * @param string|null $type Optional record type filter
     * @return array Array of record data
     */
    public function getRecordsByZoneId(int $domainId, ?string $type = null): array;

    /**
     * Get the records at one name in a zone, optionally filtered by type.
     *
     * Names match case-insensitively, per RFC 4343.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name, with or without a trailing dot
     * @param string|null $type Optional record type filter
     * @return array Array of record data
     */
    public function getRecordsByName(int $domainId, string $name, ?string $type = null): array;

    /**
     * Get SOA record content for a zone.
     *
     * @param int $domainId Domain ID
     * @return string SOA content or empty string
     */
    public function getSOARecord(int $domainId): string;

    /**
     * Get all records for a zone from the DNS backend.
     *
     * Returns a flat array of record data. In SQL mode, records include
     * numeric IDs. In API mode, IDs are resolved from the database.
     * ENT records (null/empty type) are excluded.
     *
     * @param int $domainId Domain ID
     * @param string $zoneName Zone name (needed for API calls)
     * @return array Array of record data arrays
     */
    public function getZoneRecords(int $domainId, string $zoneName): array;
}
