<?

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007, 2008  Rejo Zenger <rejo@zenger.nl>
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

// Added next line to enable i18n on following definitions. Not sure
// if this is the best (or at least a proper) location for this. /RZ.
require_once("inc/i18n.inc.php");

/* USER LEVELS */
define("ERR_LEVEL_5", _('You need user level 5 for this operation'));
define("ERR_LEVEL_10", _('You need user level 10 for this operation'));

/* RECORD STUFF */
define("ERR_RECORD_EMPTY_CONTENT", _('Your content field is empty'));
define("ERR_RECORD_ACCESS_DENIED", _('Access denied, you do not have access to that record'));
define("ERR_RECORD_DELETE_TYPE_DENIED", _('You are not allowed to delete %s records'));

/* DOMAIN STUFF */
define("ERR_DOMAIN_INVALID", _('This is an invalid zone name'));

/* USER STUFF */
define("ERR_USER_EXIST", _('Username exist already, please choose another one'));
define("ERR_USER_NOT_EXIST", _('User doesnt exist'));
define("ERR_USER_WRONG_CURRENT_PASS", _('You didnt enter the correct current password'));
define("ERR_USER_MATCH_NEW_PASS", _('The two new password fields do not match'));
define("ERR_USER_EDIT", _('Error editting user'));

/* OTHER */
define("ERR_INV_ARG", _('Invalid argument(s) given to function %s'));
define("ERR_INV_ARGC", _('Invalid argument(s) given to function %s %s'));
define("ERR_UNKNOWN", _('unknown error'));
define("ERR_INV_EMAIL", _('Enter a valid email address'));

/* DNS */
define("ERR_DNS_CONTENT", _('Your content field doesnt have a legit value'));
define("ERR_DNS_HOSTNAME", _('Invalid hostname'));
define("ERR_DNS_RECORDTYPE", _('Invalid record type! You shouldnt even been able to get that here'));
define("ERR_DNS_IPV6", _('This is not a valid IPv6 ip.'));
define("ERR_DNS_IPV4", _('This is not a valid IPv4 ip.'));
define("ERR_DNS_CNAME", _('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
define("ERR_DNS_NS_CNAME", _('You can not point a NS record to a CNAME record. Remove/rename the CNAME record first or take another name.'));
define("ERR_DNS_NS_HNAME", _('IN NS fields must be a hostnames.'));
define("ERR_DNS_MX_CNAME", _('You can not point a MX record to a CNAME record. Remove/rename the CNAME record first or take another name.'));
define("ERR_DNS_MX_PRIO", _('A prio field should be numeric.'));
define("ERR_DNS_SOA_NUMERIC", _('One of your SOA data fields is not numeric!'));
define("ERR_DNS_SOA_NUMERIC_FIELDS", _('You can only have 5 numeric fields'));
define("ERR_DNS_SOA_HOSTNAME", _('The first part of your SOA record does not contain a valid hostname for a DNS Server'));
?>
