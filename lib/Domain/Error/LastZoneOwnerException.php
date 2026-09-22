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

namespace Poweradmin\Domain\Error;

use InvalidArgumentException;

/**
 * Thrown when a write would leave zones without an owner, carrying the zones it
 * would strand so the caller can name them.
 *
 * Extends InvalidArgumentException so existing callers that catch that type keep
 * working, while controllers can catch this specific type first and word the
 * refusal for their own audience.
 */
class LastZoneOwnerException extends InvalidArgumentException
{
    private const LISTED = 10;

    /** @var array<int, string> Zone id => zone name */
    private array $zones;

    /**
     * @param array<int, string> $zones Zone id => zone name
     */
    public function __construct(array $zones, string $message = '')
    {
        $this->zones = $zones;

        parent::__construct($message);
    }

    /**
     * @return array<int, string> Zone id => zone name
     */
    public function getZones(): array
    {
        return $this->zones;
    }

    /**
     * The zone names for a message, shortened when there are many of them.
     */
    public function getZoneList(): string
    {
        $names = array_slice(array_values($this->zones), 0, self::LISTED);
        $list = implode(', ', $names);

        return count($this->zones) > self::LISTED ? $list . ', ...' : $list;
    }
}
