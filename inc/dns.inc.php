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

/*
 * Validates an IPv4 IP.
 * returns true if valid.
 */
function validate_input($zoneid, $type, &$content, &$name, &$prio, &$ttl)
{
	global $db;

	// Has to validate content first then it can do the rest
	// Since if content is invalid already it can aswell be just removed
	// Check first if content is IPv4, IPv6 or Hostname
	// We accomplish this by just running all tests over it
	// We start with IPv6 since its not able to have these ip's in domains.
	//
	// <TODO>
	// The nocheck has to move to the configuration file
	// </TODO>
	//
	$domain = get_domain_name_from_id($zoneid);
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
	}

	if ($name=="0") {
		$name=$name.".".$domain;
	} else {
		$name = ($name) ? $name.".".$domain : $domain;
	}

	if (preg_match('!@\.!i', $name))
	{
		$name = str_replace('@.', '@', $name);
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

	if ($type == 'SOA') {
		$status = is_valid_soa($content, $zoneid);
		if($status == -1) {
			error(ERR_DNS_SOA_UNIQUE);
		} elseif($status == -2) {
			error(ERR_DNS_SOA_NUMERIC);
			return false;
		}
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
	$ttl = (!isset($ttl) || !is_numeric($ttl)) ? $DEFAULT_TTL : $ttl;
	
	return true;
}



		/****************************************
		 *					*
		 * RECORD VALIDATING PART.		*
		 * CHANGES HERE SHOULD BE CONSIDERED	*
		 * THEY REQUIRE KNOWLEDGE ABOUT THE 	*
		 * DNS SPECIFICATIONS			*
		 *					*
		 ***************************************/


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


/*
 * Function to check the validity of SOA records.
 * return values: true if succesful
 */
function is_valid_soa(&$content, $zoneid)
{

	/*
	 * SOA (start of authority)
	 * there is only _ONE_ SOA record allowed in every zone.
	 * Validate SOA record
	 * The Start of Authority record is one of the most complex available. It specifies a lot
	 * about a domain: the name of the master nameserver ('the primary'), the hostmaster and
	 * a set of numbers indicating how the data in this domain expires and how often it needs
	 * to be checked. Further more, it contains a serial number which should rise on each change
	 * of the domain.
	 					    2002120902 28800 7200 604800 10800
	 * The stored format is: primary hostmaster serial refresh retry expire default_ttl
	 * From the powerdns documentation.
	 */


	// Check if there already is an occurence of a SOA, if so see if its not the one we are currently changing
	$return = get_records_by_type_from_domid("SOA", $zoneid);
	if($return->numRows() > 1)
	{
		return -1;
	}


	$soacontent = explode(" ", $content);
	// Field is at least one otherwise it wouldnt even get here.
	if(is_valid_hostname($soacontent[0]))
	{
		$totalsoa = $soacontent[0];
		// It doesnt matter what field 2 contains, but lets check if its there
		// We assume the 2nd field wont have numbers, otherwise its a TTL field
		if(count($soacontent) > 1)
		{
			if(is_numeric($soacontent[1]))
			{
				// its a TTL field, or at least not hostmaster or alike
				// Set final string to the default hostmaster addy
				global $HOSTMASTER;
				$totalsoa .= " ". $HOSTMASTER;
			}
			else
			{
				$totalsoa .= " ".$soacontent[1];
			}
			// For loop to iterate over the numbers
			$imax = count($soacontent);
			for($i = 2; ($i < $imax) && ($i < 7); $i++)
			{
				if(!is_numeric($soacontent[$i]))
				{
					return -2;
				}
				else
				{
					$totalsoa .= " ".$soacontent[$i];
				}
			}
			if($i > 7)
			{
				error(ERR_DNS_SOA_NUMERIC_FIELDS);
			}
		}
	}
	else
	{
		error(ERR_DNS_SOA_HOSTNAME);
	}
	$content = $totalsoa;
	return 1;
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
