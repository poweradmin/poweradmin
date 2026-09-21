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
 * Tracks which zones are out of date against their template after the template changes.
 */
interface ZoneTemplateSyncRepositoryInterface
{
    /**
     * Mark all zones using a template as needing sync
     */
    public function markTemplateAsModified(int $templateId): void;

    /**
     * Mark a specific zone as synced with its template
     */
    public function markZoneAsSynced(int $zoneId, int $templateId): void;

    /**
     * Mark multiple zones as synced
     *
     * @param list<int> $zoneIds
     */
    public function markZonesAsSynced(array $zoneIds, int $templateId): void;

    /**
     * Create sync tracking record when zone is linked to template
     */
    public function createSyncRecord(int $zoneId, int $templateId): void;

    /**
     * Drop sync rows for prior templates so the table reflects the zone's current template.
     * Pass 0 as $keepTemplateId to clear every sync row for the zone.
     */
    public function removeStaleSyncRecords(int $zoneId, int $keepTemplateId): void;

    /**
     * Remove all sync tracking records for a zone when it's deleted
     */
    public function cleanupZoneSyncRecords(int $zoneId): void;

    /**
     * Get count of zones needing sync for a template
     */
    public function getUnsyncedZoneCount(int $templateId): int;

    /**
     * Get sync status for all templates
     *
     * @return array<int|string, array{name: string, total_zones: int, unsynced_zones: int, is_synced: bool}>
     */
    public function getTemplateSyncStatus(?int $userId = null): array;
}
