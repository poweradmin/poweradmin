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
 * Extends PDO to add error handling and other functionality
 *
 * @package     Poweradmin
 * @copyright   2011 Aldo Gonzalez
 * @license     http://opensource.org/licenses/BSD-2-Clause BSD
 */

namespace Poweradmin\Infrastructure\Database;

use Exception;
use PDO;
use PDOStatement;

/**
 * Implements common PDO methods
 */
class PDOCommon extends PDO
{

    /**
     * result limit used in the next query
     * @var int
     */
    private int $limit = 0;

    /**
     * result offset used in the next query
     * @var int
     */
    private int $from = 0;

    /**
     * PDOCommon constructor
     *
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $driver_options
     */
    public function __construct(string $dsn, $username = '', $password = '', $driver_options = array())
    {
        try {
            parent::__construct($dsn, $username, $password, $driver_options);
        } catch (Exception $e) {
            error_log($e->getMessage());
            die("Unable to connect to the database server.");
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
     * @param string $query
     * @param int|null $fetchMode
     * @param mixed ...$fetchModeArgs
     * @return PDOStatement
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
    {
        // check if limit has been specified. if so, modify the query
        if (!empty($this->limit)) {
            $query .= " LIMIT " . $this->limit;
            if (!empty($this->from)) {
                $query .= " OFFSET " . $this->from;
            }

            // after a query is executed the limits are reset, so that
            // other queries may be performed with the same object
            $this->limit = 0;
            $this->from = 0;
        }

        try {
            $obj_pdoStatement = parent::query($query);
        } catch (Exception $e) {
            error_log("[* SQL ERROR MESSAGE FOLLOWS:\n" .
                $e->getTraceAsString() .
                "\n" . $e->getMessage() .
                "\nFull SQL Statement:" . $query .
                "\n*]");
            die("An error occurred while executing the SQL statement.");
        }

        return $obj_pdoStatement;
    }

    /**
     * Return an HTML formatted SQL string
     *
     * @param string $str
     * @return string
     */
    protected function formatSQLforHTML(string $str): string
    {
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
     * the first result row
     *
     * @param string $str
     * @return mixed
     */
    public function queryOne(string $str): mixed
    {
        $result = $this->query($str);
        $row = $result->fetch(PDO::FETCH_NUM);
        if (is_bool($row)) {
            return $row;
        }
        return $row[0];
    }

    /**
     * Execute the specified query, fetch values from first result row
     *
     * @param string $str
     * @return mixed
     */
    public function queryRow(string $str): mixed
    {
        $obj_pdoStatement = parent::query($str);
        return $obj_pdoStatement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Set the range of the next query
     *
     * @param int $limit
     * @param int $from
     */
    public function setLimit(int $limit, int $from = 0): void
    {
        $this->limit = $limit;
        $this->from = $from;
    }
}
