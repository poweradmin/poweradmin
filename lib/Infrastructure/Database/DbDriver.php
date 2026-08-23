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

namespace Poweradmin\Infrastructure\Database;

/**
 * Supported PDO drivers, as accepted by the `database.type` setting.
 *
 * `mysql` and `mysqli` are two spellings of the same backend. Every dispatch
 * site has to honour both, and forgetting one silently takes the wrong branch,
 * so prefer isMysqlFamily() over comparing the value directly.
 */
enum DbDriver: string
{
    case MYSQL = 'mysql';
    case MYSQLI = 'mysqli';
    case PGSQL = 'pgsql';
    case SQLITE = 'sqlite';

    /**
     * Whether a string names a supported driver.
     */
    public static function isValid(string $driver): bool
    {
        return self::tryFrom($driver) !== null;
    }

    /**
     * All accepted driver strings, for config validation and allowlists.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }

    /**
     * Whether this is MySQL or MariaDB under either spelling.
     */
    public function isMysqlFamily(): bool
    {
        return $this === self::MYSQL || $this === self::MYSQLI;
    }

    /**
     * Only MySQL can host the PowerDNS tables in a separate database.
     */
    public function supportsSeparatePdnsDb(): bool
    {
        return $this->isMysqlFamily();
    }
}
