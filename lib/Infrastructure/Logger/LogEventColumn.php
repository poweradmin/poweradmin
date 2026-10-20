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

namespace Poweradmin\Infrastructure\Logger;

/**
 * Fits an audit message into the log_*.event columns, which are varchar(2048) on every database.
 */
final class LogEventColumn
{
    // Counted in characters: MySQL utf8mb4 and PostgreSQL both measure varchar length that way.
    public const MAX_LENGTH = 2048;

    private const MARKER = '...';

    public static function fit(mixed $message): string
    {
        $message = (string) $message;
        if (mb_strlen($message, 'UTF-8') <= self::MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_LENGTH - strlen(self::MARKER), 'UTF-8') . self::MARKER;
    }
}
