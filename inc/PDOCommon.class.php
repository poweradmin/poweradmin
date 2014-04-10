<?php

/*
  Copyright 2011 Aldo Gonzalez. All rights reserved.

  Redistribution and use in source and binary forms, with or without modification, are
  permitted provided that the following conditions are met:

  1. Redistributions of source code must retain the above copyright notice, this list of
  conditions and the following disclaimer.

  2. Redistributions in binary form must reproduce the above copyright notice, this list
  of conditions and the following disclaimer in the documentation and/or other materials
  provided with the distribution.

  THIS SOFTWARE IS PROVIDED BY Aldo Gonzalez ''AS IS'' AND ANY EXPRESS OR IMPLIED
  WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND
  FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL <copyright HOLDER> OR
  CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
  SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
  ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
  NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
  ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

  The views and conclusions contained in the software and documentation are those of the
  authors and should not be interpreted as representing official policies, either expressed
  or implied, of Aldo Gonzalez.
 */

/**
 * Extends PDO to ensure compatibility with some used functionality from PEAR::MDB2
 *
 * @package     Poweradmin
 * @copyright   2011 Aldo Gonzalez
 * @license     http://opensource.org/licenses/BSD-2-Clause BSD
 */

/**
 * MDB2 over PDO
 */
class PDOStatementCommon {

    /**
     * Internal resource
     * @var mixed
     */
    private $pdoStatement;

    /**
     * Class constructor
     *
     * @param mixed $obj
     */
    public function __construct($obj) {
        $this->pdoStatement = $obj;
    }

    /**
     * Returns the number of rows in a result object
     *
     * @return int
     */
    public function numRows() {
        // NOTE: Doesn't work properly with PDO and SQLite3
        return $this->pdoStatement->rowCount();
    }

    /**
     * Fetch and return a row of data
     *
     * @param int $fetch_style
     * @return mixed
     */
    public function fetch($fetch_style = PDO::FETCH_ASSOC) {
        return $this->pdoStatement->fetch($fetch_style);
    }

    /**
     * Fetch and return a row of data
     *
     * @param int $fetch_style
     * @return mixed
     */
    public function fetchRow($fetch_style = PDO::FETCH_ASSOC) {
        $row = $this->pdoStatement->fetch($fetch_style);
        return $row;
    }

}

/**
 * Implements common PDO methods
 */
class PDOCommon extends PDO {

    /**
     * result limit used in the next query
     * @var int
     */
    private $limit = 0;

    /**
     * result offset used in the next query
     * @var int
     */
    private $from = 0;

    /**
     * PDOCommon constructor
     *
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $driver_options
     * @param boolean $isQuiet
     */
    public function __construct($dsn, $username = '', $password = '', $driver_options = array(), $isQuiet = false) {
        try {
            parent::__construct($dsn, $username, $password, $driver_options);
        } catch (Exception $e) {
            error_log($e->getMessage());
            if ($isQuiet) {
                die();
            } else {
                die("Unable to connect to the database server. " .
                        "Please report the problem to an Administrator.");
            }
        }
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // only allow one statement per query
        // should check that this attribute is only set on
        // mysql databases
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) == "mysql") {
            $this->setAttribute(PDO::MYSQL_ATTR_DIRECT_QUERY, false);
        }
    }

    /**
     * Send a query to the database and return any results
     *
     * @param string $str
     * @return \PDOStatementCommon
     */
    public function query($str) {
        // check if limit has been specified. if so, modify the query
        if (!empty($this->limit)) {
            $str .= " LIMIT " . $this->limit;
            if (!empty($this->from)) {
                $str .= " OFFSET " . $this->from;
            }

            // after a query is executed the limits are reset, so that
            // other queries may be performed with the same object
            $this->limit = 0;
            $this->from = 0;
        }

        try {
            $obj_pdoStatement = parent::query($str);
        } catch (Exception $e) {
            error_log("[* SQL ERROR MESSAGE FOLLOWS:\n" .
                    $e->getTraceAsString() .
                    "\n" . $e->getMessage() .
                    "\nFull SQL Statement:" . $str .
                    "\n*]");
            die("<b>An error occurred while executing the SQL statement. " .
                    "Please contact an Administrator and report the problem.</b>" .
                    "<br /><hr />The following query generated an error:<br />" .
                    "<pre>" .
                    $this->formatSQLforHTML($str) .
                    "\n\n" . $e->getMessage() .
                    "</pre>");
        }

        $obj_pdoStatementCommon = new PDOStatementCommon($obj_pdoStatement);

        return $obj_pdoStatementCommon;
    }

    /**
     * Return an HTML formatted SQL string
     *
     * @param string $str
     * @return string
     */
    protected function formatSQLforHTML($str) {
        $Keyword = array("SELECT ", "WHERE ", " ON ", "AND ", "OR ",
            "FROM ", "LIMIT ", "UNION ",
            "INNER ", "LEFT ", "RIGHT ", "JOIN ", ",",
            "GROUP BY ", "ORDER BY ", "HAVING ");
        foreach ($Keyword as $key => $value) {
            if ($value == ",") {
                $Replace[$key] = "<b>" . $value . "</b>\n";
            } else {
                $Replace[$key] = "\n<b>" . $value . "</b>";
            }
        }

        return str_replace($Keyword, $Replace, $str);
    }

    /**
     * Execute the specified query, fetch the value from the first column of
     * the the first result row
     *
     * @param string $str
     * @return array
     */
    public function queryOne($str) {
        $result = $this->query($str);
        $row = $result->fetch(PDO::FETCH_NUM);

        return $row[0];
    }

    /**
     * Execute the specified query, fetch values from first result row
     *
     * @param string $str
     * @return mixed
     */
    public function queryRow($str) {
        $obj_pdoStatement = parent::query($str);
        return $obj_pdoStatement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Set the range of the next query
     *
     * @param int $limit
     * @param int $from
     */
    public function setLimit($limit, $from = 0) {
        $this->limit = $limit;
        $this->from = $from;
    }

    /**
     * Quotes a string so it can be safely used in a query.
     *
     * @param string $str
     * @return string
     */
    public function escape($str) {
        return $this->quote($str);
    }

}
