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
 * One row of the zone editor's record table as the caller wants it: the
 * record it refers to and the values to store, already decoded from whatever
 * form or request carried them.
 */
final readonly class ZoneEditRow
{
    /**
     * @param int|string $rid The record id (a string on the API backend, where ids are encoded)
     * @param string $name The name as typed; the editor restores the zone suffix
     * @param string|null $comment The record comment, or null when the caller did not send one
     */
    public function __construct(
        public int|string $rid,
        public string $name,
        public string $type,
        public string $content,
        public int $ttl,
        public int $prio,
        public bool $disabled,
        public ?string $comment
    ) {
    }
}
