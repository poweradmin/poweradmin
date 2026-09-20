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

use Poweradmin\Domain\Model\Constants;

/**
 * Persistence for zone templates (zone_templ), their records (zone_templ_records)
 * and the zone -> template links kept in zones.zone_templ_id.
 *
 * The domain model (Poweradmin\Domain\Model\ZoneTemplate) owns permission checks,
 * validation and placeholder expansion; every SQL statement behind those decisions
 * lives here.
 */
interface ZoneTemplateRepositoryInterface
{
    /**
     * List zone templates visible to the given user, with owner/creator names and
     * the number of zones linked to each template.
     *
     * @param int|null $userId User ID (null together with $isUeberuser = true lists all)
     * @param bool $isUeberuser Whether the user may see every template
     * @return array List of zone templates
     */
    public function listZoneTemplates(?int $userId, bool $isUeberuser): array;

    /**
     * Get zone template details by ID.
     *
     * @param int $id Zone template ID
     * @return array|false Template details or false if not found
     */
    public function getZoneTemplateDetails(int $id): array|false;

    /**
     * Get the template name linked to a zone.
     *
     * @param int|string $zoneId Zone ID (zones.id, or the canonical id column on API backends)
     * @return string Template name, or an empty string when the zone has no template
     */
    public function getTemplateNameForZone(int|string $zoneId): string;

    /**
     * Check if a zone template exists.
     *
     * @param int $id Template ID
     * @return bool True if it exists
     */
    public function zoneTemplateExists(int $id): bool;

    /**
     * Check if a zone template name is already taken.
     *
     * @param string $name Template name
     * @param int|null $excludeId Exclude this template ID from the check (for updates)
     * @return bool True if the name exists
     */
    public function zoneTemplateNameExists(string $name, ?int $excludeId = null): bool;

    /**
     * Find every template ID carrying the given name. Names are not unique.
     *
     * @param string $name Zone template name
     * @return int[] Matching template IDs
     */
    public function findTemplateIdsByName(string $name): array;

    /**
     * Get the owner ID of a zone template.
     *
     * @param int $templateId Template ID
     * @return int|null Owner ID (0 for a global template) or null if not found
     */
    public function getOwner(int $templateId): ?int;

    /**
     * Check if the user owns a zone template.
     *
     * @param int $templateId Template ID
     * @param int $userId User ID
     * @return bool True if the user is the owner
     */
    public function isOwner(int $templateId, int $userId): bool;

    /**
     * ID of the global template flagged is_default, if any.
     *
     * @return int|null Template ID or null when no template is flagged
     */
    public function findFlaggedDefaultTemplateId(): ?int;

    /**
     * Whether a global template (owner 0) with this ID exists.
     *
     * @param int $id Template ID
     * @return bool True if it exists and is global
     */
    public function globalTemplateExists(int $id): bool;

    /**
     * Find global templates (owner 0) by name.
     *
     * @param string $name Template name
     * @return int[] Matching template IDs
     */
    public function findGlobalTemplateIdsByName(string $name): array;

    /**
     * Flag one global template as the system-wide default, clearing the flag from
     * every other global template in the same statement.
     *
     * @param int $templateId Template to flag
     */
    public function flagDefaultTemplate(int $templateId): void;

    /**
     * Clear the system-wide default template flag.
     */
    public function clearDefaultTemplate(): void;

    /**
     * Create a new zone template with a default SOA record.
     *
     * @param string $name Template name
     * @param string $description Template description
     * @param int $owner Owner user ID (0 for global)
     * @param int $createdBy Creator user ID
     * @return int New template ID
     */
    public function createZoneTemplate(string $name, string $description, int $owner, int $createdBy): int;

    /**
     * Create a zone template together with the given records, in one transaction.
     * A default SOA record is appended when the records carry none.
     *
     * @param string $name Template name
     * @param string $description Template description
     * @param int $owner Owner user ID (0 for global)
     * @param int $createdBy Creator user ID
     * @param array<int, array{name: string, type: string, content: string, ttl: mixed, prio?: mixed}> $records
     * @return int New template ID
     */
    public function createZoneTemplateWithRecords(
        string $name,
        string $description,
        int $owner,
        int $createdBy,
        array $records
    ): int;

    /**
     * Update a zone template.
     *
     * @param int $id Template ID
     * @param string $name New name
     * @param string $description New description
     * @param int|null $owner New owner (null keeps the current one)
     * @return bool True on success
     */
    public function updateZoneTemplate(int $id, string $name, string $description, ?int $owner = null): bool;

    /**
     * Delete a zone template with its records and links.
     *
     * @param int $id Template ID
     * @return bool True on success
     */
    public function deleteZoneTemplate(int $id): bool;

    /**
     * Delete every template the user owns, with its records and zone links.
     * Runs inside the caller's transaction.
     *
     * @param int $ownerId Owner user ID
     */
    public function deleteZoneTemplatesOwnedBy(int $ownerId): void;

    /**
     * Get the records of a zone template.
     *
     * @param int $templateId Zone template ID
     * @param int $rowStart Starting row
     * @param int $rowAmount Number of rows
     * @param string $sortBy Column to sort by
     * @return array Template records
     */
    public function getZoneTemplateRecords(
        int $templateId,
        int $rowStart = 0,
        int $rowAmount = Constants::DEFAULT_MAX_ROWS,
        string $sortBy = 'name'
    ): array;

    /**
     * Get a single zone template record by ID.
     *
     * @param int $id Record ID
     * @param int|null $templateId Restrict the lookup to this template, so a record
     *                             ID from another template cannot be read
     * @return array Record details or an empty array
     */
    public function getZoneTemplateRecordById(int $id, ?int $templateId = null): array;

    /**
     * Count the records in a zone template.
     *
     * @param int $templateId Zone template ID
     * @return int Number of records
     */
    public function countZoneTemplateRecords(int $templateId): int;

    /**
     * Add a record to a zone template.
     *
     * @param int $templateId Template ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL
     * @param int $prio Priority
     * @return int New record ID
     */
    public function addRecord(int $templateId, string $name, string $type, string $content, int $ttl, int $prio): int;

    /**
     * Update a zone template record.
     *
     * @param int $id Record ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL
     * @param int $prio Priority
     * @return bool True on success
     */
    public function updateRecord(int $id, string $name, string $type, string $content, int $ttl, int $prio): bool;

    /**
     * Delete a zone template record.
     *
     * @param int $id Record ID
     * @return bool True on success
     */
    public function deleteRecord(int $id): bool;

    /**
     * Unlink a zone from its template.
     *
     * @param int $zoneId Zone ID (zones.domain_id)
     * @return bool True on success
     */
    public function unlinkZoneFromTemplate(int $zoneId): bool;
}
