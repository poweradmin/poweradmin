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
 * Zone lookups, listings, record counts and SOA health read from the DNS backend.
 */
interface ZoneReadBackendInterface
{
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
     * @return string One of the ZoneKind values (NATIVE, MASTER, SLAVE, PRODUCER, CONSUMER); NATIVE when unknown
     */
    public function getZoneTypeById(int $domainId): string;

    /**
     * Get zone master by domain ID, for any zone kind.
     *
     * @param int $domainId Domain ID
     * @return string|null Master IP or null if none is stored
     */
    public function getZoneMasterById(int $domainId): ?string;

    /**
     * Find the best matching reverse zone ID for a PTR record name.
     *
     * @param string $reverseName Reverse name (e.g. 1.168.192.in-addr.arpa)
     * @return int Zone ID or -1 if not found
     */
    public function getBestMatchingReverseZoneId(string $reverseName): int;

    /**
     * Get all zones from the DNS backend.
     *
     * Returns raw zone data without Poweradmin metadata (ownership, comments).
     * Each zone array contains: name, type, dnssec (bool), master (string).
     *
     * @param bool $withDnssec Set false to skip DNSSEC state; API backends then
     *                         avoid a per-zone lookup and report dnssec as false
     * @return array Array of zone data arrays
     */
    public function getZones(bool $withDnssec = true): array;

    /**
     * Get id, name and type for the given zone ids, sorted by name.
     * Unknown ids are skipped.
     *
     * @param int[] $domainIds Domain IDs
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public function getZonesByIds(array $domainIds): array;

    /**
     * Count all zones in the DNS backend.
     *
     * @return int Zone count
     */
    public function countZones(): int;

    /**
     * Count all record rows in the DNS backend, ENTs included.
     *
     * @return int|null Record count, or null when the backend has no global count (API)
     */
    public function countRecords(): ?int;

    /**
     * Count non-ENT records in a zone.
     *
     * @param int $domainId Domain ID
     * @return int Record count
     */
    public function countZoneRecords(int $domainId): int;

    /**
     * Get bulk zone stats (DNSSEC state, SOA serials) keyed by zone name.
     *
     * SQL backends may return an empty array when this data is not needed;
     * API backends fetch it in a single call to avoid N+1 lookups in zone lists.
     * Record counts are not included - the PowerDNS zone list carries none.
     *
     * @param bool $withDnssec Set false when neither the DNSSEC flag nor edited_serial is needed
     * @return array<string, array{dnssec: bool, serial?: int, edited_serial?: int|null, notified_serial?: int|null}>
     */
    public function getZoneStats(bool $withDnssec = true): array;

    /**
     * Report SOA-record presence and disabled state for a zone, used by zone-list
     * UIs to render "Disabled" / "No SOA" badges. SLAVE zones legitimately have
     * no SOA (records arrive via AXFR), so callers may pass the zone kind to skip
     * unnecessary lookups; implementations must return is_missing_soa=false for SLAVE.
     *
     * Returns null on transient backend failure so callers can preserve any
     * previously-cached state instead of overwriting it with an inferred default.
     *
     * @param string $zoneName Zone name (without trailing dot)
     * @param string $kind Zone kind ('MASTER', 'NATIVE', 'SLAVE', 'PRODUCER', 'CONSUMER')
     * @return array{is_disabled: bool, is_missing_soa: bool}|null
     */
    public function getZoneSoaHealth(string $zoneName, string $kind): ?array;
}
