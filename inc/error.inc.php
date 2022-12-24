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

/* OTHER */
define("ERR_INV_INPUT", _('Invalid or unexpected input given.'));
define("ERR_INV_ARG", _('Invalid argument(s) given to function %s'));
define("ERR_INV_ARGC", _('Invalid argument(s) given to function %s %s'));
define("ERR_UNKNOWN", _('Unknown error.'));
define("ERR_INV_USERNAME", _('Enter a valid user name.'));
define("ERR_INV_EMAIL", _('Enter a valid email address.'));
define("ERR_ZONE_NOT_EXIST", _('There is no zone with this ID.'));
define("ERR_REVERS_ZONE_NOT_EXIST", _('There is no matching reverse-zone for: %s.'));
define("ERR_ZONE_TEMPL_NOT_EXIST", _('There is no zone template with this ID.'));
define("ERR_INSTALL_DIR_EXISTS", _('The <a href="install/">install/</a> directory exists, you must remove it first before proceeding.'));
define("ERR_ZONE_TEMPL_EXIST", _('Zone template with this name already exists, please choose another one.'));
define("ERR_ZONE_TEMPL_IS_EMPTY", _('Template name can\'t be an empty string.'));
define("ERR_DEFAULT_CRYPTOKEY_USED", _('Default session encryption key is used, please set it in your configuration file.'));
define("ERR_LOCALE_FAILURE", _('Failed to set locale. Selected locale may be unsupported on this system. Please contact your administrator.'));
define("ERR_ZONE_UPD", _('Zone has not been updated successfully.'));
define("ERR_EXEC_NOT_ALLOWED", _('Failed to call function exec. Make sure that exec is not listed in disable_functions at php.ini'));
define("ERR_ZONE_MUST_HAVE_OWNER", _('There must be at least one owner for a zone.'));
define("ERR_ZONE_OWNER_EXISTS", _('The selected user already owns the zone.'));
define("ERR_ZONES_ADD", _('Some zone(s) could not be added.'));

/** Print error message
 *
 * @param string $msg Error message
 * @param string|null $name Offending DNS record name
 *
 * @return null
 */
function error(string $msg, string $name = null) {
        if ($name == null) {
                echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . "</div>\n";
        } else {
                echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . " (Record: " . $name . ")</b></div>\n";
        }
}

