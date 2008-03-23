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

function count_zone_records($zone_id) {
	global $db;
	$sqlq = "SELECT COUNT(id) FROM records WHERE domain_id = ".$db->quote($zone_id);
	$record_count = $db->queryOne($sqlq);
	return $record_count;
}

function update_soa_serial($domain_id)
{
    global $db;
	/*
	 * THIS CODE ISNT TESTED THROUGH MUCH YET!
	 * !!!!!!! BETACODE !!!!!!!!!!
	 * Code committed by DeViCeD, Thanks a lot!
	 * Heavily hax0red by Trancer/azurazu
	 *
	 * First we have to check, wheather current searial number 
	 * was already updated on the other nameservers.
	 * If field 'notified_serial' is NULL, then I guess domain is
	 * NATIVE and we don't have any secondary nameservers for this domain.
	 * NOTICE: Serial number *will* be RFC1912 compilant after update 
	 * NOTICE: This function will allow only 100 DNS zone transfers ;-)
	 * YYYYMMDDnn
	 */

	$sqlq = "SELECT notified_serial FROM domains WHERE id = ".$db->quote($domain_id);
	$notified_serial = $db->queryOne($sqlq);

	$sqlq = "SELECT content FROM records WHERE type = 'SOA' AND domain_id = ".$db->quote($domain_id);
	$content = $db->queryOne($sqlq);
        $need_to_update = false;
	
	// Getting the serial field.
	$soa = explode(" ", $content);
	
	if(empty($notified_serial))
    {
        // Ok native replication, so we have to update.
        $need_to_update = true;
    }
    elseif($notified_serial >= $soa[2])
    {
        $need_to_update = true;
    }
    elseif(strlen($soa[2]) != 10)
    {
        $need_to_update = true;
    }
    else
    {
        $need_to_update = false;
    }
    if($need_to_update)
    {
        // Ok so we have to update it seems.
        $current_serial = $soa[2];
        
		/*
		 * What we need here (for RFC1912) is YEAR, MONTH and DAY
		 * so let's get it ...
		 */
		$new_serial = date('Ymd'); // we will add revision number later

		if(strncmp($new_serial, $current_serial, 8) === 0)
		{
            /*
             * Ok, so we already made updates tonight
             * let's just increase the revision number
             */				
            $revision_number = (int) substr($current_serial, -2);
            if ($revision_number == 99) return false; // ok, we cannot update anymore tonight
            ++$revision_number;
            // here it is ... same date, new revision
            $new_serial .= str_pad($revision_number, 2, "0", STR_PAD_LEFT);	
		}
 		else
		{
            /*
			 * Current serial is not RFC1912 compilant, so let's make a new one
			 */
 			$new_serial .= '00';
		}
        $soa[2] = $new_serial; // change serial in SOA array
		$new_soa = "";		
		// build new soa and update SQL after that
		for ($i = 0; $i < count($soa); $i++) 
		{	
			$new_soa .= $soa[$i] . " "; 
		}
		$sqlq = "UPDATE records SET content = ".$db->quote($new_soa)." WHERE domain_id = ".$db->quote($domain_id)." AND type = 'SOA'";
		$db->Query($sqlq);
		return true;
	}
}  

/*
 * Edit a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function edit_record($record) {

	if (verify_permission(zone_content_edit_others)) { $perm_content_edit = "all" ; }
	elseif (verify_permission(zone_content_edit_own)) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }


	$user_is_zone_owner = verify_user_is_owner_zoneid($record['zid']);
	$zone_type = get_domain_type($record['zid']);

	if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		return _("You are not allowed to edit this record.") ; //TODO i18n
	} else {
		if($record['content'] == "") {
			return _("Error: content field may not be empty.") ; //TODO i18n
		}
		global $db;
		// TODO: no need to check for numeric-ness of zone id if we check with validate_input as well?
		if (is_numeric($record['zid'])) {
			validate_input($record['zid'], $record['type'], $record['content'], $record['name'], $record['prio'], $record['ttl']);
			$query = "UPDATE records 
					SET name=".$db->quote($record['name']).", 
					type=".$db->quote($record['type']).", 
					content=".$db->quote($record['content']).", 
					ttl=".$db->quote($record['ttl']).", 
					prio=".$db->quote($record['prio']).", 
					change_date=".$db->quote(time())." 
					WHERE id=".$db->quote($record['rid']);
			$result = $db->Query($query);
			if (PEAR::isError($result)) {
				error($result->getMessage());
				return false;
			} elseif ($record['type'] != 'SOA') {
				update_soa_serial($record['zid']);
			}
			return true;
		}
		else
		{
			// TODO change to error style as above (returning directly)
			error(sprintf(ERR_INV_ARGC, "edit_record", "no zoneid given"));
		}
	}
	return true;
}


function add_record_owner($zoneid,$userid,$recordid)
{
	global $db;
	if (!xs($zoneid))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($zoneid) || is_numeric($userid) || is_numeric($recordid))
	{
		$db->query("INSERT INTO record_owners (user_id, record_id) VALUES (".$db->quote($userid).", ".$db->quote($recordid).")");
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "add_record_owner", "at least one of the arguments is not numeric"));
	}
}

function delete_record_owner($zoneid,$rowid,$recordid)
{
	global $db;
	if (!xs($zoneid))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($zoneid) || is_numeric($rowid) || is_numeric($recordid))
	{
		$db->query("DELETE FROM record_owners WHERE id=".$db->quote($rowid)." AND record_id=".$db->quote($recordid));
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "delete_record_owner", "at least one of the arguments is not numeric"));
	}
}

/*
 * Adds a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function add_record($zoneid, $name, $type, $content, $ttl, $prio) {
	global $db;

	if (verify_permission(zone_content_edit_others)) { $perm_content_edit = "all" ; }
	elseif (verify_permission(zone_content_edit_own)) { $perm_content_edit = "own" ; }
	else { $perm_content_edit = "none" ; }

	$user_is_zone_owner = verify_user_is_owner_zoneid($zoneid);
	$zone_type = get_domain_type($zoneid);

        if ( $zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0" ) {
		error(ERR_PERM_ADD_RECORD);
		return false;
	} else {
		if (validate_input($zoneid, $type, $content, $name, $prio, $ttl) ) {
			$change = time();
			$query = "INSERT INTO records VALUES ('',"
						. $db->quote($zoneid) . ","
						. $db->quote($name) . "," 
						. $db->quote($type) . "," 
						. $db->quote($content) . ","
						. $db->quote($ttl) . ","
						. $db->quote($prio) . ","
						. $db->quote($change) . ")";
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
        if (!is_valid_ip($master_ip) && !is_valid_ip6($master_ip)) {
                error(ERR_DNS_IP);
		return false;
        }
        if (!is_valid_hostname($ns_name)) {
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
                $db->query("INSERT INTO supermasters VALUES (".$db->quote($master_ip).", ".$db->quote($ns_name).", ".$db->quote($account).")");
                return true;
        }
}

function delete_supermaster($master_ip) {
	global $db;
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
        {
                $db->query("DELETE FROM supermasters WHERE ip = ".$db->quote($master_ip));
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
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
	{
	        $result = $db->queryRow("SELECT ip,nameserver,account FROM supermasters WHERE ip = ".$db->quote($master_ip));

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


/*
 * Delete a record by a given id.
 * return values: true, this function is always succesful.
 */
function delete_record($id)
{
	global $db;

	// Check if the user has access.
	if (!xs(recid_to_domid($id)))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}

	// Retrieve the type of record to see if we can actually remove it.
	$recordtype = get_recordtype_from_id($id);

	// If the record type is NS and the user tries to delete it while ALLOW_NS_EDIT is set to 0
	// OR
	// check if the name of the record isnt the domain name (if so it should delete all records)
	// OR
	// check if we are dealing with a SOA field (same story as NS)
	if (($recordtype == "NS" && $GLOBALS["ALLOW_NS_EDIT"] != 1 && (get_name_from_record_id($id) == get_domain_name_from_id(recid_to_domid($id)))) || ($recordtype == "SOA" && $GLOBALS["ALLOW_SOA_EDIT"] != 1))
	{
		error(sprintf(ERR_RECORD_DELETE_TYPE_DENIED, $recordtype));

	}
	if (is_numeric($id))
	{
	    $did = recid_to_domid($id);
		$db->query('DELETE FROM records WHERE id=' . $db->quote($id) );
		if ($type != 'SOA')
		{
			update_soa_serial($did);
		}
        // $id doesnt exist in database anymore so its deleted or just not there which means "true"	
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "delete_record"));
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
	if(verify_permission(zone_master_add)) { $zone_master_add = "1" ; } ;
	if(verify_permission(zone_slave_add)) { $zone_slave_add = "1" ; } ;

	// TODO: make sure only one is possible if only one is enabled
	if($zone_master_add == "1" || $zone_master_add == "1") {

		global $db;
		if (($domain && $owner && $webip && $mailip) || 
				($empty && $owner && $domain) || 
				(eregi('in-addr.arpa', $domain) && $owner) || 
				$type=="SLAVE" && $domain && $owner && $slave_master) {

			$response = $db->query("INSERT INTO domains (name, type) VALUES (".$db->quote($domain).", ".$db->quote($type).")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			$domain_id = $db->lastInsertId('domains', 'id');
			if (PEAR::isError($domain_id)) { error($id->getMessage()); return false; }

			$response = $db->query("INSERT INTO zones (domain_id, owner) VALUES (".$db->quote($domain_id).", ".$db->quote($owner).")");
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			if ($type == "SLAVE") {
				$response = $db->query("UPDATE domains SET master = ".$db->quote($slave_master)." WHERE id = ".$db->quote($domain_id));
				if (PEAR::isError($response)) { error($response->getMessage()); return false; }
				return true;
			} else {
				$now = time();
				if ($empty && $domain_id) {
					$ns1 = $GLOBALS['NS1'];
					$hm  = $GLOBALS['HOSTMASTER'];
					$ttl = $GLOBALS['DEFAULT_TTL'];

					$query = "INSERT INTO records VALUES (''," 
							. $db->quote($domain_id) . "," 
							. $db->quote($domain) . "," 
							. $db->quote($ns1.' '.$hm.' 1') 
							. ",'SOA',"
							. $db->quote($ttl) 
							. ", 0, "
							. $db->quote($now).")";
					$response = $db->query($query);
					if (PEAR::isError($response)) { error($response->getMessage()); return false; }
				} elseif ($domain_id) {
					global $template;

					foreach ($template as $r) {
						if ((eregi('in-addr.arpa', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!eregi('in-addr.arpa', $domain)))
						{
							$name     = parse_template_value($r["name"], $domain, $webip, $mailip);
							$type     = $r["type"];
							$content  = parse_template_value($r["content"], $domain, $webip, $mailip);
							$ttl      = $r["ttl"];
							$prio     = intval($r["prio"]);

							if (!$ttl) {
								$ttl = $GLOBALS["DEFAULT_TTL"];
							}

							$query = "INSERT INTO records VALUES (''," 
									. $db->quote($domain_id) . ","
									. $db->quote($name) . ","
									. $db->quote($content) . ","
									. $db->quote($type) . ","
									. $db->quote($ttl) . ","
									. $db->quote($prio) . ","
									. $db->quote($now) . ")";
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

	if (verify_permission(zone_content_edit_others)) { $perm_edit = "all" ; }
	elseif (verify_permission(zone_content_edit_own)) { $perm_edit = "own" ; }
	else { $perm_edit = "none" ; }
	$user_is_zone_owner = verify_user_is_owner_zoneid($id);

        if ( $perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1") ) {    
		if (is_numeric($id)) {
			$db->query("DELETE FROM zones WHERE domain_id=".$db->quote($id));
			$db->query("DELETE FROM domains WHERE id=".$db->quote($id));
			$db->query("DELETE FROM records WHERE domain_id=".$db->quote($id));
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
		$result = $db->query("SELECT domain_id FROM records WHERE id=".$db->quote($id));
		$r = $result->fetchRow();
		return $r["domain_id"];
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "recid_to_domid", "id must be a number"));
	}
}


/*
 * Sorts a zone by records.
 * return values: the sorted zone.
 */
function sort_zone($records)
{
	$ar_so = array();
	$ar_ns = array();
	$ar_mx = array();
	$ar_mb = array();
	$ar_ur = array();
	$ar_ov = array();
	foreach ($records as $c)
	{
		switch(strtoupper($c['type']))
		{
			case "SOA":
				$ar_so[] = $c;
				break;
			case "NS":
				$ar_ns[] = $c;
				break;
			case "MX":
				$ar_mx[] = $c;
				break;
			case "MBOXFW":
				$ar_mb[] = $c;
				break;
			case "URL":
				$ar_ur[] = $c;
				break;
			default:
				$ar_ov[] = $c;
				break;
		}
	}

	$res = array_merge($ar_so, $ar_ns, $ar_mx, $ar_mb, $ar_ur, $ar_ov);

	if (count($records) == count($res))
	{
		$records = $res;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "sort_zone", "records sorting failed!"));
	}
	return $records;
}


/*
 * Change owner of a domain.
 * return values: true when succesful.
 */
function add_owner_to_zone($zone_id, $user_id)
{
	global $db;
	if ( (verify_permission(zone_meta_edit_others)) || (verify_permission(zone_meta_edit_own)) && verify_user_is_owner_zoneid($_GET["id"])) {
		// User is allowed to make change to meta data of this zone.
		if (is_numeric($zone_id) && is_numeric($user_id) && is_valid_user($user_id))
		{
			if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=".$db->quote($user_id)." AND domain_id=".$db->quote($zone_id)) == 0)
			{
				$db->query("INSERT INTO zones (domain_id, owner) VALUES(".$db->quote($zone_id).", ".$db->quote($user_id).")");
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
	if ( (verify_permission(zone_meta_edit_others)) || (verify_permission(zone_meta_edit_own)) && verify_user_is_owner_zoneid($_GET["id"])) {
		// User is allowed to make change to meta data of this zone.
		if (is_numeric($zone_id) && is_numeric($user_id) && is_valid_user($user_id))
		{
			// TODO: Next if() required, why not just execute DELETE query?
			if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=".$db->quote($user_id)." AND domain_id=".$db->quote($zone_id)) != 0)
			{
				$db->query("DELETE FROM zones WHERE owner=".$db->quote($user_id)." AND domain_id=".$db->quote($zone_id));
			}
			return true;
		} else {
			error(sprintf(ERR_INV_ARGC, "delete_owner_from_zone", "$zone_id / $user_id"));
		}
	} else {
		return false;
	}
	
}

function delete_owner($domain, $owner)
{
	global $db;
	if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=".$db->quote($owner)." AND domain_id=".$db->quote($domain)) != 0)
	{
		$db->query("DELETE FROM zones WHERE owner=".$db->quote($owner)." AND domain_id=".$db->quote($domain));
	}
	return true;
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

	$result = $db->query("select id, type from records where domain_id=".$db->quote($recid)." and type=".$db->quote($type));
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
		$result = $db->query("SELECT type FROM records WHERE id=".$db->quote($id));
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
	if (is_numeric($id))
	{
		$result = $db->query("SELECT name FROM records WHERE id=".$db->quote($id));
		$r = $result->fetchRow();
		return $r["name"];
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "get_name_from_record_id"));
	}
}


/*
 * Get all the domains from a database of which the user is the owner.
 * return values: an array with the id of the domain and its name.
 */
function get_domains_from_userid($id)
{
	global $db;
	if (is_numeric($id))
	{
		$a_zones = array();

		// Check for zones the user has full access for (the 
		// user is owner of the zone.

		$res_full = $db->query("SELECT 
					domains.id AS domain_id, 
					domains.name AS name 
					FROM domains 
					LEFT JOIN zones ON domains.id=zones.domain_id 
					WHERE owner=".$db->quote($id)); 
		
		// Process the output.

		$numrows = $res_full->numRows();
		$i=1;
		if ($numrows > 0) 
		{
			$andnot=" AND NOT domains.id IN (";
			while($r = $res_full->fetchRow()) {
				
				// Create array of zone id's and name's the owner
				// has full access to.

				$a_zones[] = array(
				"id"            =>              $r["domain_id"],
				"name"          =>              $r["name"],
				"partial"	=>		"0"
				);

				// Create AND NOT for query of zones the user has 
				// only partial access to. In that query we just 
				// want to see the zones he has not full access to 
				// as well.

				$andnot.=$db->quote($r["domain_id"]);
				if ($i < $numrows) {
					$andnot.=",";
					$i++;
				}

			}
			$andnot.=")";
		}
		else
		{
			$andnot="";
		}

		// Check for zones the user has partial access only to.

		$res_partial = $db->query("SELECT DISTINCT 
					records.domain_id, 
					domains.name 
					FROM records, record_owners, domains 
					WHERE record_owners.user_id = ".$db->quote($id)." 
					AND records.id = record_owners.record_id 
					AND domains.id = records.domain_id
					".$andnot);
		
		// Add these zones to the array as well.

		while ($r = $res_partial->fetchRow())
		{
			$a_zones[] = array(
			"id"            =>              $r["domain_id"],
			"name"          =>              $r["name"],
			"partial"	=>		"1"
			);
		}

		return $a_zones;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "get_domains_from_userid", "This is not a valid userid: $id"));
	}
}


/*
 * Get domain name from a given id
 * return values: the name of the domain associated with the id.
 */
function get_domain_name_from_id($id)
{
	global $db;

	if (is_numeric($id))
	{
		$result = $db->query("SELECT name FROM domains WHERE id=".$db->quote($id));
		$rows = $result->numRows() ;
		if ($rows == 1) {
 			$r = $result->fetchRow();
 			return $r["name"];
		} elseif ($rows == "0") {
			error(sprintf("Zone does not exist."));
			return false;
		} else {
	 		error(sprintf(ERR_INV_ARGC, "get_domain_name_from_id", "more than one domain found?! whaaa! BAD! BAD! Contact admin!"));
			return false;
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "get_domain_name_from_id", "Not a valid domainid: $id"));
	}
}

function get_zone_info_from_id($zone_id) {

	if (verify_permission(zone_content_view_others)) { $perm_view = "all" ; } 
	elseif (verify_permission(zone_content_view_own)) { $perm_view = "own" ; }
	else { $perm_view = "none" ;}

	if ($perm_view == "none") { 
		error(ERR_PERM_VIEW_ZONE);
	} else {
		global $db;

		$query = "SELECT 	domains.type AS type, 
					domains.name AS name, 
					domains.master AS master_ip,
					count(records.domain_id) AS record_count
					FROM domains, records 
					WHERE domains.id = " . $db->quote($zone_id) . "
					AND domains.id = records.domain_id 
					GROUP BY domains.id";

		$response = $db->queryRow($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }
		$return = array(
			"name"		=>	$response['name'],
			"type"		=>	$response['type'],
			"master_ip"	=>	$response['master_ip'],
			"record_count"	=>	$response['record_count']);
		return $return;
	}
}



/*
 * Get information about a domain name from a given domain id.
 * the function looks up the domainname, the owner of the domain and the number of records in it.
 * return values: an array containing the information.
 */
function get_domain_info_from_id($id)
{
	global $db;
	if (!xs($id))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($id))
	{

	if ($_SESSION[$id."_ispartial"] == 1) {
	
	$sqlq = "SELECT 
	domains.type AS type,
	domains.name AS name,
	users.fullname AS owner,
	count(record_owners.id) AS aantal
	FROM domains, users, record_owners, records
	
        WHERE record_owners.user_id = ".$db->quote($_SESSION["userid"])."
        AND record_owners.record_id = records.id
	AND records.domain_id = ".$db->quote($id)."

	GROUP BY domains.name, owner, users.fullname, domains.type
	ORDER BY domains.name";
	
	$result = $db->queryRow($sqlq);

	$ret = array(
	"name"          =>              $result["name"],
	"ownerid"       =>              $_SESSION["userid"],
	"owner"         =>              $result["owner"],
	"type"		=>		$result["type"],
	"numrec"        =>              $result["aantal"]
	);

	return $ret;

	} else{
	
		// Query that retrieves the information we need.
		$sqlq = "SELECT 
			domains.type AS type,
			domains.name AS name,
			min(zones.owner) AS ownerid,
			users.fullname AS owner,
			count(records.domain_id) AS aantal
			FROM domains
			LEFT JOIN records ON domains.id=records.domain_id
			LEFT JOIN zones ON domains.id=zones.domain_id
			LEFT JOIN users ON zones.owner=users.id
			WHERE domains.id=$id
			GROUP BY domains.name, owner, users.fullname, domains.type, zones.id
			ORDER BY zones.id";

		// Put the first occurence in an array and return it.
		$result = $db->queryRow($sqlq);

		//$result["ownerid"] = ($result["ownerid"] == NULL) ? $db->queryOne("select min(id) from users where users.level=10") : $result["ownerid"];

		$ret = array(
		"name"          =>              $result["name"],
		"ownerid"       =>              $result["ownerid"],
		"owner"         =>              $result["owner"],
		"type"          =>              $result["type"],
		"numrec"        =>              $result["aantal"]
		);
		return $ret;
	}

	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "get_domain_num_records_from_id", "This is not a valid domainid: $id"));
	}
}


/*
 * Check if a domain is already existing.
 * return values: true if existing, false if it doesnt exist.
 */
function domain_exists($domain)
{
	global $db;

	if (is_valid_domain($domain)) {
		$result = $db->query("SELECT id FROM domains WHERE name=".$db->quote($domain));
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
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
        {
                $result = $db->query("SELECT ip FROM supermasters WHERE ip = ".$db->quote($master_ip));
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


function get_zones($perm,$userid=true,$letterstart=all,$rowstart=0,$rowamount=999999) 
{
	global $db;
	global $sql_regexp;
	if ($perm != "own" && $perm != "all") {
		// TODO: this should not be possible, but will need some error message
	}
	else
	{
		if ($perm == "own") {
			$sql_add = " AND zones.domain_id = domains.id
				AND zones.owner = ".$db->quote($_SESSION['userid']);
		}
		if ($letterstart!=all && $letterstart!=1) {
			$sql_add .=" AND domains.name LIKE ".$db->quote($letterstart."%")." ";
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
			GROUP BY domains.name, domains.id
			ORDER BY domains.name;";

	$db->setLimit($rowamount, $rowstart);
	$result = $db->query($sqlq);

	while($r = $result->fetchRow())
	{
		$ret[$r["name"]] = array(
		"id"		=>	$r["id"],
		"name"		=>	$r["name"],
		"type"		=>	$r["type"],
		"count_records"	=>	$r["count_records"]
		);	
	}
	return $ret;
}


/*
 * Get all domains from the database 
 * This function gets all the domains from the database unless a user id is below 5.
 * if a user id is below 5 this function will only retrieve records for that user.
 * return values: the array of domains or -1 if nothing is found.
 */
function get_domains($userid=true,$letterstart='all',$rowstart=0,$rowamount=999999)
{
	global $db;
	global $sql_regexp;
	if((!level(5) || !$userid) && !level(10) && !level(5))
	{
		$add = " AND zones.owner=".$db->quote($_SESSION["userid"]);
	}
	else
	{
		$add = "";
	}

	$sqlq = "SELECT domains.id AS domain_id,
	min(zones.owner) AS owner,
	count(DISTINCT records.id) AS aantal,
	domains.name AS domainname
	FROM domains
	LEFT JOIN zones ON domains.id=zones.domain_id 
	LEFT JOIN records ON records.domain_id=domains.id
	WHERE 1=1 $add ";
	if ($letterstart!='all' && $letterstart!=1) {
	   $sqlq.=" AND substring(domains.name,1,1) ".$sql_regexp." ".$db->quote("^".$letterstart);
	} elseif ($letterstart==1) {
	   $sqlq.=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
	}
	$sqlq.=" GROUP BY domainname, domains.id
	ORDER BY domainname";

	$db->setLimit($rowamount, $rowstart);
	$result = $db->query($sqlq);
	// Set limit needs to be called before each query
	$db->setLimit($rowamount, $rowstart);
	$result2 = $db->query($sqlq); 
	
	$numrows = $result2->numRows();
	$i=1;
	if ($numrows > 0) {
		$andnot=" AND NOT domains.id IN (";
		while($r = $result2->fetchRow()) {
			$andnot.=$db->quote($r["domain_id"]);
			if ($i < $numrows) {
				$andnot.=",";
				$i++;
			}
		}
		$andnot.=")";
	}
	else
	{
		$andnot="";
	}

	if ($letterstart!='all' && $letterstart!=1) {

		$sqlq = "SELECT domains.id AS domain_id,
		count(DISTINCT record_owners.record_id) AS aantal,
		domains.name AS domainname
		FROM domains, record_owners,records, zones
		WHERE record_owners.user_id = ".$db->quote($_SESSION["userid"])."
		AND (records.id = record_owners.record_id
		AND domains.id = records.domain_id)
		$andnot 
		AND domains.name LIKE ".$db->quote($letterstart."%")." 
		AND (zones.domain_id != records.domain_id AND zones.owner!=".$db->quote($_SESSION["userid"]).")
		GROUP BY domainname, domains.id
		ORDER BY domainname";

		$result_extra = $db->query($sqlq);

	} else {

		$sqlq = "SELECT domains.id AS domain_id,
		count(DISTINCT record_owners.record_id) AS aantal,
		domains.name AS domainname
		FROM domains, record_owners,records, zones
		WHERE record_owners.user_id = ".$db->quote($_SESSION["userid"])."
		AND (records.id = record_owners.record_id
		AND domains.id = records.domain_id)
		$andnot 
		AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'
		AND (zones.domain_id != records.domain_id AND zones.owner!=".$db->quote($_SESSION["userid"]).")
		GROUP BY domainname, domains.id
		ORDER BY domainname";

		$result_extra[$i] = $db->query($sqlq);

	}

	while($r = $result->fetchRow())
	{
		$r["owner"] = ($r["owner"] == NULL) ? $db->queryOne("select min(id) from users where users.level=10") : $r["owner"];
	     	$ret[$r["domainname"]] = array(
		"name"          =>              $r["domainname"],
		"id"            =>              $r["domain_id"],
		"owner"         =>              $r["owner"],
		"numrec"        =>              $r["aantal"]
		);
	}


	if ($letterstart!='all' && $letterstart!=1) {

		while($r = $result_extra->fetchRow())
		{
		       $ret[$r["domainname"]] = array(
		       "name"          =>              $r["domainname"]."*",
		       "id"            =>              $r["domain_id"],
		       "owner"         =>              $_SESSION["userid"],
		       "numrec"        =>              $r["aantal"]
		       );
		       $_SESSION["partial_".$r["domainname"]] = 1;
		}

	} else {

		foreach ($result_extra as $result_e) {
		while($r = $result_e->fetchRow())
		{
		       $ret[$r["domainname"]] = array(
		       "name"          =>              $r["domainname"]."*",
		       "id"            =>              $r["domain_id"],
		       "owner"         =>              $_SESSION["userid"],
		       "numrec"        =>              $r["aantal"]
		       );
		       $_SESSION["partial_".$r["domainname"]] = 1;
		}
		}

	}

	if (empty($ret)) {
	   return -1;
	} else {
	   sort($ret);
	   return $ret;
	}

}


// TODO: letterstart limitation and userid permission limitiation should be applied at the same time?
function zone_count_ng($perm, $letterstart=all) {
	global $db;
	global $sql_regexp;
	if ($perm != "own" && $perm != "all") {
		$zone_count = "0";
	} 
	else 
	{
		if ($perm == "own") {
			$sql_add = " AND zones.domain_id = domains.id
					AND zones.owner = ".$db->quote($_SESSION['userid']);
		}
		if ($letterstart!=all && $letterstart!=1) {
			$sql_add .=" AND domains.name LIKE ".$db->quote($letterstart."%")." ";
		} elseif ($letterstart==1) {
			$sql_add .=" AND substring(domains.name,1,1) ".$sql_regexp." '^[[:digit:]]'";
		}

		$sqlq = "SELECT COUNT(distinct domains.id) AS count_zones 
			FROM domains,zones 
			WHERE 1=1
			".$sql_add.";";

		$zone_count = $db->queryOne($sqlq);
	}
	return $zone_count;
}

function zone_count_for_uid($uid) {
	global $db;
	$query = "SELECT COUNT(domain_id) 
			FROM zones 
			WHERE owner = " . $db->quote($uid) . " 
			ORDER BY domain_id";
	$zone_count = $db->queryOne($query);
	return $zone_count;
}


/*
 * Get a record from an id.
 * Retrieve all fields of the record and send it back to the function caller.
 * return values: the array with information, or -1 is nothing is found.
 */
function get_record_from_id($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT id, domain_id, name, type, content, ttl, prio, change_date FROM records WHERE id=".$db->quote($id));
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


/*
 * Get all records from a domain id.
 * Retrieve all fields of the records and send it back to the function caller.
 * return values: the array with information, or -1 is nothing is found.
 */
function get_records_from_domain_id($id,$rowstart=0,$rowamount=999999)
{
	global $db;
	if (is_numeric($id))
	{
		if ($_SESSION[$id."_ispartial"] == 1) {
		$db->setLimit($rowamount, $rowstart);
		$result = $db->query("SELECT record_owners.record_id as id
		FROM record_owners,domains,records
		WHERE record_owners.user_id = ".$db->quote($_SESSION["userid"])."
		AND record_owners.record_id = records.id
		AND records.domain_id = ".$db->quote($id)."
		GROUP bY record_owners.record_id");

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
		return $ret;
		}

		} else {
		$db->setLimit($rowamount, $rowstart);
		$result = $db->query("SELECT id FROM records WHERE domain_id=".$db->quote($id));
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
			return $ret;
		}

		}
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "get_records_from_domain_id"));
	}
}


function get_users_from_domain_id($id) {
	global $db;
	$sqlq = "SELECT owner FROM zones WHERE domain_id =" .$db->quote($id);
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


function search_record($question)
{
	global $db;
	$question = trim($question);

	if (is_valid_search($question))
	{
		$sqlq = "SELECT * 
				FROM records 
				WHERE content LIKE ".$db->quote($question)." 
				OR name LIKE ".$db->quote($question)."
				ORDER BY type DESC";
		$result = $db->query($sqlq);
		$ret_r = array();
		while ($r = $result->fetchRow())
		{
		    if(xs($r['domain_id']))
		    {
			$ret_r[] = array(
			  'id'			=>	$r['id'],
			  'domain_id'		=>	$r['domain_id'],
			  'name'		=>	$r['name'],
			  'type'		=>	$r['type'],
			  'content'		=>	$r['content'],
			  'ttl'			=>	$r['ttl'],
			  'prio'		=>	$r['prio'],
			  'change_date'		=>	$r['change_date']
			);
			}
		}

		$sqlq = "SELECT domains.id, domains.name, count(records.id) AS numrec, zones.owner, records.domain_id
				FROM domains LEFT JOIN records ON domains.id = records.domain_id, zones  
				WHERE zones.domain_id = domains.id 
				AND domains.name LIKE ".$db->quote($question)." 
				GROUP BY domains.id, domains.name, zones.owner, records.domain_id";
		$result = $db->query($sqlq);
		$ret_d = array();
		while ($r = $result->fetchRow())
		{
		    if(xs($r['id']))
		    {
			    $ret_d[] = array(
				'id'			=>	$r['id'],
				'name'		=>	$r['name'],
				'numrec'		=>	$r['numrec'],
				'owner'		=>	$r['owner']
			);
			}
		}
		return array('domains' => $ret_d, 'records' => $ret_r);
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "search_record", "Invalid searchstring: $question"));
	}

}

function get_domain_type($id)
{
	global $db;
        if (is_numeric($id))
	{
		$type = $db->queryOne("SELECT type FROM domains WHERE id = ".$db->quote($id));
		if($type == "")
		{
			$type = "NATIVE";
		}
		return $type;
        }
        else
        {
                error(sprintf(ERR_INV_ARG, "get_record_from_id", "no or no valid zoneid given"));
        }
}

function get_domain_slave_master($id)
{
	global $db;
        if (is_numeric($id))
	{
		$slave_master = $db->queryOne("SELECT master FROM domains WHERE type = 'SLAVE' and id = ".$db->quote($id));
		return $slave_master;
        }
        else
        {
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
		if ($type != "SLAVE")
		{
			$add = ", master=''";
		}
		$result = $db->query("UPDATE domains SET type = " .$db->quote($type). $add." WHERE id = ".$db->quote($id));
	}
        else
        {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}

function change_zone_slave_master($zone_id, $ip_slave_master)
{
	global $db;
        if (is_numeric($zone_id))
	{
       		if (is_valid_ip($ip_slave_master) || is_valid_ip6($ip_slave_master))
		{
			$result = $db->query("UPDATE domains SET master = " .$db->quote($ip_slave_master). " WHERE id = ".$db->quote($zone_id));
		}
		else
		{
			error(sprintf(ERR_INV_ARGC, "change_domain_ip_slave_master", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
		}
	}
        else
        {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}


function validate_account($account)
{
	
  	if(preg_match("/^[A-Z0-9._-]+$/i",$account))
	{
		return true;
	}
	else
	{
		return false;
	}
}
?>
