<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2012  Poweradmin Development Team
 *      <https://www.poweradmin.org/trac/wiki/Credits>
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

include_once "PDOCommon.class.php";

class PEAR {
	public function isError() {
	}
}

class PDOExtended {

    // http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Extended.html#methodexecuteMultiple
    public function executeMultiple( $stmt, $params) {
        foreach($params as $values) {
            $stmt->execute($values);
        }
    }

}

class PDOLayer extends PDOCommon {
	private $debug = false;
	private $queries = array();

	public function quote($str, $type) {
		if ($type == 'integer') {
			$type = PDO::PARAM_INT;
		} elseif ($type == 'text') {
			$type = PDO::PARAM_STR;
		}
		return parent::quote($str, $type); 
	}

	public function setOption($option, $value) {
		if ($option == 'debug' && $value == 1) {
			$this->debug = true;
		}
	}

	public function getDebugOutput() {
		echo join("<br>", $this->queries);
	}

	public function query($str) {
		if ($this->debug) {
			$this->queries[] = $str;
		}

		return parent::query($str);
	}

	public function disconnect() {
	}

    // http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methodcreateTable
    public function loadModule($name) {
        if ($name == 'Extended') {
            $this->extended = new PDOExtended();
        }
    }

    // http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methodlistTables
    public function listTables() {
        // TODO: addapt this function also to pgsql & sqlite

        $tables = array();
        $db_type = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query = '';

        if ($db_type == 'mysql') {
            $query = 'SHOW TABLES';
        } elseif ($db_type == 'pgsql') {
            $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
        } else {
            die(ERR_DB_UNK_TYPE);
        }

        $result = $this->query($query);
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    // http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methodcreateTable
    public function createTable($name, $fields, $options = array()) {
        $db_type = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
        $query_fields = array();

        foreach ($fields as $key => $arr) {
            if ($arr['type'] == 'text' and isset($arr['length'])) {
                $arr['type'] = 'VARCHAR';
            }

            if ($db_type == 'pgsql' && isset($arr['autoincrement'])) {
                $line = $key.' SERIAL';
            } elseif ($db_type == 'pgsql' && $arr['type'] == 'integer') {
                $line = $key.' '.$arr['type'];
            } else {
                $line = $key.' '.$arr['type'].'('.$arr['length'].')';
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
            $query .= ' ENGINE='.$options['type'];
        }
        $this->exec($query);
    }

    // http://pear.php.net/package/MDB2/docs/2.5.0b3/MDB2/MDB2_Driver_Manager_Common.html#methoddropTable
    public function dropTable($name) {
        $query = "DROP TABLE $name";
        $this->exec($query);
    }

}

?>
