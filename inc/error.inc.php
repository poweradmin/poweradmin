<?

// +--------------------------------------------------------------------+
// | PowerAdmin                                                         |
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team                        |
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal   |
// | program PowerAdmin as found on http://poweradmin.sf.net            |
// | The PowerAdmin program falls under the QPL License:                |
// | http://www.trolltech.com/developer/licensing/qpl.html              |
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>       |
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>                      |
// +--------------------------------------------------------------------+

// Filename: error.inc.php
// Startdate: 25-11-2002
// Description: all error defines should be put in here.
// All errors have to use an ERR_ prefix to distinguish them from other constants.
// All errors should be placed in the appropriate group.
//
// $Id: error.inc.php,v 1.6 2003/05/04 21:38:37 azurazu Exp $
//

/* USER LEVELS */
define("ERR_LEVEL_5", "You need user level 5 for this operation");
define("ERR_LEVEL_10", "You need user level 10 for this operation");

/* RECORD STUFF */
define("ERR_RECORD_EMPTY_CONTENT", "Your content field is empty");
define("ERR_RECORD_ACCESS_DENIED", "Access denied, you do not have access to that record");
define("ERR_RECORD_DELETE_TYPE_DENIED", "You are not allowed to delete %s records");

/* DOMAIN STUFF */
define("ERR_DOMAIN_INVALID", "This is an invalid domain name");

/* USER STUFF */
define("ERR_USER_EXIST", "Username exist already, please choose another one");
define("ERR_USER_NOT_EXIST", "User doesnt exist");
define("ERR_USER_WRONG_CURRENT_PASS", "You didnt enter the correct current password");
define("ERR_USER_MATCH_NEW_PASS", "The two new password fields do not match");
define("ERR_USER_EDIT", "Error editting user");

/* OTHER */
define("ERR_INV_ARG", "Invalid argument(s) given to function %s");
define("ERR_INV_ARGC", "Invalid argument(s) given to function %s %s");
define("ERR_UNKNOWN", "unknown error");
define("ERR_INV_EMAIL", "Enter a valid email address");

/* DNS */
define("ERR_DNS_CONTENT", "Your content field doesnt have a legit value");
define("ERR_DNS_HOSTNAME", "Invalid hostname");
define("ERR_DNS_RECORDTYPE", "Invalid record type! You shouldnt even been able to get that here");
define("ERR_DNS_IPV6", "This is not a valid IPv6 ip.");
define("ERR_DNS_IPV4", "This is not a valid IPv4 ip.");
define("ERR_DNS_CNAME", "This is not a valid CNAME. Did you assign an MX or NS record to the record?");
define("ERR_DNS_NS_CNAME", "You can not point a NS record to a CNAME record. Remove/rename the CNAME record first or take another name.");
define("ERR_DNS_NS_HNAME", "IN NS fields must be a hostnames.");
define("ERR_DNS_MX_CNAME", "You can not point a MX record to a CNAME record. Remove/rename the CNAME record first or take another name.");
define("ERR_DNS_MX_PRIO", "A prio field should be numeric.");
define("ERR_DNS_SOA_NUMERIC", "One of your SOA data fields is not numeric!");
define("ERR_DNS_SOA_NUMERIC_FIELDS", "You can only have 5 numeric fields");
define("ERR_DNS_SOA_HOSTNAME", "The first part of your SOA record does not contain a valid hostname for a DNS Server");
?>
