<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * PDO DB access layer
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Infrastructure\Database;

use PDO;
use PDOStatement;

/**
 * PDO access layer
 */
class PDOLayer extends PDOCommon
{

    /**
     * Enables/disables debugging
     * @var boolean
     */
    private bool $debug = false;

    /**
     * Internal storage for queries
     * @var array
     */
    private array $queries = array();

    /**
     * Set execution options
     *
     * @param string $option Option name
     * @param int $value Option value
     */
    public function setOption(string $option, int $value): void
    {
        if ($option == 'debug' && $value == 1) {
            $this->debug = true;
        }
    }

    /**
     * Return executed queries
     *
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Executes SQL query
     *
     * @param string $query SQL query
     * @param int|null $fetchMode
     * @param mixed ...$fetchModeArgs
     * @return PDOStatement
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
    {
        if ($this->debug) {
            $this->queries[] = $query;
        }

        return parent::query($query);
    }
}
