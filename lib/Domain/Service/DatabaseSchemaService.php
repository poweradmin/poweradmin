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

namespace Poweradmin\Domain\Service;

use PDO;

class DatabaseSchemaService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * List all tables in the current database
     */
    public function listTables(): array
    {
        $tables = array();
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($db_type == 'mysql') {
            $query = 'SHOW TABLES';
        } elseif ($db_type == 'pgsql') {
            $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
        } elseif ($db_type == 'sqlite') {
            $query = "SELECT name FROM sqlite_master WHERE type='table'";
        } else {
            die(_('Unknown database type.'));
        }

        $result = $this->db->query($query);
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    /**
     * Create a new table
     *
     * @param string $name Name of the table that should be created
     * @param array $fields Associative array that contains the definition of each field of the new table
     * @param array $options An associative array of table options
     */
    public function createTable(string $name, array $fields, array $options = array()): void
    {
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query_fields = array();

        foreach ($fields as $key => $arr) {
            if ($arr['type'] == 'text' and isset($arr['length'])) {
                $arr['type'] = 'VARCHAR';
            }

            // Convert boolean type to TINYINT(1) for MySQL/MariaDB
            if (($db_type == 'mysql') && $arr['type'] == 'boolean') {
                $arr['type'] = 'TINYINT';
                $arr['length'] = 1;
            }

            if ($db_type == 'pgsql' && isset($arr['autoincrement'])) {
                $line = $key . ' SERIAL';
            } elseif ($db_type == 'pgsql' && $arr['type'] == 'integer') {
                $line = $key . ' ' . $arr['type'];
            } else {
                $line = $key . ' ' . $arr['type'] . (isset($arr['length']) ? '(' . $arr['length'] . ')' : '');
            }

            if ($arr['notnull'] && $db_type != 'pgsql' && !isset($arr['autoincrement'])) {
                $line .= ' NOT NULL';
            }

            if ($db_type == 'mysql' && isset($arr['autoincrement'])) {
                $line .= ' AUTO_INCREMENT';
            }

            if ($arr['flags'] == 'primary_keynot_null') {
                $line .= ' PRIMARY KEY';
            }

            if (isset($arr['default']) && $arr['default'] != '0') {
                $line .= ' DEFAULT ' . $arr['default'];
            }

            $query_fields[] = $line;
        }

        $query = "CREATE TABLE $name (" . implode(', ', $query_fields) . ')';

        if ($db_type == 'mysql') {
            if (isset($options['type'])) {
                $query .= ' ENGINE=' . $options['type'];
            }

            if (isset($options['charset'])) {
                $query .= ' DEFAULT CHARSET=' . $options['charset'];
            }

            if (isset($options['collation'])) {
                $query .= ' COLLATE=' . $options['collation'];
            }
        }

        $this->db->exec($query);
    }

    /**
     * Execute multiple prepared statement operations with different parameter sets
     *
     * @param \PDOStatement $stmt Prepared statement
     * @param array $params Array of parameter arrays
     */
    public function executeMultiple(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $values) {
            $stmt->execute($values);
        }
    }

    /**
     * Drop an existing table
     *
     * @param string $name name of the table that should be dropped
     */
    public function dropTable(string $name): void
    {
        $query = "DROP TABLE $name";
        $this->db->exec($query);
    }

    /**
     * Check if the old migrations table with 'apply_time' column exists
     */
    public function hasOldMigrationsTable(): bool
    {
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        switch ($db_type) {
            case 'mysql':
                $query = $this->db->query("SHOW COLUMNS FROM `migrations` LIKE 'apply_time'");
                break;
            case 'pgsql':
                $query = $this->db->query("SELECT column_name FROM information_schema.columns WHERE table_name='migrations' AND column_name='apply_time'");
                break;
            case 'sqlite':
                $query = $this->db->query("PRAGMA table_info(migrations)");
                $columns = $query->fetchAll();
                foreach ($columns as $column) {
                    if ($column['name'] === 'apply_time') {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }

        $result = $query->fetch();
        return (bool)$result;
    }
}
