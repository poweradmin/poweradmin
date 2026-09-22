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
use Poweradmin\Domain\Model\RecordRow;

/**
 * Zone-wide record listing, filtering, counting and RRset reads.
 */
interface RecordListingInterface
{
    /**
     * Get records by domain ID
     *
     * @param int $domainId Domain ID
     * @param string|null $recordType Optional record type filter
     * @return array Array of records
     */
    public function getRecordsByDomainId(int $domainId, ?string $recordType = null): array;

    /**
     * Get all records from a domain id.
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param int $id Domain ID
     * @param int $rowstart Starting row [default=0]
     * @param int $rowamount Number of rows to return in this query [default=9999]
     * @param string $sortby Column to sort by [default='name']
     * @param string $sortDirection Sort direction [default='ASC']
     * @param bool $fetchComments Whether to fetch record comments [default=false]
     *
     * @return list<RecordRow> One read model per record, empty when the zone has none
     */
    public function getRecordsFromDomainId(int $id, int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array;

    /**
     * Get filtered records from a domain with search capabilities
     *
     * @param int $zone_id The zone ID
     * @param int $row_start Starting row for pagination
     * @param int $row_amount Number of rows per page
     * @param string $sort_by Column to sort by
     * @param string $sort_direction Sort direction (ASC or DESC)
     * @param bool $include_comments Whether to include comments
     * @param string $search_term Optional search term to filter by name or content
     * @param string $type_filter Optional record type filter
     * @param string $content_filter Optional content filter
     * @return array Array of filtered records
     */
    public function getFilteredRecords(
        int $zone_id,
        int $row_start,
        int $row_amount,
        string $sort_by,
        string $sort_direction,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): array;

    /**
     * Get count of filtered records
     *
     * @param int $zone_id The zone ID
     * @param bool $include_comments Whether to include comments in the search
     * @param string $search_term Optional search term to filter by name or content
     * @param string $type_filter Optional record type filter
     * @param string $content_filter Optional content filter
     * @return int Number of filtered records
     */
    public function getFilteredRecordCount(
        int $zone_id,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): int;

    /**
     * Get all records in an RRset (matching domain, name, and type)
     *
     * @param int $domainId The domain/zone ID
     * @param string $name The record name
     * @param string $type Record type (A, AAAA, CNAME, etc.)
     * @return array Array of matching records
     */
    public function getRRSetRecords(int $domainId, string $name, string $type): array;

    /**
     * Count Zone Records for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return int Record count
     */
    public function countZoneRecords(int $zone_id): int;

    /**
     * Get the ID of a newly created record
     *
     * @param int $domainId Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return int|string|null Record ID or null if not found
     */
    public function getNewRecordId(int $domainId, string $name, string $type, string $content): int|string|null;
}
