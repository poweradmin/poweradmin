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

function zone_id_exists($zid) {
	global $db;
	$query = "SELECT COUNT(id) FROM domains WHERE id = " . $db->quote($zid, 'integer');
	$count = $db->queryOne($query);
	if (PEAR::isError($count)) { error($result->getMessage()); return false; }
	return $count;
}


function get_zone_id_from_record_id($rid) {
	global $db;
	$query = "SELECT domain_id FROM records WHERE id = " . $db->quote($rid, 'integer');
	$zid = $db->queryOne($query);
	return $zid;
}

function count_zone_records($zid) {
	global $db;
	$sqlq = "SELECT COUNT(id) FROM records WHERE domain_id = ".$db->quote($zid, 'integer');
	$record_count = $db->queryOne($sqlq);
	return $record_count;
}

function update_soa_serial($zid) {
	global $db;

	$sqlq = "SELECT notified_serial FROM domains WHERE id = ".$db->quote($zid, 'integer');
	$notified_serial = $db->queryOne($sqlq);

	$sqlq = "SELECT content FROM records WHERE type = ".$db->quote('SOA', 'text')." AND domain_id = ".$db->quote($zid, 'integer');
	$result = $db->queryOne($sqlq);

	// Split content of current SOA record into an array. 
	$soa = explode(" ", $result);

	// Check if we have to update the serial field. 
	// 
	// The serial should be updated, unless:
	//  - the serial is set to "0", see /Documentation/DNS-SOA#PowerDNSspecifics on
	//    the Poweradmin website
	//  - the serial is set to YYYYMMDD99, it's RFC 1912 style already and has 
	//    reached it limit of revisions for today
	
	if ($soa[2] == "0") {
		return true;
	} elseif ($soa[2] == date('Ymd') . "99") {
		return true;
	} else {
		$today = date('Ymd');

		// Determine revision.
		if (strncmp($today, $soa[2], 8) === 0) {
			// Current serial starts with date of today, so we need to update
			// the revision only. To do so, determine current revision first, 
			// then update counter.
			$revision = (int) substr($soa[2], -2);
			++$revision;
		} else {
			// Current serial did not start of today, so it's either an older 
			// serial or a serial that does not adhere the recommended syntax
			// of RFC-1912. In either way, set a fresh serial
			$revision = "00";
		}

		$serial = $today . tr_pad($revision, 2, "0", STR_PAD_LEFT);
		
		// Change serial in SOA array.
		$soa[2] = $serial;
		
		// Build new SOA record content and update the database.
		$content = "";		
		for ($i = 0; $i < count($soa); $i++) {	
			$content .= $soa[$i] . " "; 
		}
		$sqlq = "UPDATE records SET content = ".$db->quote($content, 'text')." WHERE domain_id = ".$db->quote($zid, 'integer')." AND type = ".$db->quote('SOA', 'text');
		$response = $db->query($sqlq);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }
		return true;
	}
}  

/*
 * Edit a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function edit_record($record) {
	
	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($record['zid']);
	$zone_type = get_domain_type($record['zid']);

	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" && $user_is_zone_owner == "0") ) {
		error(ERR_PERM_EDIT_RECORD);
		return false;
	} else {
		global $db;
		if (validate_input($record['zid'], $record['type'], $record['content'], $record['name'], $record['prio'], $record['ttl'])) {
			$query = "UPDATE records 
				SET name=".$db->quote($record['name'], 'text').", 
				type=".$db->quote($record['type'], 'text').", 
				content=" . $db->quote($record['content'], 'text') . ", 
				ttl=".$db->quote($record['ttl'], 'integer').", 
				prio=".$db->quote($record['prio'], 'integer').", 
				change_date=".$db->quote(time(), 'integer')." 
				WHERE id=".$db->quote($record['rid'], 'integer');
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($result->getMessage()); return false; }
			if ($record['type'] != 'SOA') {
				update_soa_serial($record['zid']);
			}
			return true;
		}
		return false;
	}
	return true;
}


/*
 * Adds a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function add_record($zoneid, $name, $type, $content, $ttl, $prio) {
	global $db;

	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($zoneid);
	$zone_type = get_domain_type($zoneid);

        if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" && $user_is_zone_owner == "0") ) {
		error(ERR_PERM_ADD_RECORD);
		return false;
	} else {
		if (validate_input($zoneid, $type, $content, $name, $prio, $ttl) ) {
			$change = time();
			$query = "INSERT INTO records (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
						. $db->quote($zoneid, 'integer') . ","
						. $db->quote($name, 'text') . "," 
						. $db->quote($type, 'text') . "," 
						. $db->quote($content, 'text') . ","
						. $db->quote($ttl, 'integer') . ","
						. $db->quote($prio, 'integer') . ","
						. $db->quote($change, 'integer') . ")";
			$response = $db->query($query);
			if (PEAR::isError($response)) {
				error($response->getMessage());
				return false;
			} else {
				if ($type != 'SOA') { update_soa_serial($zoneid); }
				return true;
			}
		} else {
			return false;
		}
		return true;
	}
}


function add_supermaster($master_ip, $ns_name, $account)
{
        global $db;
        if (!is_valid_ipv4($master_ip) && !is_valid_ipv6($master_ip)) {
                error(ERR_DNS_IP);
		return false;
        }
        if (!is_valid_hostname_fqdn($ns_name,0)) {
                error(ERR_DNS_HOSTNAME);
		return false;
        }
	if (!validate_account($account)) {
		error(sprintf(ERR_INV_ARGC, "add_supermaster", "given account name is invalid (alpha chars only)"));
		return false;
	}
        if (supermaster_exists($master_ip)) {
                error(ERR_SM_EXISTS);
		return false;
        } else {
                $db->query("INSERT INTO supermasters VALUES (".$db->quote($master_ip, 'text').", ".$db->quote($ns_name, 'text').", ".$db->quote($account, 'text').")");
                return true;
        }
}

function delete_supermaster($master_ip) {
	global $db;
        if (is_valid_ipv4($master_ip) || is_valid_ipv6($master_ip))
        {
                $db->query("DELETE FROM supermasters WHERE ip = ".$db->quote($master_ip, 'text'));
                return true;
        }
        else
        {
                error(sprintf(ERR_INV_ARGC, "delete_supermaster", "No or no valid ipv4 or ipv6 address given."));
        }
}

function get_supermaster_info_from_ip($master_ip)
{
	global $db;
        if (is_valid_ipv4($master_ip) || is_valid_ipv6($master_ip))
	{
	        $result = $db->queryRow("SELECT ip,nameserver,account FROM supermasters WHERE ip = ".$db->quote($master_ip, 'text'));

		$ret = array(
		"master_ip"	=>              $result["ip"],
		"ns_name"	=>              $result["nameserver"],
		"account"	=>              $result["account"]
		);

		return $ret;	
	}
        else
	{
                error(sprintf(ERR_INV_ARGC, "get_supermaster_info_from_ip", "No or no valid ipv4 or ipv6 address given."));
        }
}

function get_record_details_from_record_id($rid) {

	global $db;

	$query = "SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio, change_date FROM records WHERE id = " . $db->quote($rid, 'integer') ;

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	
	$return = $response->fetchRow();
	return $return;
}

/*
 * Delete a record by a given id.
 * return values: true, this function is always succesful.
 */
function delete_record($rid)
{
	global $db;

	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; } 
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; } 
	else { $perm_content_edit = "none" ; }

	// Determine ID of zone first.
	$record = get_record_details_from_record_id($rid);
	$user_is_zone_owner = verify_user_is_owner_zoneid($record['zid']);

	if ( $perm_content_edit == "all" || ($perm_content_edit == "own" && $user_is_zone_owner == "1" )) {
		if ($record['type'] == "SOA") {
			error(_('You are trying to delete the SOA record. If are not allowed to remove it, unless you remove the entire zone.'));
		} else {
			$query = "DELETE FROM records WHERE id = " . $db->quote($rid, 'integer');
			$response = $db->query($query);
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }
			return true;
		}
	} else {
		error(ERR_PERM_DEL_RECORD);
		return false;
	}
}


/*
 * Add a domain to the database.
 * A domain is name obligatory, so is an owner.
 * return values: true when succesful.
 * Empty means templates dont have to be applied.
 * --------------------------------------------------------------------------
 * This functions eats a template and by that it inserts various records.
 * first we start checking if something in an arpa record
 * remember to request nextID's from the database to be able to insert record.
 * if anything is invalid the function will error
 */
function add_domain($domain, $owner, $webip, $mailip, $empty, $type, $slave_master)
{
	if(verify_permission('zone_master_add')) { $zone_master_add = "1" ; } ;
	if(verify_permission('zone_slave_add')) { $zone_slave_add = "1" ; } ;

	// TODO: make sure only one is possible if only one is enabled
	if($zone_master_add == "1" || $zone_slave_add == "1") {

		global $db;
		global $dns_ns1;
		global $dns_hostmaster;
		global $dns_ttl;
		if (($domain && $owner && $webip && $mailip) || 
				($empty && $owner && $domain) || 
				(eregi('in-addr.arpa', $domain) && $owner) || 
				$type=="SLAVE" && $domain && $owner && $slave_master) {

			$response = $db->query("INSERT INTO domains (name, type) VALUES (".$db->quote($domain, 'text').", ".$db->quote($type, 'text').")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			$domain_id = $db->lastInsertId('domains', 'id');
			if (PEAR::isError($domain_id)) { error($id->getMessage()); return false; }

			$response = $db->query("INSERT INTO zones (domain_id, owner) VALUES (".$db->quote($domain_id, 'integer').", ".$db->quote($owner, 'integer').")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			if ($type == "SLAVE") {
				$response = $db->query("UPDATE domains SET master = ".$db->quote($slave_master, 'text')." WHERE id = ".$db->quote($domain_id, 'integer'));
				if (PEAR::isError($response)) { error($response->getMessage()); return false; }
				return true;
			} else {
				$now = time();
				if ($empty && $domain_id) {
					$ns1 = $dns_ns1;
					$hm  = $dns_hostmaster;
					$ttl = $dns_ttl;

					$query = "INSERT INTO records (domain_id, name, content, type, ttl, prio, change_date) VALUES (" 
							. $db->quote($domain_id, 'integer') . "," 
							. $db->quote($domain, 'text') . "," 
							. $db->quote($ns1.' '.$hm.' 1', 'text') . ","
							. $db->quote('SOA', 'text').","
							. $db->quote($ttl, 'integer')."," 
							. $db->quote(0, 'integer'). ","
							. $db->quote($now, 'integer').")";
					$response = $db->query($query);
					if (PEAR::isError($response)) { error($response->getMessage()); return false; }
				} elseif ($domain_id) {
					global $template;
					global $dns_ttl;

					foreach ($template as $r) {
						if ((eregi('in-addr.arpa', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!eregi('in-addr.arpa', $domain)))
						{
							$name     = parse_template_value($r["name"], $domain, $webip, $mailip);
							$type     = $r["type"];
							$content  = parse_template_value($r["content"], $domain, $webip, $mailip);
							$ttl      = $r["ttl"];
							$prio     = intval($r["prio"]);

							if (!$ttl) {
								$ttl = $dns_ttl;
							}

							$query = "INSERT INTO records (domain_id, name, type, content, ttl, prio, change_date) VALUES (" 
									. $db->quote($domain_id, 'integer') . ","
									. $db->quote($name, 'text') . ","
									. $db->quote($type, 'text') . ","
									. $db->quote($content, 'text') . ","
									. $db->quote($ttl, 'integer') . ","
									. $db->quote($prio, 'integer') . ","
									. $db->quote($now, 'integer') . ")";
							$response = $db->query($query);
							if (PEAR::isError($response)) { error($response->getMessage()); return false; }
						}
					}
					return true;
				 } else {
					error(sprintf(ERR_INV_ARGC, "add_domain", "could not create zone"));
				 }
			}
		} else {
			error(sprintf(ERR_INV_ARG, "add_domain"));
		}
	} else {
		error(ERR_PERM_ADD_ZONE_MASTER);
		return false;
	}
}


/*
 * Deletes a domain by a given id.
 * Function always succeeds. If the field is not found in the database, thats what we want anyway.
 */
function delete_domain($id)
{
	global $db;

	if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ; }
	else { $perm_edit = "none" ; }
	$user_is_zone_owner = verify_user_is_owner_zoneid($id);

        if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {    
		if (is_numeric($id)) {
			$db->query("DELETE FROM zones WHERE domain_id=".$db->quote($id, 'integer'));
			$db->query("DELETE FROM domains WHERE id=".$db->quote($id, 'integer'));
			$db->query("DELETE FROM records WHERE domain_id=".$db->quote($id, 'integer'));
			return true;
		} else {
			error(sprintf(ERR_INV_ARGC, "delete_domain", "id must be a number"));
			return false;
		}
	} else {
		error(ERR_PERM_DEL_ZONE);
	}
}


/*
 * Gets the id of the domain by a given record id.
 * return values: the domain id that was requested.
 */
function recid_to_domid($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT domain_id FROM records WHERE id=".$db->quote($id, 'integer'));
		$r = $result->fetchRow();
		return $r["domain_id"];
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "recid_to_domid", "id must be a number"));
	}
}


/*
 * Change owner of a domain.
 * return values: true when succesful.
 */
function add_owner_to_zone($zone_id, $user_id)
{
	global $db;
	if ( (verify_permission('zone_meta_edit_others')) || (verify_permission('zone_meta_edit_own')) && verify_user_is_owner_zoneid($_GET["id"])) {
		// User is allowed to make change to meta data of this zone.
		if (is_numeric($zone_id) && is_numeric($user_id) && is_valid_user($user_id))
		{
			if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=".$db->quote($user_id, 'integer')." AND domain_id=".$db->quote($zone_id, 'integer')) == 0)
			{
				$db->query("INSERT INTO zones (domain_id, owner) VALUES(".$db->quote($zone_id, 'integer').", ".$db->quote($user_id, 'integer').")");
			}
			return true;
		} else {
			error(sprintf(ERR_INV_ARGC, "add_owner_to_zone", "$zone_id / $user_id"));
		}
	} else {
		return false;
	}
}


function delete_owner_from_zone($zone_id, $user_id)
{
	global $db;
	if ( (verify_permission('zone_meta_edit_others')) || (verify_permission('zone_meta_edit_own')) && verify_user_is_owner_zoneid($_GET["id"])) {
		// User is allowed to make change to meta data of this zone.
		if (is_numeric($zone_id) && is_numeric($user_id) && is_valid_user($user_id))
		{
			// TODO: Next if() required, why not just execute DELETE query?
			if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=".$db->quote($user_id, 'integer')." AND domain_id=".$db->quote($zone_id, 'integer')) != 0)
			{
				$db->query("DELETE FROM zones WHERE owner=".$db->quote($user_id, 'integer')." AND domain_id=".$db->quote($zone_id, 'integer'));
			}
			return true;
		} else {
			error(sprintf(ERR_INV_ARGC, "delete_owner_from_zone", "$zone_id / $user_id"));
		}
	} else {
		return false;
	}
	
}

/*
 * Retrieves all supported dns record types
 * This function might be deprecated.
 * return values: array of types in string form.
 */
function get_record_types()
{
	global $rtypes;
	return $rtypes;
}


/*
 * Retrieve all records by a given type and domain id.
 * Example: get all records that are of type A from domain id 1
 * return values: a DB class result object
 */
function get_records_by_type_from_domid($type, $recid)
{
	global $rtypes;
	global $db;

	// Does this type exist?
	if(!in_array(strtoupper($type), $rtypes))
	{
		error(sprintf(ERR_INV_ARGC, "get_records_from_type", "this is not a supported record"));
	}

	// Get the domain id.
	$domid = recid_to_domid($recid);

	$result = $db->query("select id, type from records where domain_id=".$db->quote($recid, 'integer')." and type=".$db->quote($type, 'text'));
	return $result;
}


/*
 * Retrieves the type of a record from a given id.
 * return values: the type of the record (one of the records types in $rtypes assumable).
 */
function get_recordtype_from_id($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT type FROM records WHERE id=".$db->quote($id, 'integer'));
		$r = $result->fetchRow();
		return $r["type"];
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "get_recordtype_from_id"));
	}
}


/*
 * Retrieves the name (e.g. bla.test.com) of a record by a given id.
 * return values: the name associated with the id.
 */
function get_name_from_record_id($id)
{
	global $db;
	if (is_numeric($id)) {
		$result = $db->query("SELECT name FROM records WHERE id=".$db->quote($id, 'integer'));
		$r = $result->fetchRow();
		return $r["name"];
	} else {
		error(sprintf(ERR_INV_ARG, "get_name_from_record_id"));
	}
}


function get_zone_name_from_id($zid)
{
	global $db;

	if (is_numeric($zid))
	{
		$result = $db->query("SELECT name FROM domains WHERE id=".$db->quote($zid, 'integer'));
		$rows = $result->numRows() ;
		if ($rows == 1) {
 			$r = $result->fetchRow();
 			return $r["name"];
		} elseif ($rows == "0") {
			error(sprintf("Zone does not exist."));
			return false;
		} else {
	 		error(sprintf(ERR_INV_ARGC, "get_zone_name_from_id", "more than one domain found?! whaaa! BAD! BAD! Contact admin!"));
			return false;
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "get_zone_name_from_id", "Not a valid domainid: $id"));
	}
}

function get_zone_info_from_id($zid) {

	if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; } 
	elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
	else { $perm_view = "none" ;}

	if ($perm_view == "none") { 
		error(ERR_PERM_VIEW_ZONE);
	} else {
		global $db;

		$query = "SELECT 	domains.type AS type, 
					domains.name AS name, 
					domains.master AS master_ip,
					count(records.domain_id) AS record_count
					FROM domains LEFT OUTER JOIN records ON domains.id = records.domain_id 
					WHERE domains.id = " . $db->quote($zid, 'integer') . "
					GROUP BY domains.id, domains.type, domains.name, domains.master";
		$result = $db->query($query);
		if (PEAR::isError($result)) { error($result->getMessage()); return false; }

		if($result->numRows() != 1) {
			error(_('Function returned an error (multiple zones matching this zone ID).'));
			return false;
		} else {
			$r = $result->fetchRow();
			$return = array(
				"name"		=>	$r['name'],
				"type"		=>	$r['type'],
				"master_ip"	=>	$r['master_ip'],
				"record_count"	=>	$r['record_count']
				);
		}
		return $return;
	}
}


/*
 * Check if a domain is already existing.
 * return values: true if existing, false if it doesnt exist.
 */
function domain_exists($domain)
{
	global $db;

	if (is_valid_hostname_fqdn($domain,0)) {
		$result = $db->query("SELECT id FROM domains WHERE name=".$db->quote($domain, 'text'));
		if ($result->numRows() == 0) {
			return false;
		} elseif ($result->numRows() >= 1) {
			return true;
		}
	} else {
		error(ERR_DOMAIN_INVALID);
	}
}

function get_supermasters()
{
        global $db;
        
	$result = $db->query("SELECT ip, nameserver, account FROM supermasters");
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

        $ret = array();

        if($result->numRows() == 0) {
                return -1;
        } else {
                while ($r = $result->fetchRow()) {
                        $ret[] = array(
                        "master_ip"     => $r["ip"],
                        "ns_name"       => $r["nameserver"],
                        "account"       => $r["account"],
                        );
                }
		return $ret;
        }
}

function supermaster_exists($master_ip)
{
        global $db;
        if (is_valid_ipv4($master_ip) || is_valid_ipv6($master_ip))
        {
                $result = $db->query("SELECT ip FROM supermasters WHERE ip = ".$db->quote($master_ip, 'text'));
                if ($result->numRows() == 0)
                {
                        return false;
                }
                elseif ($result->numRows() >= 1)
                {
                        return true;
                }
        }
        else
        {
                error(sprintf(ERR_INV_ARGC, "supermaster_exists", "No or no valid IPv4 or IPv6 address given."));
        }
}


function get_zones($perm,$userid=0,$letterstart='all',$rowstart=0,$rowamount=999999) 
{
	global $db;
	global $sql_regexp;
	$sql_add = '';
	if ($perm != "own" && $perm != "all") {
		error(ERR_PERM_VIEW_ZONE);
		return false;
	}
	else
	{
		if ($perm == "own") {
			$sql_add = " AND zones.domain_id = domains.id
				AND zones.owner = ".$db->quote($userid, 'integer');
		}
		if ($letterstart!='all' && $letterstart!=1) {
			$sql_add .=" AND domains.name LIKE ".$db->quote($db->quote($letterstart, 'text', false, true)."%", 'text')." ";
		} elseif ($letterstart==1) {
			$sql_add .=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
		}
	}
	
	$sqlq = "SELECT domains.id, 
			domains.name,
			domains.type,
			COUNT(DISTINCT records.id) AS count_records
			FROM domains
			LEFT JOIN zones ON domains.id=zones.domain_id 
			LEFT JOIN records ON records.domain_id=domains.id
			WHERE 1=1".$sql_add." 
			GROUP BY domains.name, domains.id, domains.type
			ORDER BY domains.name";
	
	$db->setLimit($rowamount, $rowstart);
	$result = $db->query($sqlq);

	while($r = $result->fetchRow())
	{
		$ret[$r["name"]] = array(
		"zid"		=>	$r["id"],
		"name"		=>	$r["name"],
		"type"		=>	$r["type"],
		"count_records"	=>	$r["count_records"]
		);	
	}
	return $ret;
}

// TODO: letterstart limitation and userid permission limitiation should be applied at the same time?
function zone_count_ng($perm, $letterstart='all') {
	global $db;
	global $sql_regexp;

	$fromTable = 'domains';
	$sql_add = '';

	if ($perm != "own" && $perm != "all") {
		$zone_count = "0";
	} 
	else 
	{
		if ($perm == "own") {
			$sql_add = " AND zones.domain_id = domains.id
					AND zones.owner = ".$db->quote($_SESSION['userid'], 'integer');
			$fromTable .= ',zones';
		}
		if ($letterstart!='all' && $letterstart!=1) {
			$sql_add .=" AND domains.name LIKE ".$db->quote($db->quote($letterstart, 'text', false, true)."%", 'text')." ";
		} elseif ($letterstart==1) {
			$sql_add .=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
		}

		$sqlq = "SELECT COUNT(distinct domains.id) AS count_zones 
			FROM ".$fromTable."	WHERE 1=1
			".$sql_add.";";

		$zone_count = $db->queryOne($sqlq);
	}
	return $zone_count;
}

function zone_count_for_uid($uid) {
	global $db;
	$query = "SELECT COUNT(domain_id) 
			FROM zones 
			WHERE owner = " . $db->quote($uid, 'integer') . " 
			ORDER BY domain_id";
	$zone_count = $db->queryOne($query);
	return $zone_count;
}


/*
 * Get a record from an id.
 * Retrieve all fields of the record and send it back to the function caller.
 * return values: the array with information, or -1 is nothing is found.
 */
function get_record_from_id($rid) {
	global $db;
	$result = $db->query("SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio, change_date FROM records WHERE id=".$db->quote($rid, 'integer'));
	if($result->numRows() == 0) {
		return -1;
	} elseif ($result->numRows() == 1) {
		$r = $result->fetchRow();
		$ret = array(
			"rid"            =>      $r["rid"],
			"zid"     =>      $r["zid"],
			"name"          =>      $r["name"],
			"type"          =>      $r["type"],
			"content"       =>      $r["content"],
			"ttl"           =>      $r["ttl"],
			"prio"          =>      $r["prio"],
			"change_date"   =>      $r["change_date"]
			);
		return $ret;
	} 
	return false;
}


/*
 * Get all records from a domain id.
 * Retrieve all fields of the records and send it back to the function caller.
 * return values: the array with information, or -1 is nothing is found.
 */
function get_records_from_domain_id($zid,$rowstart=0,$rowamount=999999) {
	global $db;
	if ((isset($_SESSION[$zid."_ispartial"])) && ($_SESSION[$zid."_ispartial"] == 1)) {
		$db->setLimit($rowamount, $rowstart);
		$result = $db->query("SELECT record_owners.record_id AS rid
				FROM record_owners,domains,records
				WHERE record_owners.user_id = " . $db->quote($_SESSION["userid"], 'integer') . "
				AND record_owners.record_id = records.id
				AND records.domain_id = " . $db->quote($zid, 'integer') . "
				GROUP BY record_owners.record_id");

		$ret = array();
		if($result->numRows() == 0) {
			return -1;
		} else {
			$ret[] = array();
			$retcount = 0;
			while($r = $result->fetchRow()) {
				// Call get_record_from_id for each row.
				$ret[$retcount] = get_record_from_id($r["rid"]);
				$retcount++;
			}
			return $ret;
		}
	} else {
		$db->setLimit($rowamount, $rowstart);
		$result = $db->query("SELECT id AS rid FROM records WHERE domain_id=".$db->quote($zid, 'integer'));
		$ret = array();
		if($result->numRows() == 0) {
			return -1;
		} else {
			$ret[] = array();
			$retcount = 0;
			while($r = $result->fetchRow()) {
				// Call get_record_from_id for each row.
				$ret[$retcount] = get_record_from_id($r["rid"]);
				$retcount++;
			}
			return $ret;
		}

	}
}


function get_users_from_domain_id($id) {
	global $db;
	$sqlq = "SELECT owner FROM zones WHERE domain_id =" .$db->quote($id, 'integer');
	$id_owners = $db->query($sqlq);
	if ($id_owners->numRows() == 0) {
		return -1;
	} else {
		while ($r = $id_owners->fetchRow()) {
			$fullname = $db->queryOne("SELECT fullname FROM users WHERE id=".$r['owner']);
			$owners[] = array(
				"id" 		=> 	$r['owner'],
				"fullname"	=>	$fullname		
			);		
		}
	}
	return $owners;	
}


function search_zone_and_record($holy_grail,$perm) {
	
	global $db;

	$holy_grail = trim($holy_grail);

	$sql_add_from = '';
	$sql_add_where = '';

	$return_zones = array();
	$return_records = array();

	if (verify_permission('zone_content_view_others')) { $perm_view = "all" ; }
	elseif (verify_permission('zone_content_view_own')) { $perm_view = "own" ; }
	else { $perm_view = "none" ; }

	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	// Search for matching domains
	if ($perm == "own") {
		$sql_add_from = ", zones ";
		$sql_add_where = " AND zones.domain_id = domains.id AND zones.owner = " . $db->quote($_SESSION['userid'], 'integer');
	}
	
	$query = "SELECT 
			domains.id AS zid,
			domains.name AS name,
			domains.type AS type,
			domains.master AS master
			FROM domains" . $sql_add_from . "
			WHERE domains.name LIKE " . $db->quote($holy_grail, 'text')
			. $sql_add_where ;
	
	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	while ($r = $response->fetchRow()) {
		$return_zones[] = array(
			"zid"		=>	$r['zid'],
			"name"		=>	$r['name'],
			"type"		=>	$r['type'],
			"master"	=>	$r['master']);
	}

	// Search for matching records

	if ($perm == "own") {
		$sql_add_from = ", zones ";
		$sql_add_where = " AND zones.domain_id = records.domain_id AND zones.owner = " . $db->quote($_SESSION['userid'], 'integer');
	}

	$query = "SELECT
			records.id AS rid,
			records.name AS name,
			records.type AS type,
			records.content AS content,
			records.ttl AS ttl,
			records.prio AS prio,
			records.domain_id AS zid
			FROM records" . $sql_add_from . "
			WHERE (records.name LIKE " . $db->quote($holy_grail, 'text') . " OR records.content LIKE " . $db->quote($holy_grail, 'text') . ")"
			. $sql_add_where ;

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	while ($r = $response->fetchRow()) {
		$return_records[] = array(
			"rid"		=>	$r['rid'],
			"name"		=>	$r['name'],
			"type"		=>	$r['type'],
			"content"	=>	$r['content'],
			"ttl"		=>	$r['ttl'],
			"zid"		=>	$r['zid'],
			"prio"		=>	$r['prio']);
	}
	return array('zones' => $return_zones, 'records' => $return_records);
}

function get_domain_type($id) {
	global $db;
        if (is_numeric($id)) {
		$type = $db->queryOne("SELECT type FROM domains WHERE id = ".$db->quote($id, 'integer'));
		if ($type == "") {
			$type = "NATIVE";
		}
		return $type;
        } else {
                error(sprintf(ERR_INV_ARG, "get_record_from_id", "no or no valid zoneid given"));
        }
}

function get_domain_slave_master($id){
	global $db;
        if (is_numeric($id)) {
		$slave_master = $db->queryOne("SELECT master FROM domains WHERE type = 'SLAVE' and id = ".$db->quote($id, 'integer'));
		return $slave_master;
        } else {
                error(sprintf(ERR_INV_ARG, "get_domain_slave_master", "no or no valid zoneid given"));
        }
}

function change_zone_type($type, $id)
{
	global $db;
	$add = '';
        if (is_numeric($id))
	{
		// It is not really neccesary to clear the field that contains the IP address 
		// of the master if the type changes from slave to something else. PowerDNS will
		// ignore the field if the type isn't something else then slave. But then again,
		// it's much clearer this way.
		if ($type != "SLAVE") {
			$add = ", master=".$db->quote('', 'text');
		}
		$result = $db->query("UPDATE domains SET type = " . $db->quote($type, 'text') . $add . " WHERE id = ".$db->quote($id, 'integer'));
	} else {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}

function change_zone_slave_master($zone_id, $ip_slave_master) {
	global $db;
        if (is_numeric($zone_id)) {
       		if (is_valid_ipv4($ip_slave_master) || is_valid_ipv6($ip_slave_master)) {
			$result = $db->query("UPDATE domains SET master = " .$db->quote($ip_slave_master, 'text'). " WHERE id = ".$db->quote($zone_id, 'integer'));
		} else {
			error(sprintf(ERR_INV_ARGC, "change_domain_ip_slave_master", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
		}
	} else {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}

function get_serial_by_zid($zid) {
	global $db;
	if (is_numeric($zid)) {
		$query = "SELECT content FROM records where TYPE = ".$db->quote('SOA', 'text')." and domain_id = " . $db->quote($zid, 'integer');
		$rr_soa = $db->queryOne($query);
		if (PEAR::isError($rr_soa)) { error($rr_soa->getMessage()); return false; }
		$rr_soa_fields = explode(" ", $rr_soa);
	} else {
		error(sprintf(ERR_INV_ARGC, "get_serial_by_zid", "id must be a number"));
		return false;
	}
	return $rr_soa_fields[2];
}

function validate_account($account) {
  	if(preg_match("/^[A-Z0-9._-]+$/i",$account)) {
		return true;
	} else {
		return false;
	}
}


?>
