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

function validate_input($zid, $type, &$content, &$name, &$prio, &$ttl)
{
	global $db;
	$domain = get_domain_name_from_id($zid);
	$nocheck = array('SOA', 'HINFO', 'NAPTR', 'URL', 'MBOXFW', 'TXT');
	$hostname = false;
	$ip4 = false;
	$ip6 = false;

	if(!in_array(strtoupper($type), $nocheck)) {
		if(!is_valid_ip6($content)) {
			if(!is_valid_ip($content)) {
				if(!is_valid_hostname($content)) {
					error(ERR_DNS_CONTENT);
					return false;
				} else {
					$hostname = true;
				}
			} else {
				$ip4 = true;
			}
		} else {
			$ip6 = true;
		}
	}

	// Prepare total hostname.
	if ($name == '*') {
		$wildcard = true;
	} else {
		$wildcard = false;
	}

	if (preg_match("/@/", $name)) {
		$name = $domain ;
	} elseif ( !(preg_match("/$domain$/i", $name))) {

		if ( isset($name) && $name != "" ) {
			$name = $name . "." . $domain ;
		} else {
			$name = $domain ;
		}
	}

	if(!$wildcard) {
		if(!is_valid_hostname($name)) {
			error(ERR_DNS_HOSTNAME);
			return false;
		}
	}

	// Check record type (if it exists in our allowed list.
	if (!in_array(strtoupper($type), get_record_types())) {
		error(ERR_DNS_RECORDTYPE);
		return false;
	}

	// Start handling the demands for the functions.
	// Validation for IN A records. Can only have an IP. Nothing else.
	if ($type == 'A' && !$ip4) {
		error(ERR_DNS_IPV4);
		return false;
	}

	if ($type == 'AAAA' && !$ip6) {
		error(ERR_DNS_IPV6);
		return false;
	}

	if ($type == 'CNAME' && $hostname) {
		if(!is_valid_cname($name)) {
			error(ERR_DNS_CNAME);
			return false;
		}
	}

	if ($type == 'NS') {
		$status = is_valid_ns($content, $hostname);
		if($status == -1) {
			error(ERR_DNS_NS_HNAME);
			return false;
		}
		elseif($status == -2) {
			error(ERR_DNS_NS_CNAME);
			return false;
		}
	}

	if ($type == 'SOA' && !is_valid_rr_soa($content)) {
		return false;
	}

	// HINFO and TXT require no validation.

	if ($type == 'URL') {
		if(!is_valid_url($content)) {
			error(ERR_INV_URL);
			return false;
		}
	}
	if ($type == 'MBOXFW') 	{
		if(!is_valid_mboxfw($content)) {
			error(ERR_INV_EMAIL);
			return false;
		}
	}

	// NAPTR has to be done.
	// Do we want that?
	// http://www.ietf.org/rfc/rfc2915.txt
	// http://www.zvon.org/tmRFC/RFC2915/Output/chapter2.html
	// http://www.zvon.org/tmRFC/RFC3403/Output/chapter4.html

	// See if the prio field is valid and if we have one.
	// If we dont have one and the type is MX record, give it value '10'
	if($type == 'NAPTR') {

	}
	
	if($type == 'MX') {
		if($hostname) {
			$status = is_valid_mx($content, $prio);
			if($status == -1) {
				error(ERR_DNS_MX_CNAME);
				return false;
			}
			elseif($status == -2) {
				error(ERR_DNS_MX_PRIO);
				return false;
			}
		} else {
			error( _('If you specify an MX record it must be a hostname.') ); // TODO make error
			return false;
		}
	} else {
		$prio=0;
	}
	// Validate the TTL, it has to be numeric.
	$ttl = (!isset($ttl) || !is_numeric($ttl)) ? $dns_ttl : $ttl;
	
	return true;
}

/*
 * Validatis a CNAME record by the name it will have and its destination
 *
 */
function is_valid_cname($dest)
{
	/*
	 * This is really EVIL.
	 * If the new record (a CNAME) record is being pointed to by a MX record or NS record we have to bork.
	 * this is the idea.
	 *
	 * MX record: blaat.nl MX mail.blaat.nl
	 * Now we look what mail.blaat.nl is
	 * We discover the following:
	 * mail.blaat.nl CNAME bork.blaat.nl
	 * This is NOT allowed! mail.onthanet.nl can not be a CNAME!
	 * The same goes for NS. mail.blaat.nl must have a normal IN A record.
	 * It MAY point to a CNAME record but its not wished. Lets not support it.
	 */

	global $db;

	// Check if there are other records with this information of the following types.
	// P.S. we might add CNAME to block CNAME recursion and chains.
	$blockedtypes = " AND (type='MX' OR type='NS')";

	$cnamec = "SELECT type, content FROM records WHERE content=".$db->quote($dest) . $blockedtypes;
	$result = $db->query($cnamec);

	if($result->numRows() > 0)
	{
		return false;
		// Lets inform the user he is doing something EVIL.
		// Ok we found a record that has our content field in their content field.
	}
	return true;
}


/*
 * Checks if something is a valid domain.
 * Checks for domainname with the allowed characters <a,b,...z,A,B,...Z> and - and _.
 * This part must be followed by a 2 to 4 character TLD.
 */
function is_valid_domain($domain)
{
	if ((eregi("^[0-9a-z]([-.]?[0-9a-z])*\\.[a-z]{2,4}$", $domain)) && (strlen($domain) <= 128))
	{
		return true;
	}
	return false;
}


/*
 * Validates if given hostname is allowed.
 * returns true if allowed.
 */
function is_valid_hostname($host)
{
	if(count(explode(".", $host)) == 1)
	{
		return false;
	}

	// Its not perfect (in_addr.int is allowed) but works for now.

	if(preg_match('!(ip6|in-addr).(arpa|int)$!i', $host))
	{
		if(preg_match('!^(([A-Z\d]|[A-Z\d][A-Z\d-]*[A-Z\d])\.)*[A-Z\d]+$!i', $host))
		{
			return true;
		}
		return false;
	}

	// Validate further.
	return (preg_match('!^(([A-Z\d]|[A-Z\d][A-Z\d-]*[A-Z\d])\.)*[A-Z\d]+$!i', $host)) ? true : false;
}


/*
 * Validates an IPv4 IP.
 * returns true if valid.
 */
function is_valid_ip($ip)
{
	// Stop reading at this point. Scroll down to the next function...
	// Ok... you didn't stop reading... now you have to rewrite the whole function! enjoy ;-)
	// Trance unborked it. Twice even!
	return ($ip == long2ip(ip2long($ip))) ? true : false;

}


/*
 * Validates an IPv6 IP.
 * returns true if valid.
 */
function is_valid_ip6($ip)
{
	// Validates if the given IP is truly an IPv6 address.
	// Precondition: have a string
	// Postcondition: false: Error in IP
	//                true: IP is correct
	// Requires: String
	// Date: 10-sep-2002
	if(preg_match('!^[A-F0-9:]{1,39}$!i', $ip) == true)
	{
		// Not 3 ":" or more.
		$p = explode(':::', $ip);
		if(sizeof($p) > 1)
		{
			return false;
		}
		// Find if there is only one occurence of "::".
		$p = explode('::', $ip);
		if(sizeof($p) > 2)
		{
			return false;
		}
		// Not more than 8 octects
		$p = explode(':', $ip);

		if(sizeof($p) > 8)
		{
			return false;
		}

		// Check octet length
		foreach($p as $checkPart)
		{
			if(strlen($checkPart) > 4)
			{
				return false;
			}
		}
		return true;
	}
	return false;
}


/*
 * FANCY RECORD.
 * Validates if the fancy record mboxfw is an actual email address.
 */
function is_valid_mboxfw($email)
{
	return is_valid_email($email);
}


/*
 * Validates MX records.
 * an MX record cant point to a CNAME record. This has to be checked.
 * this function also sets a proper priority.
 */
function is_valid_mx($content, &$prio)
{
	global $db;
	// See if the destination to which this MX is pointing is NOT a CNAME record.
	// Check inside our dns server.
	if($db->queryOne("SELECT count(id) FROM records WHERE name=".$db->quote($content)." AND type='CNAME'") > 0)
	{
		return -1;
	}
	else
	{
		// Fix the proper priority for the record.
		// Bugfix, thanks Oscar :)
		if(!isset($prio))
		{
			$prio = 10;
		}
		if(!is_numeric($prio))
		{
			if($prio == '')
			{
				$prio = 10;
			}
			else
			{
				return -2;
			}
		}
	}
	return 1;
}

/*
 * Validates NS records.
 * an NS record cant point to a CNAME record. This has to be checked.
 * $hostname directive means if its a hostname or not (this to avoid that NS records get ip fields)
 * NS must have a hostname, it is not allowed to have an IP.
 */
function is_valid_ns($content, $hostname)
{
	global $db;
	// Check if the field is a hostname, it MUST be a hostname.
	if(!$hostname)
	{
		return -1;
		// "an IN NS field must be a hostname."
	}

	if($db->queryOne("SELECT count(id) FROM records WHERE name=".$db->quote($content)." AND type='CNAME'") > 0)
	{
		return -2;
		// "You can not point a NS record to a CNAME record. Remove/rename the CNAME record first or take another name."

	}
	return 1;
}


function is_valid_hostname_label($hostname_label) {

        // See <https://www.poweradmin.org/trac/wiki/Documentation/DNS-hostnames>.
        if (!preg_match('/^[a-z\d]([a-z\d-]*[a-z\d])*$/i',$hostname_label)) {
		return false;
        } elseif (preg_match('/^[\d]+$/i',$hostname_label)) {
                return false;
        } elseif ((strlen($hostname_label) < 2) || (strlen($hostname_label) > 63)) {
                return false;
        }
        return true;
}

function is_valid_hostname_fqdn($hostname) {

        // See <https://www.poweradmin.org/trac/wiki/Documentation/DNS-hostnames>.
	global $dns_strict_tld_check;
	global $valid_tlds;

	$hostname = ereg_replace("\.$","",$hostname);

	if (strlen($hostname) > 255) {
		error(ERR_DNS_HN_TOO_LONG);
		return false;
	}

        $hostname_labels = explode ('.', $hostname);
        $label_count = count($hostname_labels);

	if ($dns_strict_tld_check == "1" && !in_array($hostname_labels[$label_count-1], $valid_tlds)) {
		error(ERR_DNS_INV_TLD);
		return false;
	}

	if ($hostname_labels[$label_count-1] == "arpa") {
		// FIXME
	} else {
		foreach ($hostname_labels as $hostname_label) {
			if (!is_valid_hostname_label($hostname_label)) {
				error(ERR_DNS_HOSTNAME);
				return false;
			}
		}
	}
	return true;
}

function is_valid_rr_soa(&$content) {

	// TODO move to appropiate location
//	$return = get_records_by_type_from_domid("SOA", $zid);
//	if($return->numRows() > 1) {
//		return false;
//	}

	$fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

	if ($field_count == 0 || $field_count > 7) {
		return false;
	} else {
		if (!is_valid_hostname_fqdn($fields[0]) || preg_match('/\.arpa\.?$/',$fields[0])) {
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


function is_valid_url($url)
{
	return preg_match('!^(http://)(([A-Z\d]|[A-Z\d][A-Z\d-]*[A-Z\d])\.)*[A-Z\d]+([//]([0-9a-z//~#%&\'_\-+=:?.]*))?$!i',  $url);
}

function is_valid_search($holygrail)
{
	// Only allow for alphanumeric, numeric, dot, dash, underscore and 
	// percent in search string. The last two are wildcards for SQL.
	// Needs extension probably for more usual record types.

	return preg_match('/^[a-z0-9.\-%_]+$/i', $holygrail);
}


?>
