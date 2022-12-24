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
define("ERR_DNS_CNAME_UNIQUE", _('This is not a valid CNAME. There already exists a record with this name.'));
define("ERR_DNS_CNAME_EMPTY", _('Empty CNAME records are not allowed.'));
define("ERR_DNS_NON_ALIAS_TARGET", _('You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.'));
define("ERR_DNS_NS_HNAME", _('NS records must be a hostnames.'));
define("ERR_DNS_MX_PRIO", _('A prio field should be numeric.'));
define("ERR_DNS_SOA_NAME", _('Invalid value for name field of SOA record. It should be the name of the zone.'));
define("ERR_DNS_SOA_MNAME", _('You have an error in the MNAME field of the SOA record.'));
define("ERR_DNS_HINFO_INV_CONTENT", _('Invalid value for content field of HINFO record.'));
define("ERR_DNS_HN_TOO_LONG", _('The hostname is too long.'));
define("ERR_DNS_INV_TLD", _('You are using an invalid top level domain.'));
define("ERR_DNS_INV_TTL", _('Invalid value for TTL field. It should be numeric.'));
define("ERR_DNS_INV_TTL_INCONSISTENT", _('Invalid value for TTL field. It should be consistent for all records of this name and type.'));
define("ERR_DNS_INV_PRIO", _('Invalid value for prio field. It should be numeric.'));
define("ERR_DNS_SRV_NAME_SERVICE", _('Invalid service value in name field of SRV record.'));
define("ERR_DNS_SRV_NAME_PROTO", _('Invalid protocol value in name field of SRV record.'));
define("ERR_DNS_SRV_NAME", _('Invalid FQDN value in name field of SRV record.'));
define("ERR_DNS_SRV_WGHT", _('Invalid value for the priority field of the SRV record.'));
define("ERR_DNS_SRV_PORT", _('Invalid value for the weight field of the SRV record.'));
define("ERR_DNS_SRV_TRGT", _('Invalid SRV target.'));
define("ERR_DNS_PRINTABLE", _('Invalid characters have been used in this record.'));
define("ERR_DNS_HTML_TAGS", _('You cannot use html tags for this type of record.'));
define("ERR_DNS_TXT_MISSING_QUOTES", _('Add quotes around TXT record content.'));
define("ERR_DNS_SPF_CONTENT", _('The content of the SPF record is invalid'));

/** Print error message (toolkit.inc)
 *
 * @param string $msg Error message
 * @param string $name Offending DNS record name
 *
 * @return null
 */
function error($msg, $name = null) {
        if ($name == null) {
                echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . "</div>\n";
        } else {
                echo "     <div class=\"alert alert-danger\"><strong>Error:</strong> " . $msg . " (Record: " . $name . ")</b></div>\n";
        }
}

