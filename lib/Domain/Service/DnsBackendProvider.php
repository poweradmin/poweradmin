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
 * Interface for DNS data backend operations.
 *
 * Abstracts the underlying DNS data store (direct SQL or PowerDNS REST API).
 * Poweradmin-internal tables (zones, users, permissions, templates) are always
 * accessed via SQL regardless of the backend - this interface only covers
 * operations on PowerDNS data tables (domains, records, supermasters, etc.).
 */
interface DnsBackendProvider
{
    // ---------------------------------------------------------------
    // Zone / Domain operations
    // ---------------------------------------------------------------

    /**
     * Create a new zone in the DNS backend.
     *
     * @param string $domain Zone name (without trailing dot)
     * @param string $type Zone type: NATIVE, MASTER, or SLAVE
     * @param string $slaveMaster Master IP for SLAVE zones
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
     * Change zone type (NATIVE, MASTER, SLAVE).
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
     * Update zone account field.
     *
     * @param int $domainId Domain ID
     * @param string $account Account value
     * @return bool
     */
    public function updateZoneAccount(int $domainId, string $account): bool;

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    /**
     * Add a DNS record.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type (A, AAAA, CNAME, MX, etc.)
     * @param string $content Record content
     * @param int $ttl Time-to-live
     * @param int $prio Priority
     * @return bool
     */
    public function addRecord(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): bool;

    /**
     * Add a DNS record and return its ID.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl Time-to-live
     * @param int $prio Priority
     * @return int|null The new record ID, or null on failure
     */
    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): ?int;

    /**
     * Create a DNS record atomically with optional disabled flag.
     *
     * In SQL mode, wraps the INSERT in a transaction with deadlock retry.
     * In API mode, delegates to addRecordGetId + optional editRecord.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl Time-to-live
     * @param int $prio Priority
     * @param int $disabled Disabled flag (0 = enabled, 1 = disabled)
     * @return int|null The new record ID, or null on failure
     */
    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0): ?int;

    /**
     * Edit an existing DNS record.
     *
     * @param int $recordId Record ID
     * @param string $name New record name
     * @param string $type New record type
     * @param string $content New record content
     * @param int $ttl New TTL
     * @param int $prio New priority
     * @param int $disabled Whether record is disabled (0 or 1)
     * @return bool
     */
    public function editRecord(int $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled): bool;

    /**
     * Delete a DNS record by ID.
     *
     * @param int $recordId Record ID
     * @return bool
     */
    public function deleteRecord(int $recordId): bool;

    /**
     * Delete all records for a given domain.
     *
     * @param int $domainId Domain ID
     * @return bool
     */
    public function deleteRecordsByDomainId(int $domainId): bool;

    // ---------------------------------------------------------------
    // SOA operations
    // ---------------------------------------------------------------

    /**
     * Update the SOA serial for a zone.
     *
     * In API mode this is a no-op since PowerDNS handles serial
     * increments automatically via soa_edit_api.
     *
     * @param int $domainId Domain ID
     * @return bool
     */
    public function updateSOASerial(int $domainId): bool;

    // ---------------------------------------------------------------
    // Supermaster / Autoprimary operations
    // ---------------------------------------------------------------

    /**
     * Add a supermaster (autoprimary).
     *
     * @param string $masterIp Supermaster IP address
     * @param string $nsName Nameserver hostname
     * @param string $account Account name
     * @return bool
     */
    public function addSupermaster(string $masterIp, string $nsName, string $account): bool;

    /**
     * Delete a supermaster (autoprimary).
     *
     * @param string $masterIp Supermaster IP address
     * @param string $nsName Nameserver hostname
     * @return bool
     */
    public function deleteSupermaster(string $masterIp, string $nsName): bool;

    /**
     * Get all supermasters.
     *
     * @return array Array of supermaster records
     */
    public function getSupermasters(): array;

    /**
     * Update a supermaster.
     *
     * @param string $oldMasterIp Original IP
     * @param string $oldNsName Original nameserver
     * @param string $newMasterIp New IP
     * @param string $newNsName New nameserver
     * @param string $account Account name
     * @return bool
     */
    public function updateSupermaster(string $oldMasterIp, string $oldNsName, string $newMasterIp, string $newNsName, string $account): bool;

    // ---------------------------------------------------------------
    // Zone read methods (used by repositories in API mode)
    // ---------------------------------------------------------------

    /**
     * Check if a zone exists by name.
     *
     * @param string $zoneName Zone name
     * @return bool
     */
    public function zoneExists(string $zoneName): bool;

    /**
     * Get zone info by ID.
     *
     * @param int $domainId Domain ID
     * @return array|null [id, name, type, master, dnssec] or null
     */
    public function getZoneById(int $domainId): ?array;

    /**
     * Get zone name by domain ID.
     *
     * @param int $domainId Domain ID
     * @return string|null Zone name or null
     */
    public function getZoneNameById(int $domainId): ?string;

    /**
     * Get zone ID by name.
     *
     * @param string $zoneName Zone name
     * @return int|null Domain ID or null
     */
    public function getZoneIdByName(string $zoneName): ?int;

    /**
     * Get zone type by domain ID.
     *
     * @param int $domainId Domain ID
     * @return string Zone type (NATIVE, MASTER, SLAVE)
     */
    public function getZoneTypeById(int $domainId): string;

    /**
     * Get zone master by domain ID.
     *
     * @param int $domainId Domain ID
     * @return string|null Master IP or null
     */
    public function getZoneMasterById(int $domainId): ?string;

    // ---------------------------------------------------------------
    // Record read methods (used by repositories in API mode)
    // ---------------------------------------------------------------

    /**
     * Get a single record by ID.
     *
     * @param int $recordId Record ID
     * @return array|null Record data or null
     */
    public function getRecordById(int $recordId): ?array;

    /**
     * Get zone ID from a record ID.
     *
     * @param int $recordId Record ID
     * @return int Zone ID (0 if not found)
     */
    public function getZoneIdFromRecordId(int $recordId): int;

    /**
     * Count non-ENT records in a zone.
     *
     * @param int $domainId Domain ID
     * @return int Record count
     */
    public function countZoneRecords(int $domainId): int;

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
     * Get SOA record content for a zone.
     *
     * @param int $domainId Domain ID
     * @return string SOA content or empty string
     */
    public function getSOARecord(int $domainId): string;

    /**
     * Find the best matching reverse zone ID for a PTR record name.
     *
     * @param string $reverseName Reverse name (e.g. 1.168.192.in-addr.arpa)
     * @return int Zone ID or -1 if not found
     */
    public function getBestMatchingReverseZoneId(string $reverseName): int;

    // ---------------------------------------------------------------
    // Zone read operations
    // ---------------------------------------------------------------

    /**
     * Get all zones from the DNS backend.
     *
     * Returns raw zone data without Poweradmin metadata (ownership, comments).
     * Each zone array contains: name, type, dnssec (bool), master (string).
     *
     * @return array Array of zone data arrays
     */
    public function getZones(): array;

    /**
     * Get a single zone by name with its type, master, and DNSSEC status.
     *
     * @param string $zoneName Zone name (without trailing dot)
     * @return array|null Zone data array or null if not found
     */
    public function getZoneByName(string $zoneName): ?array;

    // ---------------------------------------------------------------
    // Record read operations
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Search operations
    // ---------------------------------------------------------------

    /**
     * Search for zones and records matching a query.
     *
     * Returns separate arrays for zone matches and record matches.
     * Results contain only DNS data - callers must enrich with
     * Poweradmin metadata (ownership, permissions).
     *
     * @param string $query Search query (supports wildcards)
     * @param string $objectType Filter: 'all', 'zone', 'record'
     * @param int $max Maximum results
     * @return array{zones: array, records: array}
     */
    public function searchDnsData(string $query, string $objectType = 'all', int $max = 100): array;

    // ---------------------------------------------------------------
    // Capability check
    // ---------------------------------------------------------------

    /**
     * Check if this is the API backend.
     *
     * Callers can use this to adjust behavior (e.g., skip manual SOA serial
     * updates, show notices about search limitations).
     *
     * @return bool
     */
    public function isApiBackend(): bool;
}
