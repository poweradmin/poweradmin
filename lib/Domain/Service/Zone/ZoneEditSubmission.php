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

namespace Poweradmin\Domain\Service\Zone;

/**
 * One submission of the zone editor's record table.
 */
final readonly class ZoneEditSubmission
{
    /**
     * @param list<ZoneEditRow> $rows The rows that arrived in full
     * @param bool $truncated Whether the transport dropped part of the submission (rows or its trailing fields)
     * @param string|null $serial The SOA serial the form was rendered with
     * @param bool $changedRowsOnly Whether the client filtered the submission down to edited rows
     * @param string|null $zoneComment The zone comment, or null when it was not sent
     */
    public function __construct(
        public int $zoneId,
        public string $zoneName,
        public int $userId,
        public string $username,
        public array $rows,
        public bool $truncated,
        public ?string $serial,
        public bool $changedRowsOnly,
        public ?string $zoneComment
    ) {
    }
}
