<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2011  Poweradmin Development Team 
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

class PEAR {
	public function isError() {
		# FIXME: implement checking for error
	}
}

class PDOStatementCommon {
	private $pdoStatement;
	
	public function __construct($obj) {
		$this->pdoStatement = $obj;
	}

	public function numRows() {
		return $this->pdoStatement->rowCount();
	}

	public function fetch() {
		return $this->pdoStatement->fetch();
	}

	public function fetchRow() {
		$row = $this->pdoStatement->fetch();
		return $row;
	}
}

class PDOLayer extends PDO {
	private $db;

	public function __constructor($dsn, $db_user, $db_pass) {
		$this->db = new PDO($dsn, $db_user, $db_pass);	
	}

	public function query($str) {
		if (!empty($this->limit)) {
			$str .= " LIMIT ".$this->limit;
		}

		$obj_pdoStatement = parent::query($str);
		$obj_pdoStatementCommon = new PDOStatementCommon($obj_pdoStatement);
		return $obj_pdoStatementCommon;
	}

	public function quote($str, $type) {
		if ($type == 'integer') {
			$type = PDO::PARAM_INT;
		} elseif ($type == 'text') {
			$type = PDO::PARAM_STR;
		}
		return parent::quote($str, $type); 
	}

	public function queryOne($str) {
		$result = $this->query($str);
		$row = $result->fetch();
		return $row[0];
	}

	public function queryRow($str) {
		$obj_pdoStatement = parent::query($str);
		$row = $obj_pdoStatement->fetch();
		return $row;
	}

	public function setLimit($limit) {
		$this->limit = $limit;
	}

	public function lastInsertId($table, $field) {
		return parent::lastInsertId(); 
	}

	public function disconnect() {
	}
}

?>
