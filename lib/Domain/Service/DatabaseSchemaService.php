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

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

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
        if (empty($fields)) {
            throw new InvalidArgumentException("Cannot create table '$name' with no fields");
        }

        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query_fields = array();

        foreach ($fields as $key => $arr) {
            // Skip fields that don't have a valid type
            if (!isset($arr['type']) || empty($arr['type'])) {
                continue;
            }

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
            } elseif ($db_type == 'pgsql' && $arr['type'] == 'boolean') {
                // PostgreSQL boolean type doesn't accept length parameter
                $line = $key . ' ' . $arr['type'];
            } else {
                $line = $key . ' ' . $arr['type'] . (isset($arr['length']) ? '(' . $arr['length'] . ')' : '');
            }

            if (isset($arr['notnull']) && $arr['notnull'] && $db_type != 'pgsql' && !isset($arr['autoincrement'])) {
                $line .= ' NOT NULL';
            }

            if ($db_type == 'mysql' && isset($arr['autoincrement'])) {
                $line .= ' AUTO_INCREMENT';
            }

            if (isset($arr['flags']) && $arr['flags'] == 'primary_keynot_null') {
                $line .= ' PRIMARY KEY';
            }

            if (isset($arr['default']) && $arr['default'] != '0') {
                // Quote string defaults for text/varchar fields
                if (in_array($arr['type'], ['text', 'VARCHAR']) && !in_array(strtoupper($arr['default']), ['CURRENT_TIMESTAMP', 'NULL'])) {
                    $line .= ' DEFAULT ' . $this->db->quote($arr['default']);
                } else {
                    $line .= ' DEFAULT ' . $arr['default'];
                }
            }

            $query_fields[] = $line;
        }

        if (empty($query_fields)) {
            throw new InvalidArgumentException("Cannot create table '$name' - no valid fields processed");
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

        try {
            $this->db->exec($query);
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to create table '$name'. SQL: $query. Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute multiple prepared statement operations with different parameter sets
     *
     * @param PDOStatement $stmt Prepared statement
     * @param array $params Array of parameter arrays
     */
    public function executeMultiple(PDOStatement $stmt, array $params): void
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
}
