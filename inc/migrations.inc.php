<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
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

/**
 * Migration functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Check if given migration exists
 *
 * @param resource $db DB link
 * @param string $file_name Migration file name
 *
 * @return boolean true on success, false on failure
 */
function migration_exists($db, $file_name) {
    $query = "SELECT COUNT(version) FROM migrations WHERE version = " . $db->quote($file_name, 'text');
    $count = $db->queryOne($query);
    if ($count == 0) {
        return false;
    }

    return true;
}

/** Save migration status to database
 *
 * @param resource $db DB link
 * @param string $file_name Migration file name
 *
 * @return boolean true on success, false on failure
 */
function migration_save($db, $file_name) {
    $query = "INSERT INTO migrations (version, apply_time) VALUES(" . $db->quote($file_name, 'text') . "," . $db->quote(time(), 'text') . ")";
    return $db->query($query);
}

/** Get newline symbol depending on environment
 *
 * @return string newline for run environment
 */
function migration_get_environment_newline() {
    $new_line = '<br/>';
    if (php_sapi_name() == 'cli') {
        $new_line = PHP_EOF;
    }
    return $new_line;
}

/** Display given message
 * 
 * @param string $msg Message that needs to be dispalyed
 */
function migration_message($msg) {
    $new_line = migration_get_environment_newline();
    echo $msg.$new_line;
}
