<?php

# This script will increment SOA serials in all zones

# Disable script execution from the web
if (isset($_SERVER['REMOTE_ADDR'])) {
	echo "Error: execute this script from command line\n";
	exit;
}

include_once('../inc/config.inc.php');
require_once("../inc/database.inc.php");
require_once("../inc/record.inc.php");

//$timezone		= 'UTC';

function set_timezone() {
	if (function_exists('date_default_timezone_set')) {
		if (isset($timezone)) {
			date_default_timezone_set($timezone);
		} else if (!ini_get('date.timezone')) {
			date_default_timezone_set('UTC');	
		}
	}
}

$db = dbConnect();

# get ids for all zones
$zones = get_zones('all');

# increment all serials
foreach ($zones as $zone_name => $zone_array) {
	$domain_id = $zone_array['id'];

	$soa_rec = get_soa_record($domain_id);
	$curr_serial = get_soa_serial($soa_rec);
	$new_serial = get_next_serial($curr_serial);

	echo "Updating $zone_name : $curr_serial => $new_serial\n";

	update_soa_serial($domain_id);
}

$db->disconnect();

?>
