<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
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

function validate_input($rid, $zid, $type, &$content, &$name, &$prio, &$ttl) { 

	$zone = get_zone_name_from_id($zid);				// TODO check for return

	if (!(preg_match("/$zone$/i", $name))) {
		if (isset($name) && $name != "") {
			$name = $name . "." . $zone;
		} else {
			$name = $zone;
		}
	}
	
	switch ($type) {

		case "A":
			if (!is_valid_ipv4($content)) return false;
                        if (!is_valid_rr_cname_exists($name,$rid)) return false; 
			if (!is_valid_hostname_fqdn($name,1)) return false;
			break;

		case "AAAA":
			if (!is_valid_ipv6($content)) return false;
                        if (!is_valid_rr_cname_exists($name,$rid)) return false; 
			if (!is_valid_hostname_fqdn($name,1)) return false;
			break;

		case "CNAME":
			if (!is_valid_rr_cname_name($name)) return false;
                        if (!is_valid_rr_cname_unique($name,$rid)) return false; 
			if (!is_valid_hostname_fqdn($name,1)) return false;
			if (!is_valid_hostname_fqdn($content,0)) return false;
			break;

		case "HINFO":
			if (!is_valid_rr_hinfo_content($content)) return false;
			if (!is_valid_hostname_fqdn($name,1)) return false;
			break;

		case "MX":
			if (!is_valid_hostname_fqdn($content,0)) return false;
			if (!is_valid_hostname_fqdn($name,1)) return false;
			if (!is_valid_non_alias_target($content)) return false;
			break;

		case "NS":
			if (!is_valid_hostname_fqdn($content,0)) return false;
			if (!is_valid_hostname_fqdn($name,1)) return false;
			if (!is_valid_non_alias_target($content)) return false;
			break;

		case "PTR":
			if (!is_valid_hostname_fqdn($content,0)) return false;
			if (!is_valid_hostname_fqdn($name,1)) return false;
			break;

		case "SOA":
			if (!is_valid_rr_soa_name($name,$zone)) return false;
			if (!is_valid_hostname_fqdn($name,1)) return false;
			if (!is_valid_rr_soa_content($content)) return false;
			break;
		
		case "SRV":
			if (!is_valid_rr_srv_name($name)) return false;
			if (!is_valid_rr_srv_content($content)) return false;
			break;

		case "TXT":
			if (!is_valid_printable($name)) return false;
			if (!is_valid_printable($content)) return false;
			break;

		case "CURL":
		case "MBOXFW":
		case "NAPTR":
		case "SPF":
			/*
			Validate SPF entry
			*/
                        if(!is_valid_spf($content)) return false; 
		case "SSHFP":
		case "URL":
			// These types are supported by PowerDNS, but there is not
			// yet code for validation. Validation needs to be added 
			// for these types. One Day Real Soon Now. [tm]
			break;

		default:
			error(ERR_DNS_RR_TYPE);
			return false;
	}

	if (!is_valid_rr_prio($prio,$type)) return false;
	if (!is_valid_rr_ttl($ttl)) return false;

	return true;
}

function is_valid_hostname_fqdn(&$hostname, $wildcard) {

	global $dns_strict_tld_check;
	global $valid_tlds;

	$hostname = preg_replace("/\.$/","",$hostname);

	if (strlen($hostname) > 255) {
		error(ERR_DNS_HN_TOO_LONG);
		return false;
	}

        $hostname_labels = explode ('.', $hostname);
        $label_count = count($hostname_labels);

	foreach ($hostname_labels as $hostname_label) {
		if ($wildcard == 1 && !isset($first)) {
			if (!preg_match('/^(\*|[\w-\/]+)$/',$hostname_label)) { error(ERR_DNS_HN_INV_CHARS); return false; }
			$first = 1;
		} else {
			if (!preg_match('/^[\w-\/]+$/',$hostname_label)) { error(ERR_DNS_HN_INV_CHARS); return false; }
		}
		if (substr($hostname_label, 0, 1) == "-") { error(ERR_DNS_HN_DASH); return false; }
		if (substr($hostname_label, -1, 1) == "-") { error(ERR_DNS_HN_DASH); return false; }
		if (strlen($hostname_label) < 1 || strlen($hostname_label) > 63) { error(ERR_DNS_HN_LENGTH); return false; }
	}
	
	if ($hostname_labels[$label_count-1] == "arpa" && (substr_count($hostname_labels[0], "/") == 1 XOR substr_count($hostname_labels[1], "/") == 1)) {
		if (substr_count($hostname_labels[0], "/") == 1) { 
			$array = explode ("/", $hostname_labels[0]);
		} else {
			$array = explode ("/", $hostname_labels[1]);
		}
		if (count($array) != 2) { error(ERR_DNS_HOSTNAME) ; return false; }
		if (!is_numeric($array[0]) || $array[0] < 0 || $array[0] > 255) { error(ERR_DNS_HOSTNAME) ; return false; }
		if (!is_numeric($array[1]) || $array[1] < 25 || $array[1] > 31) { error(ERR_DNS_HOSTNAME) ; return false; }
	} else {
		if (substr_count($hostname, "/") > 0) { error(ERR_DNS_HN_SLASH) ; return false; }
	}
	
	if ($dns_strict_tld_check == "1" && !in_array($hostname_labels[$label_count-1], $valid_tlds)) {
		error(ERR_DNS_INV_TLD); return false;
	}

	return true;
}

function is_valid_ipv4($ipv4) {

// 20080424/RZ: The current code may be replaced by the following if() 
// statement, but it will raise the required PHP version to ">= 5.2.0". 
// Not sure if we want that now.
//
//	if(filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
//		error(ERR_DNS_IPV4); return false;
//	}

	if (!preg_match("/^[0-9\.]{7,15}$/", $ipv4)) {
		error(ERR_DNS_IPV4); return false;
	}

	$quads = explode('.', $ipv4);
	$numquads = count($quads);
	
	if ($numquads != 4) {
		error(ERR_DNS_IPV4); return false;
	}

	for ($i = 0; $i < 4; $i++) {
		if ($quads[$i] > 255) {
			error(ERR_DNS_IPV4); return false;
		}
	}

	return true;
}

function is_valid_ipv6($ipv6) {

// 20080424/RZ: The current code may be replaced by the following if() 
// statement, but it will raise the required PHP version to ">= 5.2.0". 
// Not sure if we want that now.
//
//	if(filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
//		error(ERR_DNS_IPV6); return false;
//	}

	if (!preg_match("/^[0-9a-f]{0,4}:([0-9a-f]{0,4}:){0,6}[0-9a-f]{0,4}$/i", $ipv6)) {
		error(ERR_DNS_IPV6); return false;
	}

	$quads = explode(':', $ipv6);
	$numquads = count ($quads);

	if ($numquads > 8 || $numquads < 3) {
		error(ERR_DNS_IPV6); return false;
	}

	$emptyquads = 0;
	for ($i = 1; $i < $numquads-1; $i++) {
		if ($quads[$i] == "") $emptyquads++;
	}

	if ($emptyquads > 1) {
		error(ERR_DNS_IPV6); return false;
	}

	if ($emptyquads == 0 && $numquads != 8) {
		error(ERR_DNS_IPV6); return false;
	}

	return true;
}

function is_valid_printable($string) {
	if (!preg_match('/^[[:print:]]+$/', trim($string))) { error(ERR_DNS_PRINTABLE); return false; }
	return true;
}

function is_valid_rr_cname_name($name) {
	global $db;

	$query = "SELECT type, content 
			FROM records 
			WHERE content = " . $db->quote($name, 'text') . "
			AND (type = ".$db->quote('MX', 'text')." OR type = ".$db->quote('NS', 'text').")";
	
	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; };

	if ($response->numRows() > 0) {
		error(ERR_DNS_CNAME); return false;
	}

	return true;
}

/*
Check and see if the CNAME exists already
*/
function is_valid_rr_cname_exists($name,$rid) { 
        global $db; 
 
        if ($rid > 0) { 
                $where = " AND id != " .$db->quote($rid); 
        } else { 
                $where = ''; 
        } 
        $query = "SELECT type, name 
                        FROM records 
                        WHERE name = " . $db->quote($name) . $where . " 
                        AND TYPE = 'CNAME'"; 
 
        $response = $db->query($query); 
        if (PEAR::isError($response)) { error($response->getMessage()); return false; }; 
        if ($response->numRows() > 0) { 
                error(ERR_DNS_CNAME_EXISTS); return false; 
        } 
        return true; 
} 

/*
Check and see if this CNAME is unique (doesn't overlap A/AAAA
*/	 
function is_valid_rr_cname_unique($name,$rid) { 
        global $db; 
 
        if ($rid > 0) { 
                $where = " AND id != " .$db->quote($rid); 
        } else { 
                $where = ''; 
        } 
        $query = "SELECT type, name 
                        FROM records 
                        WHERE name = " . $db->quote($name) . $where . " 
                        AND TYPE IN ('A', 'AAAA', 'CNAME')"; 
 
        $response = $db->query($query); 
        if (PEAR::isError($response)) { error($response->getMessage()); return false; }; 
        if ($response->numRows() > 0) { 
                error(ERR_DNS_CNAME_UNIQUE); return false; 
        } 
        return true; 
} 


function is_valid_non_alias_target($target) {
	global $db;
	
	$query = "SELECT type, name
			FROM records
			WHERE name = " . $db->quote($target, 'text') . "
			AND TYPE = ".$db->quote('CNAME', 'text');

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; };
	if ($response->numRows() > 0) {
		error(ERR_DNS_NON_ALIAS_TARGET); return false;
	}
	return true;
}

function is_valid_rr_hinfo_content($content) {

	if ($content[0] == "\"") {
		$fields = preg_split('/(?<=") /', $content, 2);
	} else {
		$fields = preg_split('/ /', $content, 2);
	}

	for ($i = 0; ($i < 2); $i++) {
		if (!preg_match("/^([^\s]{1,1000}|\"([^\"]{1,998}\")$/i", $fields[$i])) {
			error(ERR_DNS_HINFO_INV_CONTENT); return false;
		}
	}

	return true;
}

function is_valid_rr_soa_content(&$content) {

	$fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

	if ($field_count == 0 || $field_count > 7) {
		return false;
	} else {
		if (!is_valid_hostname_fqdn($fields[0],0) || preg_match('/\.arpa\.?$/',$fields[0])) {
			return false;
		}
		$final_soa = $fields[0];

		if (isset($fields[1])) {
			$addr_input = $fields[1];
		} else {
			global $dns_hostmaster;
			$addr_input = $dns_hostmaster;
		}
		if (!preg_match("/@/", $addr_input)) {
			$addr_input = preg_split('/(?<!\\\)\./', $addr_input, 2);
			$addr_to_check = str_replace("\\", "", $addr_input[0]) . "@" . $addr_input[1];
		} else {
			$addr_to_check = $addr_input;
		}
		
		if (!is_valid_email($addr_to_check)) {
			return false;
		} else {
			$addr_final = explode('@', $addr_to_check, 2);
			$final_soa .= " " . str_replace(".", "\\.", $addr_final[0]) . "." . $addr_final[1];
		}

		if (isset($fields[2])) {
			if (!is_numeric($fields[2])) {
				return false;
			}
			$final_soa .= " " . $fields[2];
		} else {
			$final_soa .= " 0";
		}
		
		if ($field_count == 7) {
			for ($i = 3; ($i < 7); $i++) {
				if (!is_numeric($fields[$i])) {
					return false;
				} else {
					$final_soa .= " " . $fields[$i];
				}
			}
		}
	}
	$content = $final_soa;
	return true;
}

function is_valid_rr_soa_name($name, $zone) {
	if ($name != $zone) {
		error(ERR_DNS_SOA_NAME); return false;
	}
	return true;
}

function is_valid_rr_prio(&$prio, $type) {
	if ($type == "MX" || $type == "SRV" ) {
		if (!is_numeric($prio) || $prio < 0 || $prio > 65535 ) {
			error(ERR_DNS_INV_PRIO); return false;
		}
	} else {
		$prio = "";
	}

	return true;
}

function is_valid_rr_srv_name(&$name){

	if (strlen($name) > 255) {
		error(ERR_DNS_HN_TOO_LONG);
		return false;
	}

	$fields = explode('.', $name, 3);
	if (!preg_match('/^_[\w-]+$/i', $fields[0])) { error(ERR_DNS_SRV_NAME) ; return false; }
	if (!preg_match('/^_[\w]+$/i', $fields[1])) { error(ERR_DNS_SRV_NAME) ; return false; }
	if (!is_valid_hostname_fqdn($fields[2],0)) { error(ERR_DNS_SRV_NAME) ; return false ; }
	$name = join('.', $fields);
	return true ;
}

function is_valid_rr_srv_content(&$content) {
	$fields = preg_split("/\s+/", trim($content), 3);
	if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) { error(ERR_DNS_SRV_WGHT) ; return false; } 
	if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) { error(ERR_DNS_SRV_PORT) ; return false; } 
	if ($fields[2] == "" || ($fields[2] != "." && !is_valid_hostname_fqdn($fields[2],0))) {
		error(ERR_DNS_SRV_TRGT) ; return false;
	}
	$content = join(' ', $fields);
	return true;
}

function is_valid_rr_ttl(&$ttl) {

	if (!isset($ttl) || $ttl == "" ) {
		global $dns_ttl;
		$ttl = $dns_ttl;
	}
	
	if (!is_numeric($ttl) ||  $ttl < 0 || $ttl > 2147483647 ) {
		error(ERR_DNS_INV_TTL);	return false;
	}

	return true;
}

function is_valid_search($holygrail) {

	// Only allow for alphanumeric, numeric, dot, dash, underscore and 
	// percent in search string. The last two are wildcards for SQL.
	// Needs extension probably for more usual record types.

	return preg_match('/^[a-z0-9.\-%_]+$/i', $holygrail);
}

/*
SPF Validation function
*/
function is_valid_spf($content){
    //Regex from http://www.schlitt.net/spf/tests/spf_record_regexp-03.txt
	  $regex = "^[Vv]=[Ss][Pp][Ff]1( +([-+?~]?([Aa][Ll][Ll]|[Ii][Nn][Cc][Ll][Uu][Dd][Ee]:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[Aa](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?|[Mm][Xx](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?|[Pp][Tt][Rr](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?|[Ii][Pp]4:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])(/([1-9]|1[0-9]|2[0-9]|3[0-2]))?|[Ii][Pp]6:(::|([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){1,8}:|([0-9A-Fa-f]{1,4}:){7}:[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}){1,2}|([0-9A-Fa-f]{1,4}:){5}(:[0-9A-Fa-f]{1,4}){1,3}|([0-9A-Fa-f]{1,4}:){4}(:[0-9A-Fa-f]{1,4}){1,4}|([0-9A-Fa-f]{1,4}:){3}(:[0-9A-Fa-f]{1,4}){1,5}|([0-9A-Fa-f]{1,4}:){2}(:[0-9A-Fa-f]{1,4}){1,6}|[0-9A-Fa-f]{1,4}:(:[0-9A-Fa-f]{1,4}){1,7}|:(:[0-9A-Fa-f]{1,4}){1,8}|([0-9A-Fa-f]{1,4}:){6}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){6}:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|[0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|::([0-9A-Fa-f]{1,4}:){0,6}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]))(/([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?|[Ee][Xx][Ii][Ss][Tt][Ss]:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))|[Rr][Ee][Dd][Ii][Rr][Ee][Cc][Tt]=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[Ee][Xx][Pp]=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[A-Za-z][-.0-9A-Z_a-z]*=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*))* *$^";
        if(!preg_match($regex, $content)){
          return false;
        }
        else {
          return true;
        }
}
?>
