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

namespace Poweradmin\Domain\Service\Consistency;

/**
 * Finds and repairs ownerless zones, masterless slave zones, orphaned records and
 * missing or duplicate SOAs. One implementation per DNS backend.
 *
 * Every check returns ['status' => 'success'|'warning'|'error', 'message' => string,
 * 'data' => list of findings].
 */
interface ConsistencyCheckerInterface
{
    /** @return array{status: string, message: string, data: array} */
    public function checkZonesHaveOwners(): array;

    /** @return array{status: string, message: string, data: array} */
    public function checkZonesHaveCanonicalIds(): array;

    /** @return array{status: string, message: string, data: array} */
    public function checkSlaveZonesHaveMasters(): array;

    /** @return array{status: string, message: string, data: array} */
    public function checkRecordsBelongToZones(): array;

    /** @return array{status: string, message: string, data: array} */
    public function checkDuplicateSOARecords(): array;

    /** @return array{status: string, message: string, data: array} */
    public function checkZonesWithoutSOA(): array;

    /**
     * Run every check, keyed by check name. Null means the backend could not be
     * read and no result can be trusted.
     *
     * @return array<string, array{status: string, message: string, data: array}>|null
     */
    public function runAllChecks(): ?array;

    /** Assign $currentUserId as the owner of a zone that has none. */
    public function fixZoneWithoutOwner(int $zoneId, int $currentUserId): bool;

    /** @return array{assigned: int, failed: int} */
    public function fixAllZonesWithoutOwner(int $currentUserId): array;

    /** Point a stranded zones row's domain_id at its own id; $zoneId is the row's primary key. */
    public function fixZoneCanonicalId(int $zoneId): bool;

    /** @return array{fixed: int, failed: int} */
    public function fixAllZonesWithCanonicalIdIssue(): array;

    public function deleteSlaveZone(int $zoneId): bool;

    public function deleteOrphanedRecord(int $recordId): bool;

    /** Keep the first SOA record of the zone and delete the rest. */
    public function fixDuplicateSOA(int $zoneId): bool;

    public function createDefaultSOA(int $zoneId): bool;
}
