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

use Poweradmin\Domain\Service\Dns\RecordLog;

/**
 * One posted editor row that differs from the zone: the normalised row as it
 * will be written and the log holding the stored state it replaces.
 */
final readonly class ZoneEditRowChange
{
    /**
     * @param array<string, mixed> $record The row with its full name restored and disabled normalised to 0/1
     * @param RecordLog $log Prior state already captured; the writer adds the after state
     */
    public function __construct(
        public array $record,
        public RecordLog $log
    ) {
    }

    /**
     * The stored row before the change, as RecordLog captured it. Lacks an "id"
     * when the record no longer resolves.
     *
     * @return array<string, mixed>
     */
    public function before(): array
    {
        return $this->log->getRecordCopy();
    }
}
