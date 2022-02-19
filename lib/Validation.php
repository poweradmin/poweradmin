<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

class Validation
{
    /** Validate email address string
     *
     * @param string $address email address string
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_email($address)
    {
        $fields = preg_split("/@/", $address, 2);
        if ((!preg_match("/^[0-9a-z]([-_.]?[0-9a-z])*$/i", $fields[0])) || (!isset($fields[1]) || $fields[1] == '' || !Dns::is_valid_hostname_fqdn($fields[1], 0))) {
            return false;
        }
        return true;
    }

    /** Validate numeric string
     *
     * @param string $string number
     *
     * @return boolean true if number, false otherwise
     */
    public static function is_number($string)
    {
        if (!preg_match("/^[0-9]+$/i", $string)) {
            return false;
        } else {
            return true;
        }
    }
}
