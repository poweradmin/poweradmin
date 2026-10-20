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

namespace TestHelpers;

use PDO;

/** Fails the allocator's marker lock like a deadlock victim, or with another error every time. */
class FlakyMarkerLockPdo extends PDO
{
    public int $deadlocks = 0;
    public ?string $alwaysFail = null;
    public int $attempts = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT setting_value FROM app_settings')) {
            $this->attempts++;
            if ($this->alwaysFail !== null) {
                throw new \PDOException($this->alwaysFail);
            }
            if ($this->deadlocks-- > 0) {
                $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
                $e->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw $e;
            }
        }

        return parent::prepare($query, $options);
    }
}
