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
 * DNSSEC functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Execute PDNSSEC rectify-zone command for Domain ID
 *
 * If a Domain is dnssec enabled, or uses features as
 * e.g. ALSO-NOTIFY, ALLOW-AXFR-FROM, TSIG-ALLOW-AXFR
 * following has to be executed
 * pdnssec rectify-zone $domain
 *
 * @param int $domain_id Domain ID
 *
 * @return boolean true on success, false on failure or unnecessary
 */
function do_rectify_zone($domain_id) {
    global $db;
    global $pdnssec_command;

    $output = array();

    /* if pdnssec_command is set we perform ``pdnssec rectify-zone $domain`` on all zones,
     * as pdns needs the "auth" column for all zones if dnssec is enabled
     *
     * If there is any entry at domainmetadata table for this domain,
     * it is an error if pdnssec_command is not set */
    $query = "SELECT COUNT(id) FROM domainmetadata WHERE domain_id = " . $db->quote($domain_id, 'integer');
    $count = $db->queryOne($query);

    if (PEAR::isError($count)) {
        error($count->getMessage());
        return false;
    }

    if (isset($pdnssec_command)) {
        $domain = get_zone_name_from_id($domain_id);
        $command = $pdnssec_command . " rectify-zone " . $domain;

        if (!function_exists('exec')) {
            error(ERR_EXEC_NOT_ALLOWED);
            return false;
        }

        if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
            error(ERR_EXEC_PDNSSEC);
            return false;
        }

        exec($command, $output, $return_code);
        if ($return_code != 0) {
            /* if rectify-zone failed: display error */
            error(ERR_EXEC_PDNSSEC_RECTIFY_ZONE);
            return false;
        }

        return true;
    } else if ($count >= 1) {
        error(ERR_EXEC_PDNSSEC);
        return false;
    } else {
        /* no rectify-zone has to be done or command is not
         * configured in inc/config.inc.php */
        return false;
    }
}

/** Execute PDNSSEC secure-zone command for Domain Name
 *
 * @param string $domain_name Domain Name
 *
 * @return boolean true on success, false on failure or unnecessary
 */
function do_secure_zone($domain_name) {
    global $pdnssec_command;

    if (!function_exists('exec')) {
        error(ERR_EXEC_NOT_ALLOWED);
        return false;
    }

    if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
        error(ERR_EXEC_PDNSSEC);
        return false;
    }

    $command = $pdnssec_command . " secure-zone " . $domain_name;
    exec($command, $output, $return_code);

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SECURE_ZONE);
        return false;
    }

    return true;
}
