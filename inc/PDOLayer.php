<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * PDO DB access layer
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
include_once "PDOCommon.class.php";

/**
 * Overrided PEAR class
 */
class PEAR {

    /**
     * Overrided isError method
     */
    public static function isError() {
        
    }

}

/**
 * Fake PDO Extended module
 */
class PDOExtended {

    /**
     * Does several execute() calls on the same statement handle
     *
     * @link http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Extended.html#methodexecuteMultiple
     * @param resource $stmt Statement handle
     * @param array $params numeric array containing the data to insert into the query
     */
    public function executeMultiple($stmt, $params) {
        foreach ($params as $values) {
            $stmt->execute($values);
        }
    }

}

/**
 * PDO access layer
 */
class PDOLayer extends PDOCommon {

    /**
     * Enables/disables debugging
     * @var boolean
     */
    private $debug = false;

    /**
     * Internal storage for queries
     * @var array
     */
    private $queries = array();

    /**
     * Quotes a string
     *
     * @param string $string
     * @param string $paramtype
     * @return string Returns quoted string
     */
    public function quote($string, $paramtype = NULL) {
        if ($paramtype == 'integer') {
            $paramtype = PDO::PARAM_INT;
        } elseif ($paramtype == 'text') {
            $paramtype = PDO::PARAM_STR;
        }
        return parent::quote($string, $paramtype);
    }

    /**
     * Set execution options
     *
     * @param string $option Option name
     * @param int $value Option value
     */
    public function setOption($option, $value) {
        if ($option == 'debug' && $value == 1) {
            $this->debug = true;
        }
    }

    /**
     * Return debug output
     *
     * @param string Debug output
     */
    public function getDebugOutput() {
        echo join("<br>", $this->queries);
    }

    /**
     * Executes SQL query
     *
     * @param string $str SQL query
     * @return PDOStatement
     */
    public function query($str) {
        if ($this->debug) {
            $this->queries[] = $str;
        }

        return parent::query($str);
    }

    /**
     * Dummy method
     */
    public function disconnect() {
        
    }

    /**
     * Load PDO module
     *
     * @param string $name Module name to load
     */
    public function loadModule($name) {
        if ($name == 'Extended') {
            $this->extended = new PDOExtended();
        }
    }

    /**
     * List all tables in the current database
     *
     * @link http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methodlistTables
     */
    public function listTables() {
        // TODO: addapt this function also to pgsql & sqlite

        $tables = array();
        $db_type = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query = '';

        if ($db_type == 'mysql') {
            $query = 'SHOW TABLES';
        } elseif ($db_type == 'pgsql') {
            $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
        } elseif ($db_type == 'sqlite') {
            $query = "SELECT name FROM sqlite_master WHERE type='table'";
        } else {
            die(ERR_DB_UNK_TYPE);
        }

        $result = $this->query($query);
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    /**
     * Create a new table
     *
     * @link http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methodcreateTable
     * @param string $name Name of the table that should be created
     * @param mixed[] $fields Associative array that contains the definition of each field of the new table
     * @param mixed[] $options An associative array of table options
     */
    public function createTable($name, $fields, $options = array()) {
        $db_type = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query_fields = array();

        foreach ($fields as $key => $arr) {
            if ($arr['type'] == 'text' and isset($arr['length'])) {
                $arr['type'] = 'VARCHAR';
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

            $query_fields[] = $line;
        }

        $query = "CREATE TABLE $name (" . implode(', ', $query_fields) . ')';

        if ($db_type == 'mysql' && isset($options['type'])) {
            $query .= ' ENGINE=' . $options['type'];
        }
        $this->exec($query);
    }

    /**
     * Drop an existing table
     *
     * @link http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methoddropTable
     * @param string $name name of the table that should be dropped
     */
    public function dropTable($name) {
        $query = "DROP TABLE $name";
        $this->exec($query);
    }

}
