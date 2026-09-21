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
 * Single-record reads and existence checks within a zone.
 */
interface RecordLookupInterface
{
    /**
     * Get a record by ID
     *
     * @param int|string $recordId Record ID (int for SQL mode, encoded string for API mode)
     * @return array|null Record data if found, null otherwise
     */
    public function getRecordById(int|string $recordId): ?array;

    /**
     * Get a Record from a Record ID
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int|string $id Record ID (int for SQL mode, encoded string for API mode)
     * @return array|null array of record detail, or null if nothing found
     */
    public function getRecordFromId(int|string $id): ?array;

    /**
     * Get record details from Record ID
     *
     * @param int|string $rid Record ID (int for SQL mode, encoded string for API mode)
     *
     * @return array array of record details [rid,zid,name,type,content,ttl,prio]
     */
    public function getRecordDetailsFromRecordId(int|string $rid): array;

    /**
     * Get Zone ID from Record ID
     *
     * @param int|string $rid Record ID
     *
     * @return int Zone ID
     */
    public function getZoneIdFromRecordId(int|string $rid): int;

    /**
     * Record ID to Domain ID
     *
     * Gets the id of the domain by a given record id
     *
     * @param int|string $id Record ID (int for SQL mode, encoded string for API mode)
     * @return int Domain ID of record
     */
    public function recidToDomid(int|string $id): int;

    /**
     * Check if a record with the given parameters already exists
     *
     * @param int $domain_id Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return bool True if record exists, false otherwise
     */
    public function recordExists(int $domain_id, string $name, string $type, string $content): bool;

    /**
     * Get the records at one name in a zone, optionally filtered by type.
     *
     * Prefer this over getRecordsByDomainId() whenever only one name matters:
     * in API backend mode the zone-wide call fetches every record in the zone.
     * Names match case-insensitively, per RFC 4343.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name, with or without a trailing dot
     * @param string|null $type Optional record type filter
     * @return array Array of records
     */
    public function getRecordsByName(int $domainId, string $name, ?string $type = null): array;

    /**
     * Check if any PTR record exists for a given reverse domain name
     *
     * @param int $domain_id Domain ID
     * @param string $name Reverse domain name (e.g., "1.1.168.192.in-addr.arpa")
     *
     * @return bool True if any PTR record exists for this name
     */
    public function hasPtrRecord(int $domain_id, string $name): bool;

    /**
     * Check if record has similar records with same name and type
     *
     * @param int $domain_id Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param int|string $record_id Current record ID to exclude from check
     * @return bool True if similar records found, false otherwise
     */
    public function hasSimilarRecords(int $domain_id, string $name, string $type, int|string $record_id): bool;

    /**
     * Check if non-delegation records exist for a given name
     *
     * Per RFC 1034: Delegation NS records are NOT authoritative data.
     * Per RFC 4034: DS records are part of the delegation for DNSSEC.
     * This method checks for records other than NS/DS which indicate authoritative data.
     * Only delegation records (NS, DS) at a name should not prevent zone creation.
     *
     * @param string $name Record name
     * @return bool True if non-delegation records exist, false otherwise
     */
    public function hasNonDelegationRecords(string $name): bool;
}
