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

   Extends PDO to ensure compatibility with some used functionality
   from PEAR::MDB2
*/

class PDOStatementCommon {
    private $pdoStatement;

    public function __construct($obj)
    {
        $this->pdoStatement = $obj;
    }

    /* wrapper for MDB2 */
    public function numRows()
    {
        return $this->pdoStatement->rowCount();
    }

    public function fetch($fetch_style = PDO::FETCH_ASSOC) {
        return $this->pdoStatement->fetch($fetch_style);
    }

    /* wrapper for MDB2 */
    public function fetchRow($fetch_style = PDO::FETCH_ASSOC) {
        $row = $this->pdoStatement->fetch($fetch_style);
        return $row;
    }
}

class PDOCommon extends PDO {
    private $limit = 0;
    private $from = 0;

    public function __construct($dsn, $username='', $password='',
                                $driver_options=array(), $isQuiet = false)
    {
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
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME) == "mysql") {
        	$this->setAttribute(PDO::MYSQL_ATTR_DIRECT_QUERY, false);
        }
    }

    public function query($str)
    {
        // check if limit has been specified. if so, modify the query
        if (!empty($this->limit)) {
            $limitRange = $this->limit;
            if (!empty($this->from)) {
                $limitRange = $limitRange." OFFSET ".$this->from;
            }
            $str .= " LIMIT ".$limitRange;

            // after a query is executed the limits are reset, so that
            // other queries may be performed with the same object
            $this->limit = 0;
            $this->from = 0;
        }

        try {
            $obj_pdoStatement = parent::query($str);
        } catch (Exception $e) {
            error_log("[* SQL ERROR MESSAGE FOLLOWS:\n".
                      $e->getTraceAsString().
                      "\n".$e->getMessage().
                      "\nFull SQL Statement:".$str.
                      "\n*]");
            die("<b>An error occurred while executing the SQL statement. " .
                "Please contact an Administrator and report the problem.</b>".
                "<br /><hr />The following query generated an error:<br />".
                "<pre>".
                $this->formatSQLforHTML($str).
                "\n\n".$e->getMessage().
                "</pre>");
        }

        $obj_pdoStatementCommon = new PDOStatementCommon($obj_pdoStatement);

        return $obj_pdoStatementCommon;
    }

    /* returns an HTML formatted SQL string */
    protected function formatSQLforHTML($str)
    {
        $Keyword = array("SELECT ", "WHERE ", " ON ", "AND ", "OR ",
                         "FROM ", "LIMIT ", "UNION ",
                         "INNER ", "LEFT ", "RIGHT ", "JOIN ", ",",
                         "GROUP BY ", "ORDER BY ", "HAVING ");
        foreach($Keyword as $key => $value) {
            if ($value == ",") {
                $Replace[$key] = "<b>".$value."</b>\n";
            } else {
                $Replace[$key] = "\n<b>".$value."</b>";
            }
        }

        return str_replace($Keyword, $Replace, $str);
    }

    /* wrapper for MDB2 */
    public function queryOne($str)
    {
        $result = $this->query($str);

        $row = $result->fetch(PDO::FETCH_NUM);

        return $row[0];
    }

    /* wrapper for MDB2 */
    public function queryRow($str)
    {
        $obj_pdoStatement = parent::query($str);

        $row = $obj_pdoStatement->fetch(PDO::FETCH_ASSOC);
        return $row;
    }

    /* wrapper for MDB2 */
    public function setLimit($limit, $from=0)
    {
        $this->limit = $limit;
        $this->from = $from;
    }

    /* wrapper for MDB2 */
    public function escape($str)
    {
        return $this->quote($str);
    }
}
?>
