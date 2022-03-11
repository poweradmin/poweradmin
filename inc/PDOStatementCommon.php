<?php

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
        return $this->pdoStatement->fetch($fetch_style);
    }
}
