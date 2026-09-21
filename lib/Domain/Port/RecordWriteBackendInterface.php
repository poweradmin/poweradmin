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
 * Record add, edit and delete writes against the DNS backend.
 */
interface RecordWriteBackendInterface
{
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
     * @param array|null $comment RRset comment ['content' => string, 'account' => string]; null leaves
     *                            comments untouched. SQL mode ignores it.
     * @return int|string|null The new record ID (int for SQL mode, encoded string for API mode), or null on failure
     */
    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, ?array $comment = null): int|string|null;

    /**
     * Create a DNS record atomically with optional disabled flag.
     *
     * In SQL mode, wraps the INSERT in a transaction with deadlock retry.
     * In API mode, one RRset REPLACE carries the record and the disabled flag.
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl Time-to-live
     * @param int $prio Priority
     * @param int $disabled Disabled flag (0 = enabled, 1 = disabled)
     * @param array|null $comment RRset comment as for addRecordGetId()
     * @return int|string|null The new record ID, or null on failure
     */
    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0, ?array $comment = null): int|string|null;

    /**
     * Edit an existing DNS record.
     *
     * @param int|string $recordId Record ID (int for SQL mode, encoded string for API mode)
     * @param string $name New record name
     * @param string $type New record type
     * @param string $content New record content
     * @param int $ttl New TTL
     * @param int $prio New priority
     * @param int $disabled Whether record is disabled (0 or 1)
     * @param array|null $comment RRset comment ['content' => string, 'account' => string]; null leaves
     *                            comments untouched, empty content clears them. SQL mode ignores it.
     * @return bool
     */
    public function editRecord(int|string $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled, ?array $comment = null): bool;

    /**
     * Delete a DNS record by ID.
     *
     * @param int|string $recordId Record ID (int for SQL mode, encoded string for API mode)
     * @return bool
     */
    public function deleteRecord(int|string $recordId): bool;

    /**
     * Replace the content of a zone's SOA record. The SQL backend keeps the stored
     * TTL; the API backend rewrites the whole RRset with the configured dns.ttl.
     *
     * @param int $zoneId Domain ID
     * @param string $content New SOA content
     * @return bool True when the SOA record was rewritten
     */
    public function replaceSoaContent(int $zoneId, string $content): bool;
}
