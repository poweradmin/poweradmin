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

namespace Poweradmin\Infrastructure\Database;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class TableNameService
{
    private ?string $pdnsDbName;

    public function __construct(ConfigurationManager $config)
    {
        $this->pdnsDbName = $config->get('database', 'pdns_db_name');
    }

    public function getPdnsTable(string $tableName): string
    {
        $pdnsTables = [
            'domains', 'records', 'supermasters', 'comments',
            'domainmetadata', 'cryptokeys', 'tsigkeys'
        ];

        if (!in_array($tableName, $pdnsTables, true)) {
            throw new \InvalidArgumentException("Table name not allowed for prefixing: $tableName");
        }

        return $this->pdnsDbName ? $this->pdnsDbName . '.' . $tableName : $tableName;
    }
}
