<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
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


$valid_vars_get = array(
	"commit"	=> "validate_true",			# commit a change, value unused
	"ip_master"	=> "validate_ip_address",		# ip address of a master (of slave)
	"letter"	=> "validate_alphanumeric",		# letter for show_letter
	"logout"	=> "validate_true",			# commit a logout
	"pid"		=> "validate_positive_number",		# permission template id
	"rid"		=> "validate_positive_number",		# record id
	"start"		=> "validate_positive_number",		# number for show_page 
	"time"		=> "validate_positive_number",		# session related?
	"uid"		=> "validate_positive_number",		# user id
	"zid"		=> "validate_positive_number"		# zone id
	);

$valid_vars_post = array(
	"active"	=> "validate_positive_number",		# switch active status of user
	"authenticate"	=> "validate_sql_safe",			# commit at authentication 
	"commit"	=> "validate_true",			# commit a change, value unused
	"content"	=> "validate_sql_safe",			# content field of a record
	"descr"		=> "validate_sql_safe",			# description of a user 
	"email"		=> "validate_sql_safe",			# email address of a user
	"empty"		=> "validate_positive_number",		# prio field of a record
	"fullname"	=> "validate_sql_safe",			# fullname of a user
	"holy_grail"	=> "validate_holy_grail",		# string to do a search for
	"ip_mail"	=> "validate_ip_address",		# ip address of a mail server
	"ip_master"	=> "validate_ip_address",		# ip address of a master (of slave)
	"ip_superm"	=> "validate_ip_address",		# ip address of a supermaster
	"ip_web"	=> "valodate_ip_address",		# ip addeess of a web server
	"label"		=> "validate_sql_safe",			# label field of a record
	"name"		=> "validate_sql_safe",			# name of the zone
	"ns_name"	=> "validate_sql_safe",			# hostname of supermaster slave in zone
	"owner"		=> "validate_sql_safe",			# owner of an object
	"password"	=> "validate_sql_safe",			# password at login
	"password_now"	=> "validate_sql_safe",			# current password
	"password_new1"	=> "validate_sql_safe",			# new password, first try
	"password_new2"	=> "validate_sql_safe",			# new password, second try
	"pt_name"	=> "validate_sql_safe",			# permission template name
	"pt_descr"	=> "validate_sql_safe",			# permission template description
	"pid"		=> "validate_positive_number",		# permission template id
	"prio"		=> "validate_positive_number",		# prio field of a record
	"rid"		=> "validate_positive_number",		# record id
	"sid"		=> "validate_positive_number",		# supermaster id
	"sm_owner"	=> "validate_sql_safe",			# reference to supermaster owner
	"ttl"		=> "validate_positive_number",		# ttl field of a record
	"type"		=> "validate_sql_safe",			# type field of a record
	"uid"		=> "validate_positive_number",		# user id
	"username"	=> "validate_sql_safe",			# username at login
	"zid"		=> "validate_positive_number",		# zone id
	"zone_name"	=> "validate_sql_safe",			# name of the zone
	"zone_type"	=> "validate_sql_safe"			# type of the zone
	);

function validate_variables($input, $valid_vars) {
	foreach ($input as $key => $val) {

		// Check if given value is an array in itself. If so, start from the beginning 
		// with given value as a key.
		if (is_array($val)) {
			validate_variables($val, $valid_vars);
		} else {

			// Check if the given key exists in the list of valid variables.
			if (array_key_exists($key,$valid_vars)) {
				if (!$valid_vars[$key]($val)) {
					$input['var_err_val'][] = $key;
				}
			
			// If it wasn't in the list of valid variables, check if the key
			// is just a positive number. We are using auto-generated id's.
			} elseif (validate_positive_number($key)) {
				// Do nothing. It's OK.
			} else {
				error("An unknown variable (\"" . $key . "\") was defined.");
			}
		}
	}
	return $input;
}

function validate_positive_number($string) {
	return eregi("^[0-9]+$", $string);
}

function validate_holy_grail($string) {
        return preg_match('/^[a-z0-9.\-%_]+$/i', $string);
}

function validate_true($string) {
	return true;
}

function validate_sql_safe($string) {
	return preg_match('/^[a-z0-9.\-\\/%_@ ]+$/i', $string);
}

function validate_alphanumeric($string) {
	return preg_match('/^[a-z0-9]+$/i', $string);
}

function validate_ip_address($string) {
	return (is_valid_ipv4($string) || is_valid_ipv6($string));
}

function minimum_variable_set($required_keys,$input) {
        foreach ($required_keys as $key) {
                if (!array_key_exists($key, $input)) {
                        error("Variable \"" . $key ."\" was not defined, but this variable is required.");
                        $false = "1";
                }
	}
	if (isset($false)) {
		return false ;
	} else {
		return true ;
	}
	
}

?>
