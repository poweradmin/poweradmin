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
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/** Print success message (toolkit.inc)
 *
 * @param string $msg Success message
 *
 * @return null
 */
function success(string $msg) {
    if ($msg) {
        echo "     <div class=\"alert alert-success\">" . $msg . "</div>\n";
    } else {
        echo "     <div class=\"alert alert-success\">" . _('Something has been successfully performed. What exactly, however, will remain a mystery.') . "</div>\n";
    }
}

/** Print error message
 *
 * @param string $msg Error message
 * @param string|null $name Offending DNS record name
 *
 * @return null
 */
function error(string $msg, string $name = null)
{
    if ($name == null) {
        echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . "</div>\n";
    } else {
        echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . " (Record: " . $name . ")</b></div>\n";
    }
}
