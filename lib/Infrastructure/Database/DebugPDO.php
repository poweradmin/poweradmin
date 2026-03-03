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

use PDO;
use PDOStatement;

/**
 * PDO subclass that records executed queries for debug display.
 *
 * Only instantiated when database.debug is enabled. All consumers
 * type-hint PDO; the footer uses method_exists() to access getQueries().
 */
class DebugPDO extends PDO
{
    /** @var array<string> */
    private array $queries = [];

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::query($query);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;
        return parent::exec($statement);
    }

    /**
     * @return array<string>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }
}
