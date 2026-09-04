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

            if ($db_type == 'mysql' && isset($arr['charset'])) {
                $line .= ' CHARACTER SET ' . $arr['charset'];
            }

            // Per-column collation (e.g. utf8mb4_bin for case-sensitive keys); MySQL infers the charset from it.
            if ($db_type == 'mysql' && isset($arr['collation'])) {
                $line .= ' COLLATE ' . $arr['collation'];
            }

            // Autoincrement is excluded because pgsql SERIAL and mysql AUTO_INCREMENT already imply NOT NULL.
            if (isset($arr['notnull']) && $arr['notnull'] && !isset($arr['autoincrement'])) {
                $line .= ' NOT NULL';
            }

            if ($db_type == 'mysql' && isset($arr['autoincrement'])) {
                $line .= ' AUTO_INCREMENT';
            }

            if (isset($arr['flags']) && $arr['flags'] == 'primary_keynot_null') {
                $line .= ' PRIMARY KEY';
            }

            $hasDefault = isset($arr['default'])
                && ($arr['default'] != '0' || !empty($arr['emit_default']));
            if ($hasDefault) {
                if ($db_type == 'pgsql' && $arr['type'] == 'boolean') {
                    // PostgreSQL rejects integer literals as boolean defaults; emit true/false.
                    $line .= ' DEFAULT ' . ($arr['default'] ? 'true' : 'false');
                } elseif (in_array($arr['type'], ['text', 'VARCHAR']) && !in_array(strtoupper($arr['default']), ['CURRENT_TIMESTAMP', 'NULL'])) {
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
     * Create the secondary indexes declared for a table.
     *
     * Accepts both structure-array shapes: nested (`['fields' => ['col' => []], 'type' => 'unique']`)
     * and flat (`['col1', 'col2', 'unique' => true]`). On PostgreSQL/SQLite index names are
     * schema-global, so a name that doesn't already carry the table name is prefixed with it to
     * avoid collisions between the generic names the structure array reuses across tables.
     *
     * @param string $tableName
     * @param array $indexes Map of index name => definition
     */
    public function createIndexes(string $tableName, array $indexes): void
    {
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $namesAreGlobal = in_array($db_type, ['pgsql', 'sqlite'], true);

        foreach ($indexes as $indexName => $definition) {
            $definition = (array)$definition;
            $unique = !empty($definition['unique']) || (($definition['type'] ?? null) === 'unique');

            if (isset($definition['fields']) && is_array($definition['fields'])) {
                $columns = array_keys($definition['fields']);
            } else {
                $columns = array_values(array_filter(
                    $definition,
                    static fn($value, $key) => is_int($key) && is_string($value),
                    ARRAY_FILTER_USE_BOTH
                ));
            }

            if (empty($columns)) {
                continue;
            }

            $emitName = ($namesAreGlobal && !str_contains($indexName, $tableName))
                ? $tableName . '_' . $indexName
                : $indexName;

            $uniqueClause = $unique ? 'UNIQUE ' : '';
            $query = "CREATE {$uniqueClause}INDEX $emitName ON $tableName (" . implode(', ', $columns) . ')';

            try {
                $this->db->exec($query);
            } catch (PDOException $e) {
                // Index may already exist on reinstall; a duplicate is not fatal.
            }
        }
    }

    /**
     * Add the foreign keys declared for a table via ALTER TABLE. Call after every table exists.
     *
     * SQLite is skipped: it can only declare foreign keys inline at CREATE TABLE time (no
     * ALTER TABLE ADD CONSTRAINT), matching the installer's long-standing behavior there.
     *
     * @param string $tableName
     * @param array $foreignKeys Map of constraint name => ['table' => ref, 'fields' => [local => ref], 'ondelete'?, 'onupdate'?]
     */
    public function createForeignKeys(string $tableName, array $foreignKeys): void
    {
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($db_type === 'sqlite') {
            return;
        }

        foreach ($foreignKeys as $constraintName => $definition) {
            if (empty($definition['table']) || empty($definition['fields']) || !is_array($definition['fields'])) {
                continue;
            }

            $referencedTable = (string)$definition['table'];
            $localColumns = implode(', ', array_keys($definition['fields']));
            $referencedColumns = implode(', ', array_values($definition['fields']));
            $query = "ALTER TABLE $tableName ADD CONSTRAINT $constraintName "
                . "FOREIGN KEY ($localColumns) REFERENCES $referencedTable ($referencedColumns)";

            if (!empty($definition['ondelete'])) {
                $query .= ' ON DELETE ' . $definition['ondelete'];
            }
            if (!empty($definition['onupdate'])) {
                $query .= ' ON UPDATE ' . $definition['onupdate'];
            }

            try {
                $this->db->exec($query);
            } catch (PDOException $e) {
                // Constraint may already exist on reinstall; a duplicate is not fatal.
            }
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
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        // PostgreSQL refuses to drop a table other tables still FK-reference; CASCADE drops the
        // dependent constraints (not the referencing tables) so a reinstall can replace users et al.
        $query = $db_type === 'pgsql' ? "DROP TABLE $name CASCADE" : "DROP TABLE $name";
        $this->db->exec($query);
    }
}
