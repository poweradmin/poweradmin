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

    public function validateOrderBy(string $column, array $allowedColumns): string
    {
        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException("Invalid ORDER BY column: $column");
        }
        
        return $column;
    }

    public function validateDirection(string $direction): string
    {
        $allowedDirections = ['ASC', 'DESC'];
        $direction = strtoupper($direction);
        
        if (!in_array($direction, $allowedDirections, true)) {
            throw new \InvalidArgumentException("Invalid sort direction: $direction");
        }
        
        return $direction;
    }

    public function validateLimit(int $limit, int $maxLimit = 10000): int
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException("LIMIT cannot be negative: $limit");
        }
        
        if ($limit > $maxLimit) {
            throw new \InvalidArgumentException("LIMIT too large: $limit (max: $maxLimit)");
        }
        
        return $limit;
    }

    public function validateOffset(int $offset): int
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("OFFSET cannot be negative: $offset");
        }
        
        return $offset;
    }
}
