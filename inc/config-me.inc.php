<?php

/**
 * Sample configuration file with default values
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
// NOTE: Do not edit this file, otherwise it's very likely your changes
// will be overwritten with an upgrade.
// Instead, create the file "inc/config.inc.php" and set the variables you
// want to set there. Your changes will override the defaults provided by us.
// Better description of available configuration settings you can find here:
// <https://github.com/poweradmin/poweradmin/wiki/Configuration-File>
// Database settings
$db_host = '';
$db_port = '';
$db_user = '';
$db_pass = '';
$db_name = '';
$db_type = '';
//$db_charset = 'latin1'; // or utf8
//$db_file		= '';		# used only for SQLite, provide full path to database file
//$db_debug		= false;	# show all SQL queries

// Security settings
// This should be changed upon install
$session_key = 'p0w3r4dm1n';
$password_encryption = 'bcrypt'; // md5, md5salt or bcrypt
$password_encryption_cost = 12; // needed for bcrypt

// Interface settings
$iface_lang = 'en_EN';
$iface_style = 'ignite'; // For dark themes, use 'spark'
$iface_templates = 'templates';
$iface_rowamount = 10;
$iface_expire = 1800;
$iface_zonelist_serial = false;
$iface_title = 'Poweradmin';
$iface_add_reverse_record = true; // Displays a checkbox for adding a reverse record
$iface_zone_type_default = 'MASTER'; // or 'NATIVE'
$iface_zone_comments = true; // Show or hide zone comments

// Predefined DNS settings
$dns_hostmaster = '';
$dns_ns1 = '';
$dns_ns2 = '';
$dns_ns3 = '';
$dns_ns4 = '';
$dns_ttl = 86400;
$dns_soa  = '28800 7200 604800 86400'; // refresh, retry, expire, minimum
$dns_strict_tld_check = false;
$dns_top_level_tld_check = false;     // Do not allow the creation of top-level domains
$dns_third_level_check = false;       // Do not allow the creation of third-level domains

// Timezone settings
// See <http://www.php.net/manual/en/timezones.php> for help.
//$timezone		= 'UTC';

// Logging settings
// Syslog usage - writes authentication attempts to syslog
// This facility could be used in combination with fail2ban to
// ban IPs with break-in attempts
$syslog_use = false;
$syslog_ident = 'poweradmin';
// On Windows usually only LOG_USER is available
$syslog_facility = LOG_USER;

//mysqllogging true or false
$mysql_log = false;

// DNSSEC settings
$pdnssec_use = false;
$pdnssec_debug = false;
$pdnssec_command = '/usr/bin/pdnsutil';

// LDAP settings
$ldap_use = false;
$ldap_debug = false;
$ldap_uri = 'ldap://domaincontroller.example.com'; // Hostname, port number not required
$ldap_basedn = 'ou=users,dc=example,dc=com'; // The place where all users are stored
$ldap_binddn = 'cn=admin,dc=example,dc=com'; // OpenLDAP - full DN of the user cn=admin,dc=example,dc=com, Active Directory - Group\User
$ldap_bindpw = 'some_password';
$ldap_user_attribute = 'uid'; // OpenLDAP - uid, Active Directory - sAMAccountName
$ldap_proto = 3;

// Do not use this configuration variable in production, instead remove the installation folder.
$ignore_install_dir = false;

// Displays the memory consumption and execution time of an application
$display_stats = false;
