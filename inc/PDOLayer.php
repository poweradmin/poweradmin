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
}

?>
