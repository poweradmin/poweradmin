<?php

// See <https://www.poweradmin.org/trac/wiki/Documentation/ConfigurationFile> for help.

$db_host		= '';
$db_port		= '';
$db_user		= '';
$db_pass		= '';
$db_name		= '';
$db_type		= '';

// This should be changed upon install
$cryptokey		= 'p0w3r4dm1n';

$iface_lang		= 'en_EN';
$iface_style		= 'example';
$iface_rowamount	= 50;
$iface_expire		= 1800;
$iface_zonelist_serial	= false;
$iface_title 		= 'Poweradmin';

$dns_hostmaster		= '';
$dns_ns1		= '';
$dns_ns2		= '';
$dns_ttl		= 86400;
$dns_fancy		= false;
$dns_strict_tld_check	= true;

// See <http://www.php.net/manual/en/timezones.php> for help.
//$timezone		= 'UTC';

/* Syslog usage - writes authentication attempts to syslog
 This facility could be used in combination with fail2ban to
 ban IPs with break-in attempts
*/
$syslog_use = false;
$syslog_ident = 'poweradmin';
// On Windows usually only LOG_USER is available
$syslog_facility = LOG_USER;

?>
