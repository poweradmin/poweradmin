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
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Implements common PDO methods
 */
class PDOCommon extends PDO
{
    /**
     * Debug mode flag
     * @var bool
     */
    protected bool $debug = false;

    /**
     * Storage for executed queries
     * @var array
     */
    protected array $queries = [];


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
            // Use MessageService for consistent error display
            require_once dirname(__DIR__, 3) . '/lib/Infrastructure/Service/MessageService.php';
            $messageService = new MessageService();
            $messageService->displayDirectSystemError("Unable to connect to the database server. Please check your database configuration.");
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
        if ($this->debug) {
            $this->queries[] = $query;
        }

        try {
            $obj_pdoStatement = parent::query($query);
        } catch (Exception $e) {
            error_log("[* SQL ERROR MESSAGE FOLLOWS:\n" .
                $e->getTraceAsString() .
                "\n" . $e->getMessage() .
                "\nFull SQL Statement:" . $query .
                "\n*]");

            // Use MessageService for consistent error display
            require_once dirname(__DIR__, 3) . '/lib/Infrastructure/Service/MessageService.php';
            $messageService = new MessageService();
            $messageService->displayDirectSystemError("An error occurred while executing the SQL statement. Please check the error logs for details.");
        }

        // Initialize PDOStatement variable if not set
        $obj_pdoStatement = $obj_pdoStatement ?? null;
        return $obj_pdoStatement;
    }

    /**
     * Prepare a statement for execution and return a statement object
     *
     * @param string $query
     * @param array $options
     * @return PDOStatement|false
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->debug) {
            $this->queries[] = $query;
        }

        return parent::prepare($query, $options);
    }

    /**
     * Execute a statement
     *
     * @param string $statement
     * @return int|false
     */
    public function exec(string $statement): int|false
    {
        if ($this->debug) {
            $this->queries[] = $statement;
        }

        return parent::exec($statement);
    }

    /**
     * Enable/disable debug mode
     *
     * @param bool $debug
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Get all executed queries
     *
     * @return array
     */
    public function getQueries(): array
    {
        return $this->queries;
    }
}
