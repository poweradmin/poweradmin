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
 * Tracks which zone records a template application wrote, so a later
 * application of the same template can remove exactly those records.
 *
 * SQL backends key the link on the numeric records.id; the API backend keys
 * it on the encoded record identifier and keeps it in a separate table.
 */
interface TemplateRecordLinkRepositoryInterface
{
    /**
     * Link a record the template just wrote to the template.
     *
     * @param int|string $recordId Numeric id on SQL backends, encoded id on the API backend
     */
    public function linkRecord(int $zoneId, int|string $recordId, int $templateId): void;

    /**
     * Delete the records a template application wrote to a zone, and their links.
     * SQL backends only: the records are removed from the PowerDNS table directly.
     *
     * @return array Rows (id, name, type, content, ttl, prio, disabled) of the removed records
     */
    public function removeLinkedRecords(int $zoneId, int $templateId): array;

    /**
     * Delete the apex SOA records of a zone so the template's SOA can replace them.
     * SQL backends only.
     *
     * @return array Rows (id, name, type, content, ttl, prio, disabled) of the removed records
     */
    public function removeSoaRecords(int $zoneId): array;

    /**
     * Links a template application wrote on the API backend.
     *
     * @return array Rows (id, record_id) with the encoded record identifier
     */
    public function listEncodedLinks(int $zoneId, int $templateId): array;

    /**
     * Drop API backend links by their row ids.
     *
     * @param int[] $linkIds
     */
    public function removeEncodedLinks(array $linkIds): void;
}
