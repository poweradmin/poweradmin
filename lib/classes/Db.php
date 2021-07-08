<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2016  Poweradmin Development Team
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

namespace Poweradmin;

use Doctrine\DBAL\DriverManager;
use Valitron\Validator;

/**
 * Db class
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2016 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
class Db
{
    /**
     * Internal storage of database-connections
     * @var array
     */
    protected static $connections = array();


    /**
     * Creates database-connection using Doctrine (DBAL)
     *
     * @param array $parameters
     * @param string $name
     * @return bool
     */
    public static function createConnection($parameters, $name = 'default')
    {
        // Create connection
        self::$connections[$name] = DriverManager::getConnection($parameters);

        try {
            self::$connections[$name]->connect();
        } catch(\Exception $e) {
            // Delete connection
            unset(self::$connections[$name]);
        }

        // Return status
        return array_key_exists($name, self::$connections) ? true : false;
    }

    /**
     * Returns connection-object with specified name
     *
     * @param string $name
     * @return \Doctrine\DBAL\Connection
     */
    public static function getConnection($name = 'default')
    {
        // Check if connection with specified name exists
        if (!array_key_exists($name, self::$connections)) {
            // @ToDo: real error-handling
            die(sprintf('<strong>Error:</strong> No connection with name "%s" found!', $name));
        }

        // Return connection
        return self::$connections[$name];
    }

    /**
     * Validates given parameters depending on dbDriver
     *
     * @param array $parameters
     * @return array|bool
     */
    public static function validateParameters($parameters)
    {
        // Get object of validation-library
        $validator = new Validator($parameters);

        // Add validation for dbDriver
        $validator->rule('required', 'driver');
        $validator->rule('in', 'driver', ['pdo_mysql', 'pdo_sqlite', 'pdo_pgsql']);

        // Check if dbDriver is validate
        if ($validator->validate()) {
            // Switch dbDriver
            switch($parameters['driver']) {
                case 'pdo_mysql':
                case 'pdo_pgsql':
                    // Set validation-rules
                    $validator->rule('required', ['host', 'port', 'user', 'password', 'dbname', 'charset']);
                    $validator->rule('numeric', 'port');
                    $validator->rule('in', 'charset', ['latin1', 'utf8']);
                    break;

                case 'pdo_sqlite':
                    // Set validation-rules
                    $validator->rule('required', 'path');
                    $validator->rule('isFile', 'path');
                    break;
            }
        }

        // Return result
        return $validator->validate() ? true : $validator->errors();
    }
}
