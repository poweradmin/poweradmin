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

/* PERMISSIONS */
define("ERR_PERM_SEARCH", _("You do not have the permission to perform searches."));
define("ERR_PERM_ADD_RECORD", _("You do not have the permission to add a record to this zone."));
define("ERR_PERM_EDIT_RECORD", _("You do not have the permission to edit this record."));
define("ERR_PERM_VIEW_RECORD", _("You do not have the permission to view this record."));
define("ERR_PERM_DEL_RECORD", _("You do not have the permission to delete this record."));
define("ERR_PERM_ADD_ZONE_MASTER", _("You do not have the permission to add a master zone."));
define("ERR_PERM_ADD_ZONE_SLAVE", _("You do not have the permission to add a slave zone."));
define("ERR_PERM_DEL_ZONE", _("You do not have the permission to delete a zone."));
define("ERR_PERM_VIEW_COMMENT", _("You do not have the permission to view this comment."));
define("ERR_PERM_EDIT_COMMENT", _("You do not have the permission to edit this comment."));
define("ERR_PERM_DEL_SM", _("You do not have the permission to delete a supermaster."));
define("ERR_PERM_VIEW_ZONE", _("You do not have the permission to view this zone."));
define("ERR_PERM_EDIT_USER", _("You do not have the permission to edit this user."));
define("ERR_PERM_EDIT_PERM_TEMPL", _("You do not have the permission to edit permission templates."));
define("ERR_PERM_DEL_PERM_TEMPL", _("You do not have the permission to delete permission templates."));
define("ERR_PERM_ADD_USER", _("You do not have the permission to add a new user."));
define("ERR_PERM_DEL_USER", _("You do not have the permission to delete this user."));
define("ERR_PERM_EDIT_ZONE_TEMPL", _("You do not have the permission to edit zone templates."));
define("ERR_PERM_DEL_ZONE_TEMPL", _("You do not have the permission to delete zone templates."));
define("ERR_PERM_ADD_ZONE_TEMPL", _("You do not have the permission to add a zone template."));

/* DOMAIN STUFF */
define("ERR_DOMAIN_INVALID", _('This is an invalid zone name.'));
define("ERR_SM_EXISTS", _('There is already a supermaster with this IP address and hostname.'));
define("ERR_DOMAIN_EXISTS", _('There is already a zone with this name.'));

/* USER STUFF */
define("ERR_USER_EXIST", _('Username exist already, please choose another one.'));
define("ERR_USER_NOT_EXIST", _('User does not exist.'));
define("ERR_USER_WRONG_CURRENT_PASS", _('You did not enter the correct current password.'));
define("ERR_USER_MATCH_NEW_PASS", _('The two new password fields do not match.'));
define("ERR_PERM_TEMPL_ASSIGNED", _('This template is assigned to at least one user.'));

/* OTHER */
define("ERR_INV_INPUT", _('Invalid or unexpected input given.'));
define("ERR_INV_ARG", _('Invalid argument(s) given to function %s'));
define("ERR_INV_ARGC", _('Invalid argument(s) given to function %s %s'));
define("ERR_UNKNOWN", _('Unknown error.'));
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

/* DATABASE */
define("ERR_DB_NO_DB_NAME", _('No database name has been set in config.inc.php.'));
define("ERR_DB_NO_DB_HOST", _('No database host has been set in config.inc.php.'));
define("ERR_DB_NO_DB_USER", _('No database username has been set in config.inc.php.'));
define("ERR_DB_NO_DB_PASS", _('No database password has been set in config.inc.php.'));
define("ERR_DB_NO_DB_TYPE", _('No or unknown database type has been set in config.inc.php.'));
define("ERR_DB_NO_DB_FILE", _('No database file has been set in config.inc.php.'));
define("ERR_DB_NO_DB_UPDATE", _('It seems that you forgot to update the database after Poweradmin upgrade to new version.'));
define("ERR_DB_UNK_TYPE", _('Unknown database type.'));

/* DNS */
define("ERR_DNS_CONTENT", _('Your content field doesnt have a legit value.'));
define("ERR_DNS_HOSTNAME", _('Invalid hostname.'));
define("ERR_DNS_HN_INV_CHARS", _('You have invalid characters in your hostname.'));
define("ERR_DNS_HN_DASH", _('A hostname can not start or end with a dash.'));
define("ERR_DNS_HN_LENGTH", _('Given hostname or one of the labels is too short or too long.'));
define("ERR_DNS_HN_SLASH", _('Given hostname has too many slashes.'));
define("ERR_DNS_RR_TYPE", _('Unknown record type.'));
define("ERR_DNS_IP", _('This is not a valid IPv4 or IPv6 address.'));
define("ERR_DNS_IPV6", _('This is not a valid IPv6 address.'));
define("ERR_DNS_IPV4", _('This is not a valid IPv4 address.'));
define("ERR_DNS_CNAME", _('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
define("ERR_DNS_CNAME_EXISTS", _('This is not a valid record. There is already exists a CNAME with this name.'));
define("ERR_DNS_CNAME_UNIQUE", _('This is not a valid CNAME. There is already exists an A, AAAA or CNAME with this name.'));
define("ERR_DNS_CNAME_EMPTY", _('Empty CNAME records are not allowed.'));
define("ERR_DNS_NON_ALIAS_TARGET", _('You can not point a NS or MX record to a CNAME record. Remove or rame the CNAME record first, or take another name.'));
define("ERR_DNS_NS_HNAME", _('NS records must be a hostnames.'));
define("ERR_DNS_MX_PRIO", _('A prio field should be numeric.'));
define("ERR_DNS_SOA_NAME", _('Invalid value for name field of SOA record. It should be the name of the zone.'));
define("ERR_DNS_SOA_MNAME", _('You have an error in the MNAME field of the SOA record.'));
define("ERR_DNS_HINFO_INV_CONTENT", _('Invalid value for content field of HINFO record.'));
define("ERR_DNS_HN_TOO_LONG", _('The hostname is too long.'));
define("ERR_DNS_INV_TLD", _('You are using an invalid top level domain.'));
define("ERR_DNS_INV_TTL", _('Invalid value for TTL field. It should be numeric.'));
define("ERR_DNS_INV_PRIO", _('Invalid value for prio field. It should be numeric.'));
define("ERR_DNS_SRV_NAME", _('Invalid value for name field of SRV record.'));
define("ERR_DNS_SRV_WGHT", _('Invalid value for the priority field of the SRV record.'));
define("ERR_DNS_SRV_PORT", _('Invalid value for the weight field of the SRV record.'));
define("ERR_DNS_SRV_TRGT", _('Invalid SRV target.'));
define("ERR_DNS_PRINTABLE", _('Invalid characters have been used in this record.'));

/* DNSSEC */
define('ERR_EXEC_PDNSSEC', _('Failed to call pdnssec utility.'));
define('ERR_EXEC_PDNSSEC_ADD_ZONE_KEY', _('Failed to add new DNSSEC key.'));
define('ERR_EXEC_PDNSSEC_DISABLE_ZONE', _('Failed to deactivate DNSSEC keys.'));
define('ERR_EXEC_PDNSSEC_SECURE_ZONE', _('Failed to secure zone.'));
define('ERR_EXEC_PDNSSEC_SHOW_ZONE', _('Failed to get DNSSEC key details.'));
define('ERR_EXEC_PDNSSEC_RECTIFY_ZONE', _('Failed to rectify zone.'));
define('ERR_EXEC_PDNSSEC_PRESIGNED_ZONE', _('Failed to change presigned mode'));
define('ERR_PDNSSEC_DEL_ZONE_KEY', _('Failed to delete DNSSEC key.'));

/* GOOD! */
define("SUC_ZONE_ADD", _('Zone has been added successfully.'));
define("SUC_ZONE_DEL", _('Zone has been deleted successfully.'));
define("SUC_ZONES_DEL", _('Zones have been deleted successfully.'));
define("SUC_ZONE_UPD", _('Zone has been updated successfully.'));
define("SUC_ZONES_UPD", _('Zones have been updated successfully.'));
define("SUC_USER_UPD", _('The user has been updated successfully.'));
define("SUC_USER_ADD", _('The user has been created successfully.'));
define("SUC_USER_DEL", _('The user has been deleted successfully.'));
define("SUC_RECORD_UPD", _('The record has been updated successfully.'));
define("SUC_RECORD_DEL", _('The record has been deleted successfully.'));
define("SUC_COMMENT_UPD", _('The comment has been updated successfully.'));
define("SUC_SM_DEL", _('The supermaster has been deleted successfully.'));
define("SUC_SM_ADD", _('The supermaster has been added successfully.'));
define("SUC_PERM_TEMPL_ADD", _('The permission template has been added successfully.'));
define("SUC_PERM_TEMPL_UPD", _('The permission template has been updated successfully.'));
define("SUC_PERM_TEMPL_DEL", _('The permission template has been deleted successfully.'));
define("SUC_ZONE_TEMPL_ADD", _('Zone template has been added successfully.'));
define("SUC_ZONE_TEMPL_UPD", _('Zone template has been updated successfully.'));
define("SUC_ZONE_TEMPL_DEL", _('Zone template has been deleted successfully.'));
define("SUC_EXEC_PDNSSEC_RECTIFY_ZONE", _('Zone has been rectified successfully.'));
define("SUC_EXEC_PDNSSEC_ADD_ZONE_KEY", _('Zone key has been added successfully.'));
define("SUC_EXEC_PDNSSEC_REMOVE_ZONE_KEY", _('Zone key has been deleted successfully.'));
define("SUC_EXEC_PDNSSEC_ACTIVATE_ZONE_KEY", _('Zone key has been successfully activated.'));
define("SUC_EXEC_PDNSSEC_DEACTIVATE_ZONE_KEY", _('Zone key has been successfully deactivated.'));

/** Print error message (toolkit.inc)
 *
 * @param string $msg Error message
 *
 * @return null
 */
function error($msg) {
    echo "     <div class=\"error\">Error: " . $msg . "</div>\n";
}
