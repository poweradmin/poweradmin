<?php

/**
 * MDB2 over PDO
 */
class PDOStatementCommon extends PDOStatement {

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
     * @param int $mode
     * @return mixed
     */
    #[ReturnTypeWillChange]
    public function fetch($mode = PDO::FETCH_ASSOC, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0) {
        return $this->pdoStatement->fetch($mode);
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
