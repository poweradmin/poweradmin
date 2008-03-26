<?php

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

/* PERMISSIONS */
define("ERR_PERM_SEARCH", _("You do not have the permission to perform searches.")); 
define("ERR_PERM_ADD_RECORD", _("You do not have the permission to add a record to this zone.")); 
define("ERR_PERM_EDIT_RECORD", _("You do not have the permission to edit this record.")); 
define("ERR_PERM_VIEW_RECORD", _("You do not have the permission to view this record.")); 
define("ERR_PERM_DEL_RECORD", _("You do not have the permission to delete this record.")); 
define("ERR_PERM_ADD_ZONE_MASTER", _("You do not have the permission to add a master zone.")); 
define("ERR_PERM_DEL_ZONE", _("You do not have the permission to delete a zone.")); 
define("ERR_PERM_DEL_SM", _("You do not have the permission to delete a supermaster.")); 
define("ERR_PERM_VIEW_ZONE", _("You do not have the permission to view this zone.")); 
define("ERR_PERM_EDIT_USER", _("You do not have the permission to edit this user.")); 
define("ERR_PERM_EDIT_PERM_TEMPL", _("You do not have the permission to edit permission templates.")); 
define("ERR_PERM_DEL_PERM_TEMPL", _("You do not have the permission to delete permission templates.")); 
define("ERR_PERM_ADD_USER", _("You do not have the permission to add a new user.")); 
define("ERR_PERM_DEL_USER", _("You do not have the permission to delete this user.")); 

/* RECORD STUFF */
define("ERR_RECORD_EMPTY_CONTENT", _('Your content field is empty'));
define("ERR_RECORD_ACCESS_DENIED", _('Access denied, you do not have access to that record'));
define("ERR_RECORD_DELETE_TYPE_DENIED", _('You are not allowed to delete %s records'));

/* DOMAIN STUFF */
define("ERR_DOMAIN_INVALID", _('This is an invalid zone name'));
define("ERR_SM_EXISTS", _('There is already a supermaster with this IP address.')); 
define("ERR_DOMAIN_EXISTS", _('There is already a zone with this name.')); 

/* USER STUFF */
define("ERR_USER_EXIST", _('Username exist already, please choose another one'));
define("ERR_USER_NOT_EXIST", _('User doesnt exist'));
define("ERR_USER_WRONG_CURRENT_PASS", _('You didnt enter the correct current password'));
define("ERR_USER_MATCH_NEW_PASS", _('The two new password fields do not match'));
define("ERR_USER_EDIT", _('Error editting user'));
define("ERR_PERM_TEMPL_ASSIGNED", _('This template is assigned to at least one user'));

/* OTHER */
define("ERR_INV_INPUT", _('Invalid or unexpected input given.'));  
define("ERR_INV_ARG", _('Invalid argument(s) given to function %s'));
define("ERR_INV_ARGC", _('Invalid argument(s) given to function %s %s'));
define("ERR_UNKNOWN", _('unknown error'));
define("ERR_INV_EMAIL", _('Enter a valid email address'));

/* DNS */
define("ERR_DNS_CONTENT", _('Your content field doesnt have a legit value'));
define("ERR_DNS_HOSTNAME", _('Invalid hostname'));
define("ERR_DNS_RECORDTYPE", _('Invalid record type! You shouldnt even been able to get that here'));
define("ERR_DNS_IP", _('This is not a valid IPv4 or IPv6 address.')); 
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

/* GOOD! */
define("SUC_ZONE_ADD", _('Zone has been added succesfully.')); 
define("SUC_ZONE_DEL", _('Zone has been deleted succesfully.')); 
define("SUC_USER_UPD", _('The user has been updated succesfully.')); 
define("SUC_USER_ADD", _('The user has been created succesfully.')); 
define("SUC_USER_DEL", _('The user has been deleted succesfully.')); 
define("SUC_RECORD_UPD", _('The record has been updated succesfully.')); 
define("SUC_RECORD_DEL", _('The record has been deleted succesfully.')); 
define("SUC_SM_DEL", _('The supermaster has been deleted succesfully.')); 
define("SUC_PERM_TEMPL_DEL", _('The permission template has been deleted succesfully.')); 

?>
