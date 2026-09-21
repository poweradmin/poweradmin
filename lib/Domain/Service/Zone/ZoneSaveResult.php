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

use Poweradmin\Domain\Enum\ZoneSaveOutcome;

/**
 * How a zone editor save ended, with what the page needs to report it and,
 * for a refused stale form, the rows to put back in front of the operator.
 */
final readonly class ZoneSaveResult
{
    /**
     * @param bool $serialBumped Whether the SOA serial was incremented
     * @param bool $truncated max_input_vars dropped part of the post
     * @param list<string> $errors Reasons for the rows that failed to write
     * @param array<int|string, mixed> $rejectedRecords Rows to restore after a serial conflict
     */
    public function __construct(
        public ZoneSaveOutcome $outcome,
        public bool $serialBumped = false,
        public bool $truncated = false,
        public array $errors = [],
        public array $rejectedRecords = [],
        public ?string $rejectedZoneComment = null
    ) {
    }
}
