<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2012  Poweradmin Development Team
 *      <https://www.poweradmin.org/trac/wiki/Credits>
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

/** Record functions
 *
 */


/** Check if Zone ID exists
 *
 * @param int $zid Zone ID
 *
 * @return boolean|int Domain count or false on failure
 */
function zone_id_exists($zid) {
	global $db;
	$query = "SELECT COUNT(id) FROM domains WHERE id = " . $db->quote($zid, 'integer');
	$count = $db->queryOne($query);
	if (PEAR::isError($count)) { error($count->getMessage()); return false; }
	return $count;
}

/** Get Zone ID from Record ID
 *
 * @param int $rid Record ID
 *
 * @return int Zone ID
 */
function get_zone_id_from_record_id($rid) {
	global $db;
	$query = "SELECT domain_id FROM records WHERE id = " . $db->quote($rid, 'integer');
	$zid = $db->queryOne($query);
	return $zid;
}

/** Count Zone Records for Zone ID
 *
 * @param int $zone_id Zone ID
 *
 * @return int Record count
 */
function count_zone_records($zone_id) {
	global $db;
	$sqlq = "SELECT COUNT(id) FROM records WHERE domain_id = ".$db->quote($zone_id, 'integer');
	$record_count = $db->queryOne($sqlq);
	return $record_count;
}

/** Get SOA record content for Zone ID
 *
 * @param int $domain_id Domain ID
 *
 * @return string SOA content
 */
function get_soa_record($domain_id) {
	global $db;

	$sqlq = "SELECT content FROM records WHERE type = ".$db->quote('SOA', 'text')." AND domain_id = ".$db->quote($domain_id, 'integer');
	$result = $db->queryOne($sqlq);
	
	return $result;
}

/** Get SOA Serial Number
 *
 * @param string $soa_rec SOA record content
 *
 * @return string SOA serial
 */
function get_soa_serial($soa_rec) {
	$soa = explode(" ", $soa_rec);
	return $soa[2];
}

/** Get Next Date
 *
 * @param string $curr_date Current date in YYYYMMDD format
 *
 * @return string Date +1 day
 */
function get_next_date($curr_date) {
	$next_date = date('Ymd', strtotime('+1 day', strtotime($curr_date)));
	return $next_date;
}

/** Get Next Serial
 *
 * Zone transfer to zone slave(s) will occur only if the serial number
 * of the SOA RR is arithmetically greater that the previous one 
 * (as defined by RFC-1982).
 * 
 * The serial should be updated, unless:
 * 
 * - the serial is set to "0", see http://doc.powerdns.com/types.html#id482176
 * 
 * - set a fresh serial ONLY if the existing serial is lower than the current date
 * 
 * - update date in serial if it reaches limit of revisions for today or do you 
 * think that ritual suicide is better in such case?
 * 
 * "This works unless you will require to make more than 99 changes until the new 
 * date is reached - in which case perhaps ritual suicide is the best option."
 * http://www.zytrax.com/books/dns/ch9/serial.html
 *
 * @param string $curr_serial Current Serial No
 * @param string $today Optional date for "today"
 *
 * @return string Next serial number
 */
function get_next_serial($curr_serial, $today = '') {

	if ($today == '') {
		set_timezone();
		$today = date('Ymd');
	}
	
	$revision = (int) substr($curr_serial, -2);
	$ser_date = substr($curr_serial, 0, 8);
	
	if ($curr_serial == '0') {
		$serial = $curr_serial;

	} elseif ($curr_serial == $today . '99') {
		$serial = get_next_date($today) . '00';
	
	} else {
		if (strcmp($today, $ser_date) === 0) {
			// Current serial starts with date of today, so we need to update the revision only.
			++$revision;
			
		} elseif (strncmp($today, $curr_serial, 8) === -1) {
			// Reuse existing serial date if it's in the future
			$today = substr($curr_serial, 0, 8);

			// Get next date if revision reaches maximum per day (99) limit otherwise increment the counter
			if ($revision == 99) {
				$today = get_next_date($today);
				$revision = "00";
			} else {
				++$revision;
			}
			
		} else {
			// Current serial did not start of today, so it's either an older 
			// serial, therefore set a fresh serial
			$revision = "00";
		}

		// Create new serial out of existing/updated date and revision
		$serial = $today . str_pad($revision, 2, "0", STR_PAD_LEFT);
	}
	
	return $serial;
}

/** Update SOA record
 *
 * @param int $domain_id Domain ID
 * @param string $content SOA content to set
 * 
 * @return boolean true if success
 */
function update_soa_record($domain_id, $content) {
	global $db;
	
	$sqlq = "UPDATE records SET content = ".$db->quote($content, 'text')." WHERE domain_id = ".$db->quote($domain_id, 'integer')." AND type = ".$db->quote('SOA', 'text');
	$response = $db->query($sqlq);
	
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	
	return true;
}

/** Set SOA serial in SOA content
 *
 * @param string $soa_rec SOA record content
 * @param string $serial New serial number
 *
 * @return string Updated SOA record
 */
function set_soa_serial($soa_rec, $serial) {
	// Split content of current SOA record into an array. 
	$soa = explode(" ", $soa_rec);
	$soa[2] = $serial;
	
	// Build new SOA record content
	$soa_rec = join(" ", $soa);
	chop($soa_rec);
	
	return $soa_rec;
}

/** Update SOA serial
 *
 * Increments SOA serial to next possible number
 *
 * @param int $domain_id Domain ID
 *
 * @return boolean true if success
 */
function update_soa_serial($domain_id) {
	$soa_rec = get_soa_record($domain_id);
    if ($soa_rec == NULL) {
        return false;
    }

	$curr_serial = get_soa_serial($soa_rec);
	$new_serial = get_next_serial($curr_serial);
	
	if ($curr_serial != $new_serial) {
		$soa_rec = set_soa_serial($soa_rec, $new_serial);
		return update_soa_record($domain_id, $soa_rec);
	}

	return true;
}  

/** Get Zone comment
 *
 * @param int $zone_id Zone ID
 *
 * @return string Zone Comment
 */
function get_zone_comment($zone_id) {
	global $db;
	$query = "SELECT comment FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
	$comment = $db->queryOne($query);

	if ($comment == "0") $comment = '';

	return $comment;
}

/** Edit the zone comment
 *
 * This function validates it if correct it inserts it into the database.
 *
 * @param int $zone_id Zone ID
 * @param string $comment Comment to set
 *
 * @return boolean true on success
 */
function edit_zone_comment($zone_id,$comment) {
	
	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
	$zone_type = get_domain_type($zone_id);

	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" && $user_is_zone_owner == "0") ) {
		error(ERR_PERM_EDIT_COMMENT);
		return false;
	} else {
		global $db;

        $query = "SELECT COUNT(*) FROM zones WHERE domain_id=".$db->quote($zone_id, 'integer');

		$count = $db->queryOne($query);

		if ($count > 0) {
			$query = "UPDATE zones
				SET comment=".$db->quote($comment, 'text')."
				WHERE domain_id=".$db->quote($zone_id, 'integer');
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($result->getMessage()); return false; }
		} else {
			$query = "INSERT INTO zones (domain_id, owner, comment)
				VALUES(".$db->quote($zone_id, 'integer').",1,".$db->quote($comment, 'text').")";
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($result->getMessage()); return false; }
		}
	}
	return true;
}

/** Edit a record
 *
 * This function validates it if correct it inserts it into the database.
 *
 * @param mixed[] $record Record structure to update
 *
 * @return boolean true if successful
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
			if (validate_input($record['rid'], $record['zid'], $record['type'], $record['content'], $record['name'], $record['prio'], $record['ttl'])) {
				$name = strtolower($record['name']); // powerdns only searches for lower case records
				if ($record['type'] == "SPF" || $record['type'] == "TXT"){
                                $content = $db->quote(stripslashes('\"'.$record['content'].'\"'), 'text');
                                }else{
                                $content = $db->quote($record['content'], 'text');
                                }
			$query = "UPDATE records 
				SET name=".$db->quote($name, 'text').", 
				type=".$db->quote($record['type'], 'text').", 
				content=".$content.",
				ttl=".$db->quote($record['ttl'], 'integer').", 
				prio=".$db->quote($record['prio'], 'integer').", 
				change_date=".$db->quote(time(), 'integer')." 
				WHERE id=".$db->quote($record['rid'], 'integer');
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($result->getMessage()); return false; }
			return true;
		}
		return false;
	}
}


/** Add a record
 *
 * This function validates it if correct it inserts it into the database.
 *
 * @param int $zoneid Zone ID
 * @param string $name Name part of record
 * @param string $type Type of record
 * @param string $content Content of record
 * @param int $ttl Time-To-Live of record
 * @param int $prio Priority of record
 *
 * @return boolean true if successful
 */
function add_record($zoneid, $name, $type, $content, $ttl, $prio) {
	global $db;
    global $pdnssec_use;

	if (verify_permission('zone_content_edit_others')) { $perm_content_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($zoneid);
	$zone_type = get_domain_type($zoneid);

        if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" && $user_is_zone_owner == "0") ) {
		error(ERR_PERM_ADD_RECORD);
		return false;
	} else {
		$response = $db->beginTransaction();
		if (validate_input(-1, $zoneid, $type, $content, $name, $prio, $ttl) ) { 
			$change = time();
			$name = strtolower($name); // powerdns only searches for lower case records
				if($type == "SPF" || $type == "TXT"){
                                                $content = $db->quote(stripslashes('\"'.$content.'\"'), 'text');
                                                }else{
                                                $content = $db->quote($content, 'text');
                                                }
			$query = "INSERT INTO records (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
						. $db->quote($zoneid, 'integer') . ","
						. $db->quote($name, 'text') . "," 
						. $db->quote($type, 'text') . ","
						. $content . ","
						. $db->quote($ttl, 'integer') . ","
						. $db->quote($prio, 'integer') . ","
						. $db->quote($change, 'integer') . ")";
			$response = $db->exec($query);
			if (PEAR::isError($response)) {
				error($response->getMessage());
				$response = $db->rollback();
				return false;
			} else {
				$response = $db->commit();
				if ($type != 'SOA') { update_soa_serial($zoneid); }
                                if ($pdnssec_use) { do_rectify_zone($zoneid); }
				return true;
			}
		} else {
			return false;
		}
	}
}

/** Add Supermaster
 *
 * Add a trusted supermaster to the global supermasters table
 *
 * @param string $master_ip Supermaster IP address
 * @param string $ns_name Hostname of supermasterfound in NS records for domain
 * @param string $account Account name used for tracking
 *
 * @return boolean true on success
 */
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
        if (supermaster_ip_name_exists($master_ip, $ns_name)) {
                error(ERR_SM_EXISTS);
		return false;
        } else {
                $db->query("INSERT INTO supermasters VALUES (".$db->quote($master_ip, 'text').", ".$db->quote($ns_name, 'text').", ".$db->quote($account, 'text').")");
                return true;
        }
}

/** Delete Supermaster
 *
 * Delete a supermaster from the global supermasters table
 *
 * @param string $master_ip Supermaster IP address
 * @param string $ns_name Hostname of supermaster
 *
 * @return boolean true on success
 */
function delete_supermaster($master_ip, $ns_name) {
	global $db;
        if (is_valid_ipv4($master_ip) || is_valid_ipv6($master_ip) || is_valid_hostname_fqdn($ns_name, 0))
        {
                $db->query("DELETE FROM supermasters WHERE ip = ". $db->quote($master_ip, 'text') .
			" AND nameserver = " . $db->quote($ns_name, 'text'));
                return true;
        }
        else
        {
                error(sprintf(ERR_INV_ARGC, "delete_supermaster", "No or no valid ipv4 or ipv6 address given."));
        }
}


/** Get Supermaster Info from IP
 *
 * Retrieve supermaster details from supermaster IP address
 *
 * @param string $master_ip Supermaster IP address
 *
 * @return mixed[] array of supermaster details
 */
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

/** Get record details from Record ID
 *
 * @param $rid Record ID
 *
 * @return mixed[] array of record details [rid,zid,name,type,content,ttl,prio,change_date]
 */
function get_record_details_from_record_id($rid) {

	global $db;

	$query = "SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio, change_date FROM records WHERE id = " . $db->quote($rid, 'integer') ;

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	
	$return = $response->fetchRow();
	return $return;
}

/** Delete a record by a given record id
 *
 * @param int $rid Record ID
 *
 * @return boolean true on success
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


/**
 * Add a domain to the database
 *
 * A domain is name obligatory, so is an owner.
 * return values: true when succesful.
 * 
 * Empty means templates dont have to be applied.
 * 
 * This functions eats a template and by that it inserts various records.
 * first we start checking if something in an arpa record
 * remember to request nextID's from the database to be able to insert record.
 * if anything is invalid the function will error
 *
 * @param string $domain A domain name
 * @param int $owner Owner ID for domain
 * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
 * @param string $slave_master Master server hostname for domain
 * @param int|string $zone_template ID of zone template ['none' or int] 
 *
 * @return boolean true on success
 */
function add_domain($domain, $owner, $type, $slave_master, $zone_template)
{
	if(verify_permission('zone_master_add')) { $zone_master_add = "1" ; } ;
	if(verify_permission('zone_slave_add')) { $zone_slave_add = "1" ; } ;

	// TODO: make sure only one is possible if only one is enabled
	if($zone_master_add == "1" || $zone_slave_add == "1") {

		global $db;
		global $dns_ns1;
		global $dns_hostmaster;
		global $dns_ttl;
        global $db_layer;
        global $db_type;

		if (($domain && $owner && $zone_template) || 
				(preg_match('/in-addr.arpa/i', $domain) && $owner && $zone_template) || 
				$type=="SLAVE" && $domain && $owner && $slave_master) {

			$response = $db->query("INSERT INTO domains (name, type) VALUES (".$db->quote($domain, 'text').", ".$db->quote($type, 'text').")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

            if ($db_layer == 'MDB2' && ($db_type == 'mysql' || $db_type == 'pgsql')) {
			    $domain_id = $db->lastInsertId('domains', 'id');
            } else if ($db_layer == 'PDO' && $db_type == 'pgsql') {
                $domain_id = $db->lastInsertId('domains_id_seq');
            } else {
                $domain_id = $db->lastInsertId();
            }

			if (PEAR::isError($domain_id)) { error($domain_id->getMessage()); return false; }

			$response = $db->query("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (".$db->quote($domain_id, 'integer').", ".$db->quote($owner, 'integer').", ".$db->quote(($zone_template == "none") ? 0 : $zone_template, 'integer').")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			if ($type == "SLAVE") {
				$response = $db->query("UPDATE domains SET master = ".$db->quote($slave_master, 'text')." WHERE id = ".$db->quote($domain_id, 'integer'));
				if (PEAR::isError($response)) { error($response->getMessage()); return false; }
				return true;
			} else {
				$now = time();
				if ($zone_template == "none" && $domain_id) {
					$ns1 = $dns_ns1;
					$hm  = $dns_hostmaster;
					$ttl = $dns_ttl;
					
					set_timezone();
					
					$serial = date("Ymd");
					$serial .= "00";

					$query = "INSERT INTO records (domain_id, name, content, type, ttl, prio, change_date) VALUES (" 
							. $db->quote($domain_id, 'integer') . "," 
							. $db->quote($domain, 'text') . "," 
							. $db->quote($ns1.' '.$hm.' '.$serial.' 28800 7200 604800 86400', 'text') . ","
							. $db->quote('SOA', 'text').","
							. $db->quote($ttl, 'integer')."," 
							. $db->quote(0, 'integer'). ","
							. $db->quote($now, 'integer').")";
					$response = $db->query($query);
					if (PEAR::isError($response)) { error($response->getMessage()); return false; }
					return true;
				} elseif ($domain_id && is_numeric($zone_template)) {
					global $dns_ttl;

					$templ_records = get_zone_templ_records($zone_template);
                    if ($templ_records != -1) {
                        foreach ($templ_records as $r) {
                            if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain)))
                            {
                                $name     = parse_template_value($r["name"], $domain);
                                $type     = $r["type"];
                                $content  = parse_template_value($r["content"], $domain);
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


/** Deletes a domain by a given id
 *
 * Function always succeeds. If the field is not found in the database, thats what we want anyway.
 *
 * @param int $id Zone ID
 *
 * @return boolean true on success
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

/** Record ID to Domain ID
 *
 * Gets the id of the domain by a given record id
 *
 * @param int $id Record ID
 * @return int Domain ID of record
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


/** Change owner of a domain
 * 
 * @param int $zone_id Zone ID
 * @param int $user_id User ID
 *
 * @return boolean true when succesful
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
				$zone_templ_id = get_zone_template($zone_id);
				if ($zone_templ_id == NULL) $zone_templ_id = 0;
				$db->query("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES("
					. $db->quote($zone_id, 'integer') . ", "
					. $db->quote($user_id, 'integer') . ", "
					. $db->quote($zone_templ_id, 'integer') . ")"
				);
			}
			return true;
		} else {
			error(sprintf(ERR_INV_ARGC, "add_owner_to_zone", "$zone_id / $user_id"));
		}
	} else {
		return false;
	}
}


/** Delete owner from zone
 *
 * @param int $zone_id Zone ID
 * @param int $user_id User ID
 *
 * @return boolean true on success
 */
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

/** Retrieve all supported dns record types
 *
 * This function might be deprecated.
 *
 * @return string[] array of types
 */
function get_record_types()
{
	global $rtypes;
	return $rtypes;
}


/** Retrieve all records by a given type and domain id
 *
 * Example get all records that are of type A from domain id 1
 *
 * <code>
 * get_records_by_type_from_domid('A', 1)
 * </code>
 *
 * @param string $type Record type
 * @param int $recid Record ID
 *
 * @return object a DB class result object
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

/** Get Record Type for Record ID
 *
 * Retrieves the type of a record from a given id.
 * 
 * @param int $id Record ID
 * @return string Record type (one of the records types in $rtypes assumable).
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


/** Get Name from Record ID
 *
 * Retrieves the name (e.g. bla.test.com) of a record by a given id.
 *
 * @param int $id Record ID
 * @return string Name part of record
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

/** Get Zone Name from Zone ID
 *
 * @param int $zid Zone ID
 *
 * @return string Domain name
 */
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
		error(sprintf(ERR_INV_ARGC, "get_zone_name_from_id", "Not a valid domainid: $zid"));
	}
}

/** Get zone id from name
 *
 * @param string $zname Zone name
 * @return int Zone ID
*/
function get_zone_id_from_name($zname) {
        global $db;
      
        if (!empty($zname))
        {
                $result = $db->query("SELECT id FROM domains WHERE name=".$db->quote($zname, 'text'));
                $rows = $result->numRows() ;
                if ($rows == 1) {
                        $r = $result->fetchRow();
                        return $r["id"];
                } elseif ($rows == "0") {
                        error(sprintf("Zone does not exist."));
                        return false;
                } else {
                        error(sprintf(ERR_INV_ARGC, "get_zone_id_from_name", "more than one domain found?! whaaa! BAD! BAD! Contact admin!"));
                        return false;
                }
        }
        else
        {
                error(sprintf(ERR_INV_ARGC, "get_zone_id_from_name", "Not a valid domainname: $zname"));
        }
}


/** Get Zone details from Zone ID
 *
 * @param int $zid Zone ID
 * @return mixed[] array of zone details [type,name,master_ip,record_count]
 */
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

/** Convert IPv6 Address to PTR
 *
 * @param string $ip IPv6 Address
 * @return string PTR form of address
 */
function convert_ipv6addr_to_ptrrec($ip) {
// rev-patch
// taken from: http://stackoverflow.com/questions/6619682/convert-ipv6-to-nibble-format-for-ptr-records
// PHP (>= 5.1.0, or 5.3+ on Windows), use the inet_pton
//      $ip = '2001:db8::567:89ab';

        $addr = inet_pton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];
        $arpa = implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
        return $arpa;
}

/** Get Best Matching in-addr.arpa Zone ID from Domain Name
 *
 * @param string $domain Domain name
 *
 * @return int Zone ID
 */
function get_best_matching_zone_id_from_name($domain) {
// rev-patch
// tring to find the correct zone
// %ip6.arpa and %in-addr.arpa is looked for

        global $db;

        $ret = array();
        $match=72; // the longest ip6.arpa has a length of 72
        $found_domain_id=-1;

        // get all reverse-zones
        $query = "SELECT name, id
                    FROM domains
                   WHERE name like " . $db->quote('%.arpa', 'text') ."
                   ORDER BY length(name) DESC";

        $response = $db->query($query);
        if (PEAR::isError($response)) { error($response->getMessage()); return false; };
        if ($response->numRows() == 0) {
                return -1;
        } else {
                while ($r = $response->fetchRow()) {
                        $pos = stripos($domain, $r["name"]);
                        if ($pos !== false) {
                                // one possible searched $domain is found
                                if ($pos < $match) {
                                        $match = $pos;
                                        $found_domain_id = $r["id"];
                                }
                        }
                }
        }
        return $found_domain_id;
}

/** Check if Domain Exists
 *
 * Check if a domain is already existing.
 *
 * @param string $domain Domain name
 * @return boolean true if existing, false if it doesnt exist.
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

/** Get All Supermasters
 *
 * Gets an array of arrays of supermaster details
 *
 * @return array[] supermasters detail [master_ip,ns_name,account]s
 */
function get_supermasters()
{
        global $db;
        
	$result = $db->query("SELECT ip, nameserver, account FROM supermasters");
	if (PEAR::isError($result)) { error($result->getMessage()); return false; }

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

/** Check if Supermaster IP address exists
 *
 * @param string $master_ip Supermaster IP
 *
 * @return boolean true if exists, otherwise false
 */
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

/** Check if Supermaster IP Address and NS Name combo exists
 *
 * @param string $master_ip Supermaster IP Address
 * @param string $ns_name Supermaster NS Name
 *
 * @return boolean true if exists, false otherwise
 */
function supermaster_ip_name_exists($master_ip, $ns_name)
{
        global $db;
        if ((is_valid_ipv4($master_ip) || is_valid_ipv6($master_ip)) && is_valid_hostname_fqdn($ns_name,0))
        {
                $result = $db->query("SELECT ip FROM supermasters WHERE ip = ".$db->quote($master_ip, 'text').
					" AND nameserver = ".$db->quote($ns_name, 'text'));
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

/** Get Zones
 *
 * @param string $perm View Zone Permissions ['own','all','none']
 * @param int $userid Requesting User ID
 * @param string $letterstart Starting letters to match [default='all']
 * @param int $rowstart Start from row in set [default=0]
 * @param int $rowamount Max number of rows to fetch for this query when not 'all' [default=999999]
 * @param string $sortby Column to sort results by [default='name']
 *
 * @return boolean|mixed[] false or array of zone details [id,name,type,count_records]
 */
function get_zones($perm,$userid=0,$letterstart='all',$rowstart=0,$rowamount=999999,$sortby='name') 
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
			$sql_add .=" AND domains.name LIKE ".$db->quote($letterstart."%", 'text')." ";
		} elseif ($letterstart==1) {
			$sql_add .=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
		}
	}
	
	if ($sortby != 'count_records') {
		$sortby = "domains.".$sortby.", domains.name";
	} else {
		$sortby = $sortby.", domains.name";
	}

	$sqlq = "SELECT domains.id,
			domains.name,
			domains.type,
			Record_Count.count_records
			FROM domains
			LEFT JOIN zones ON domains.id=zones.domain_id
			LEFT JOIN (
				SELECT COUNT(domain_id) AS count_records, domain_id FROM records GROUP BY domain_id
			) Record_Count ON Record_Count.domain_id=domains.id
			WHERE 1=1".$sql_add."
			GROUP BY domains.name, domains.id, domains.type, Record_Count.count_records
			ORDER BY " . $sortby;

    if ($letterstart != 'all') {
        $db->setLimit($rowamount, $rowstart);
    }
	$result = $db->query($sqlq);

	$ret = array();
	while($r = $result->fetchRow())
	{
    //fixme: name is not guaranteed to be unique with round-robin record sets
		$ret[$r["name"]] = array(
		"id"		=>	$r["id"],
		"name"		=>	$r["name"],
		"type"		=>	$r["type"],
		"count_records"	=>	$r["count_records"]
		);	
	}
	return $ret;
}

// TODO: letterstart limitation and userid permission limitiation should be applied at the same time?
// fixme: letterstart 'all' forbids searching for domains that actually start with 'all'
/** Get Count of Zones
 *
 * @param string $perm 'all', 'own' uses session 'userid'
 * @param string $letterstart Starting letters to match [default='all']  
 *
 * @return int Count of zones matched
 */
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

		if ($letterstart != 'all' && $letterstart != 1) {
			$sql_add .=" AND domains.name LIKE ".$db->quote($letterstart."%", 'text')." ";
		} elseif ($letterstart == 1) {
			$sql_add .=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
		}

# XXX: do we really need this distinct directive as it's unsupported in sqlite)
#		$sqlq = "SELECT COUNT(distinct domains.id) AS count_zones 

		$sqlq = "SELECT COUNT(domains.id) AS count_zones 
			FROM ".$fromTable."	WHERE 1=1
			".$sql_add;
		$zone_count = $db->queryOne($sqlq);
	}
	return $zone_count;
}

/** Get Zone Count for Owner User ID
 *
 * @param int $uid User ID
 *
 * @return int Count of Zones matched
 */
function zone_count_for_uid($uid) {
	global $db;
	$query = "SELECT COUNT(domain_id) 
			FROM zones 
			WHERE owner = " . $db->quote($uid, 'integer') . " 
			ORDER BY domain_id";
	$zone_count = $db->queryOne($query);
	return $zone_count;
}


/** Get a Record from an Record ID
 *
 * Retrieve all fields of the record and send it back to the function caller.
 *
 * @param int $id Record ID
 * @return int|mixed[] array of record detail, or -1 if nothing found
 */
function get_record_from_id($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT id, domain_id, name, type, content, ttl, prio, change_date FROM records WHERE id=".$db->quote($id, 'integer'));
		if($result->numRows() == 0)
		{
			return -1;
		}
		elseif ($result->numRows() == 1)
		{
			$r = $result->fetchRow();
			$ret = array(
				"id"            =>      $r["id"],
				"domain_id"     =>      $r["domain_id"],
				"name"          =>      $r["name"],
				"type"          =>      $r["type"],
				"content"       =>      $r["content"],
				"ttl"           =>      $r["ttl"],
				"prio"          =>      $r["prio"],
				"change_date"   =>      $r["change_date"]
				);
			return $ret;
		}
		else
		{
			error(sprintf(ERR_INV_ARGC, "get_record_from_id", "More than one row returned! This is bad!"));
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "get_record_from_id"));
	}
}


/** Get all records from a domain id.
 *
 * Retrieve all fields of the records and send it back to the function caller.
 * 
 * @param int $id Domain ID
 * @param int $rowstart Starting row [default=0]
 * @param int $rowamount Number of rows to return in this query [default=999999]
 * @param string $sortby Column to sort by [default='name']
 *
 * @return int|mixed[] array of record detail, or -1 if nothing found
 */
function get_records_from_domain_id($id,$rowstart=0,$rowamount=999999,$sortby='name') {
	global $db;
	$result = array();
	if (is_numeric($id)) {
		if ((isset($_SESSION[$id."_ispartial"])) && ($_SESSION[$id."_ispartial"] == 1)) {
			$db->setLimit($rowamount, $rowstart);
			$result = $db->query("SELECT record_owners.record_id as id
					FROM record_owners,domains,records
					WHERE record_owners.user_id = " . $db->quote($_SESSION["userid"], 'integer') . "
					AND record_owners.record_id = records.id
					AND records.domain_id = " . $db->quote($id, 'integer') . "
					GROUP BY record_owners.record_id ORDER BY records.".$sortby);

			$ret = array();
			if($result->numRows() == 0) {
				return -1;
			} else {
				$ret[] = array();
				$retcount = 0;
				while($r = $result->fetchRow())
				{
					// Call get_record_from_id for each row.
					$ret[$retcount] = get_record_from_id($r["id"]);
					$retcount++;
				}
                                $result = $ret;
			}

		} else {
			$db->setLimit($rowamount, $rowstart);
			$result = $db->query("SELECT id FROM records WHERE domain_id=".$db->quote($id, 'integer')." ORDER BY records.".$sortby);
			$ret = array();
			if($result->numRows() == 0)
			{
				return -1;
			}
			else
			{
				$ret[] = array();
				$retcount = 0;
				while($r = $result->fetchRow())
				{
					// Call get_record_from_id for each row.
					$ret[$retcount] = get_record_from_id($r["id"]);
					$retcount++;
				}
                                $result = $ret;
			}
			$result = order_domain_results($result, $sortby);
			return $result;
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "get_records_from_domain_id"));
	}
}

/** Sort Domain Records intelligently
 *
 * @param string[] $domains Array of domains
 * @param string $sortby Column to sort by [default='name','type','content','prio','ttl']
 *
 * @return mixed[] array of records detail
 */
function order_domain_results($domains, $sortby) {
        $results = array();
        $soa = array();
        $ns = array();
        
        foreach ($domains as $key => $domain) {
                switch ($domain['type']) {
                  case 'SOA':
                    $soa[] = $domain;
                    unset ($domains[$key]);
                    break;
                  case 'NS':
                    $ns[] = $domain;
                    unset ($domains[$key]);
                    break;
                  default:
                    continue;
                }
        }

        switch ($sortby)
        {
          case 'name':
            usort($domains, 'sort_domain_results_by_name');
            break;
          case 'type':
            usort($domains, 'sort_domain_results_by_type');
            break;
          case 'content':
            usort($domains, 'sort_domain_results_by_content');
            break;
          case 'prio':
            usort($domains, 'sort_domain_results_by_prio');
            break;
          case 'ttl':
            usort($domains, 'sort_domain_results_by_ttl');
            break;
          default:
            usort($domains, 'sort_domain_results_by_name');
            break;
        }

        $results = array_merge($soa, $ns);
        $results = array_merge($results, $domains);
        
        return $results;
}

/** Sort records by name
 *
 * @param mixed[] $a A
 * @param mixed[] $b B
 *
 * @return mixed[] result of strnatcmp
 */
function sort_domain_results_by_name($a, $b) {
	return strnatcmp($a['name'], $b['name']);
}

/** Sort records by type
 *
 * @param mixed[] $a A
 * @param mixed[] $b B
 *
 * @return mixed[] result of strnatcmp
 */
function sort_domain_results_by_type($a, $b) {
	if ($a['type'] != $b['type']) {
		return strnatcmp($a['type'], $b['type']);
	} else {
		return strnatcmp($a['name'], $b['name']);
	}
}

/** Sort records by content
 *
 * @param mixed[] $a A
 * @param mixed[] $b B
 *
 * @return mixed[] result of strnatcmp
 */
function sort_domain_results_by_content($a, $b) {
	if ($a['content'] != $b['content']) {
		return strnatcmp($a['content'], $b['content']);
	} else {
		return strnatcmp($a['name'], $b['name']);
	}
}

/** Sort records by prio
 *
 * @param mixed[] $a A
 * @param mixed[] $b B
 *
 * @return mixed[] result of strnatcmp
 */
function sort_domain_results_by_prio($a, $b) {
	if ($a['prio'] != $b['prio']) {
		return strnatcmp($a['prio'], $b['prio']);
	} else {
		return strnatcmp($a['name'], $b['name']);
	}
}

/** Sort records by TTL
 *
 * @param mixed[] $a A
 * @param mixed[] $b B
 *
 * @return mixed[] result of strnatcmp
 */
function sort_domain_results_by_ttl($a, $b) {
	if ($a['ttl'] != $b['ttl']) {
		return strnatcmp($a['ttl'], $b['ttl']);
	} else {
		return strnatcmp($a['name'], $b['name']);
	}
}

/** Get list of owners for Domain ID
 *
 * @param int $id Domain ID
 *
 * @return mixed[] array of owners [id,fullename]
 */
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

/** Search for Zone or Record
 *
 * @param string $holy_grail  String to search
 * @param string $perm User permitted to view 'all' or 'own' zones
 * @param string $zone_sortby Column to sort domain results [default='name']
 * @param string $record_sortby Column to sort record results by [default='name']
 *
 * @return mixed[] 'zones' => array of zones, 'records' => array of records 
 */
function search_zone_and_record($holy_grail,$perm,$zone_sortby='name',$record_sortby='name') {
	
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

        if ($perm == "all") {
                $sql_add_from = ", zones, users ";
                $sql_add_where = " AND zones.domain_id = domains.id AND users.id = " . $db->quote($_SESSION['userid'], 'integer');
	}
        
	if ($perm == "own") {
                $sql_add_from = ", zones, users ";
                $sql_add_where = " AND zones.domain_id = domains.id AND users.id = " . $db->quote($_SESSION['userid'], 'integer') . " AND zones.owner = " . $db->quote($_SESSION['userid'], 'integer');
	}
	$query = "SELECT 
			domains.id AS zid,
			domains.name AS name,
			domains.type AS type,
			domains.master AS master,
                        zones.owner AS owner
			FROM domains" . $sql_add_from . "
			WHERE domains.name LIKE " . $db->quote($holy_grail, 'text')
			. $sql_add_where . "
                        ORDER BY " . $zone_sortby;

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

    $cached_owners = array();
	while ($r = $response->fetchRow()) {
        $owner = '';
        if (isset($cached_owners[$r['owner']])) {
            $owner = $cached_owners[$r['owner']];
        } else {
            $owner = get_owner_from_id($r['owner']);
            $cached_owners[$r['owner']] = $owner;
        }

		$return_zones[] = array(
			"zid"		=>	$r['zid'],
			"name"		=>	$r['name'],
			"type"		=>	$r['type'],
			"master"	=>	$r['master'],
			"owner"		=>	$owner);
	}

	$sql_add_from = '';
        $sql_add_where = '';

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
			. $sql_add_where . "
			ORDER BY " . $record_sortby; 

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

/** Get Domain Type for Domain ID
 *
 * @param int $id Domain ID
 *
 * @return string Domain Type [NATIVE,MASTER,SLAVE]
 */
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

/** Get Slave Domain's Master
 *
 * @param int $id Domain ID
 *
 * @return string Master server
 */
function get_domain_slave_master($id){
	global $db;
        if (is_numeric($id)) {
		$slave_master = $db->queryOne("SELECT master FROM domains WHERE type = 'SLAVE' and id = ".$db->quote($id, 'integer'));
		return $slave_master;
        } else {
                error(sprintf(ERR_INV_ARG, "get_domain_slave_master", "no or no valid zoneid given"));
        }
}

/** Change Zone Type 
 *
 * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
 * @param int $id Zone ID
 *
 * @return null
 */
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

/** Change Slave Zone's Master IP Address
 *
 * @param int $zone_id Zone ID
 * @param string $ip_slave_master Master IP Address
 *
 * @return null
 */
function change_zone_slave_master($zone_id, $ip_slave_master) {
	global $db;
        if (is_numeric($zone_id)) {
		if (are_multipe_valid_ips($ip_slave_master)) {
			$result = $db->query("UPDATE domains SET master = " .$db->quote($ip_slave_master, 'text'). " WHERE id = ".$db->quote($zone_id, 'integer'));
		} else {
			error(sprintf(ERR_INV_ARGC, "change_domain_ip_slave_master", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
		}
	} else {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}

/** Get Serial for Zone ID
 *
 * @param int $zid Zone ID
 *
 * @return boolean|string Serial Number or false if not found
 */
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

/** Validate Account is valid string
 *
 * @param string $account Account name alphanumeric and ._-
 *
 * @return boolean true is valid, false otherwise
 */
function validate_account($account) {
  	if(preg_match("/^[A-Z0-9._-]+$/i",$account)) {
		return true;
	} else {
		return false;
	}
}

/** Get Zone Template ID for Zone ID
 *
 * @param int $zone_id Zone ID
 *
 * @return int Zone Template ID
 */
function get_zone_template($zone_id) {
	global $db;
	$query = "SELECT zone_templ_id FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
	$comment = $db->queryOne($query);
	return $comment;
}

/** Update Zone Templatea ID for Zone ID
 * 
 * @param int $zone_id Zone ID
 * @param int @new_zone_template_id New Zone Template ID
 *
 * @return boolean true on success, false otherwise
 */
function update_zone_template($zone_id, $new_zone_template_id) {
        global $db;
	$query = "UPDATE zones
			SET zone_templ_id = " . $db->quote($new_zone_template_id, 'integer') . "
			WHERE id = " . $db->quote($zone_id, 'integer') ;
	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
        return true;
}

/** Update All Zone Records for Zone ID with Zone Template
 *
 * @param int $zone_id Zone ID to update
 * @param int $zone_template Zone Template to use for update
 *
 * @return null
 */
function update_zone_records($zone_id, $zone_template) {
        global $db;
        global $dns_ns1;
        global $dns_hostmaster;
        global $dns_ttl;

	if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; }
	elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ; }
	else { $perm_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);

        if(verify_permission('zone_master_add')) { $zone_master_add = "1" ; } ;
	if(verify_permission('zone_slave_add')) { $zone_slave_add = "1" ; } ;

        $response = $db->beginTransaction();

        if (0 != $zone_template) {
                        if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) { 
                                if (is_numeric($zone_id)) { 
                                    //TODO: account for non-template records?
                                    $db->exec("DELETE FROM records WHERE domain_id=".$db->quote($zone_id, 'integer')); 
                                } else { 
                                    error(sprintf(ERR_INV_ARGC, "delete_domain", "id must be a number")); 
                                } 
                        } else { 
                                error(ERR_PERM_DEL_ZONE); 
                        }

                        if($zone_master_add == "1" || $zone_slave_add == "1") {
                                $domain = get_zone_name_from_id($zone_id);
                                $now = time();
                                $templ_records = get_zone_templ_records($zone_template);

				if ($templ_records == -1) return; 

                                foreach ($templ_records as $r) {
          //fixme: appears to be a bug and regex match should occur against $domain
					if ((preg_match('/in-addr.arpa/i', $zone_id) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $zone_id)))
                                        {
						$name     = parse_template_value($r["name"], $domain);
						$type     = $r["type"];
						$content  = parse_template_value($r["content"], $domain);
						$ttl      = $r["ttl"];
                                       	        $prio     = intval($r["prio"]);

                       	                        if (!$ttl) {
                               	                        $ttl = $dns_ttl;
               	                                }

       	                                        $query = "INSERT INTO records (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
							. $db->quote($zone_id, 'integer') . ","
							. $db->quote($name, 'text') . ","
							. $db->quote($type, 'text') . ","
							. $db->quote($content, 'text') . ","
							. $db->quote($ttl, 'integer') . ","
							. $db->quote($prio, 'integer') . ","
							. $db->quote($now, 'integer') . ")";
               	                                $response = $db->exec($query);
					}
				}
                        }
        }

        $query = "UPDATE zones
                    SET zone_templ_id = " . $db->quote($zone_template, 'integer') . "
                    WHERE domain_id = " . $db->quote($zone_id, 'integer') ;
	$response = $db->exec($query);

        if (PEAR::isError($response)) {
            $response = $db->rollback();
        } else {
            $response = $db->commit();
        }
}

/** Delete array of domains
 *
 * Deletes a domain by a given id.
 * Function always succeeds. If the field is not found in the database, thats what we want anyway.
 *
 * @param int[] $domains Array of Domain IDs to delete
 *
 * @return boolean true on success, false otherwise
 */
function delete_domains($domains)
{
	global $db;
        $error = false;
        $return = false;
        $response = $db->beginTransaction();

        foreach ($domains as $id) {
                if (verify_permission('zone_content_edit_others')) { $perm_edit = "all" ; }
                elseif (verify_permission('zone_content_edit_own')) { $perm_edit = "own" ; }
                else { $perm_edit = "none" ; }
                $user_is_zone_owner = verify_user_is_owner_zoneid($id);

                if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {
                        if (is_numeric($id)) {
                                $db->exec("DELETE FROM zones WHERE domain_id=".$db->quote($id, 'integer'));
                                $db->exec("DELETE FROM domains WHERE id=".$db->quote($id, 'integer'));
                                $db->exec("DELETE FROM records WHERE domain_id=".$db->quote($id, 'integer'));
                        } else {
                                error(sprintf(ERR_INV_ARGC, "delete_domains", "id must be a number"));
                                $error = true;
                        }
                } else {
                        error(ERR_PERM_DEL_ZONE);
                        $error = true;
                }
        }

        if (PEAR::isError($response)) {
            $response = $db->rollback();
            $commit = false;
        } else {
            $response = $db->commit();
            $commit = true;
        }

        if (true == $commit && false == $error) {
            $return = true;
        }

        return $return;
}

/** Execute PDNSSEC rectify-zone command for Domain ID
 *
 * If a Domain is dnssec enabled, or uses features as 
 * e.g. ALSO-NOTIFY, ALLOW-AXFR-FROM, TSIG-ALLOW-AXFR
 * following has to be executed
 * pdnssec rectify-zone $domain 
 *
 * @param int $domain_id Domain ID
 *
 * @return boolean true on success, false on failure or unnecessary
 */
function do_rectify_zone ($domain_id) {
	global $db;
	global $pdnssec_command;

	$output = array();

	/* if pdnssec_command is set we perform ``pdnssec rectify-zone $domain`` on all zones,
	 * as pdns needs the "auth" column for all zones if dnssec is enabled
	 *
	 * If there is any entry at domainmetadata table for this domain,
	 * it is an error if pdnssec_command is not set */
	$query = "SELECT COUNT(id) FROM domainmetadata WHERE domain_id = " . $db->quote($domain_id, 'integer');
	$count = $db->queryOne($query);

	if (PEAR::isError($count)) { error($count->getMessage()); return false; }

	if (isset($pdnssec_command)) {
		$domain = get_zone_name_from_id($domain_id);
		$command = $pdnssec_command . " rectify-zone " . $domain;

		if (!function_exists('exec')) { error(ERR_EXEC_NOT_ALLOWED); return false; }

		if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
			error(ERR_EXEC_PDNSSEC);
			return false;
		}

		exec($command, $output, $return_code);
		if ($return_code != 0) {
			/* if rectify-zone failed: display error */
			error(ERR_EXEC_PDNSSEC_RECTIFY_ZONE);
			return false;
		}

		return true;
	} else if ($count >= 1) {
		error(ERR_EXEC_PDNSSEC);
		return false;
	} else {
		/* no rectify-zone has to be done or command is not
		 * configured in inc/config.inc.php */
		return false;
	}
}

/** Execute PDNSSEC secure-zone command for Domain Name
 *
 * @param string $domain_name Domain Name
 *
 * @return boolean true on success, false on failure or unnecessary
 */
function do_secure_zone($domain_name) {
    global $pdnssec_command;

    if (!function_exists('exec')) { error(ERR_EXEC_NOT_ALLOWED); return false; }

    if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
        error(ERR_EXEC_PDNSSEC);
        return false;
    }

    $command = $pdnssec_command . " secure-zone " . $domain_name;
    exec($command, $output, $return_code);

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SECURE_ZONE);
        return false;
    }

    return true;
}

?>
