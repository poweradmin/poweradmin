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

use Phinx\Migration\AbstractMigration;

final class UpdateContentColumnInZoneTemplRecords extends AbstractMigration
{
    public function change(): void
    {
        $adapter = $this->getAdapter();
        $adapterType = $adapter->getAdapterType();
        $table = $this->table('zone_templ_records');

        // Modify the column based on the database type
        switch ($adapterType) {
            case 'pgsql':
            case 'mysql':
                $table->changeColumn('content', 'string', [
                    'limit' => 2048,
                    'null' => false
                ])->update();
                break;

            case 'sqlite':
                $table->changeColumn('content', 'string', [
                    'null' => false
                ])->update();
                break;

            default:
                throw new RuntimeException("Unsupported database adapter: $adapterType");
        }
    }
}