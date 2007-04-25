<?

// +--------------------------------------------------------------------+
// | PowerAdmin                                                         |
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team                        |
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal   |
// | program PowerAdmin as found on http://poweradmin.sf.net            |
// | The PowerAdmin program falls under the QPL License:                |
// | http://www.trolltech.com/developer/licensing/qpl.html              |
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>       |
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>                      |
// +--------------------------------------------------------------------+

// Filename: record.inc.php
// Startdate: 26-10-2002
// This file is ought to edit the records in the database.
// Records are domains aswell, they also belong here.
// All database functions are associative.
// use nextID first to get a new id. Then insert it into the database.
// do not rely on auto_increment (see above).
// use dns.inc.php for validation functions.
//
// $Id: record.inc.php,v 1.21 2003/05/10 20:21:01 azurazu Exp $
//


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

	$sqlq = "SELECT `notified_serial` FROM `domains` WHERE `id` = '".$domain_id."'";
	$notified_serial = $db->queryOne($sqlq);

	$sqlq = "SELECT `content` FROM `records` WHERE `type` = 'SOA' AND `domain_id` = '".$domain_id."'";
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
		$sqlq = "UPDATE `records` SET `content` = '".$new_soa."' WHERE `domain_id` = '".$domain_id."' AND `type` = 'SOA' LIMIT 1";
		$db->Query($sqlq);
		return true;
	}
}  

/*
 * Edit a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function edit_record($recordid, $zoneid, $name, $type, $content, $ttl, $prio)
{
	global $db;
  	if($content == "")
  	{
  		error(ERR_RECORD_EMPTY_CONTENT);
  	}
  	// Edits the given record (validates specific stuff first)
	if (!xs(recid_to_domid($recordid)))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($zoneid))
	{
		validate_input($recordid, $zoneid, $type, $content, $name, $prio, $ttl);
                $change = time();
                $db->query("UPDATE records set name='$name', type='$type', content='$content', ttl='$ttl', prio='$prio', change_date='$change' WHERE id=$recordid");
		
		/*
		 * Added by DeViCeD - Update SOA Serial number
		 * There should be more checks
		 */
		if ($type != 'SOA')
		{
			update_soa_serial($zoneid);
		}
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "edit_record", "no zoneid given"));
	}

}


/*
 * Adds a record.
 * This function validates it if correct it inserts it into the database.
 * return values: true if succesful.
 */
function add_record($zoneid, $name, $type, $content, $ttl, $prio)
{

	global $db;
	if (!xs($zoneid))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($zoneid))
	{
		// Check the user input.
		validate_input($zoneid, $type, $content, $name, $prio, $ttl);

		// Generate new timestamp for the daemon
		$change = time();
		
		// Execute query.
		$db->query("INSERT INTO records (domain_id, name, type, content, ttl, prio, change_date) VALUES ($zoneid, '$name', '$type', '$content', $ttl, '$prio', $change)");
		if ($type != 'SOA')
		{
			update_soa_serial($zoneid);
		}
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "add_record"));
	}
}


function add_supermaster($master_ip, $ns_name, $account)
{
        global $db;
        if (!is_valid_ip($master_ip) && !is_valid_ip6($master_ip))
        {
                error(sprintf(ERR_INV_ARGC, "add_supermaster", "No or no valid ipv4 or ipv6 address given."));
        }
        if (!is_valid_hostname($ns_name))
        {
                error(ERR_DNS_HOSTNAME);
        }
	if (!validate_account($account))
	{
		error(sprintf(ERR_INV_ARGC, "add_supermaster", "given account name is invalid (alpha chars only)"));
	}
        if (supermaster_exists($master_ip))
        {
                error(sprintf(ERR_INV_ARGC, "add_supermaster", "supermaster already exists"));
        }
        else
        {
                $db->query("INSERT INTO supermasters VALUES ('$master_ip', '$ns_name', '$account')");
                return true;
        }
}

function delete_supermaster($master_ip)
{
        global $db;
        if (!level(5))
        {
                error(ERR_LEVEL_5);
        }
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
        {
                $db->query("DELETE FROM supermasters WHERE ip = '$master_ip'");
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
        if (!level(5))
        {
                error(ERR_LEVEL_5);
        }
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
	{
	        $result = $db->queryRow("SELECT ip,nameserver,account FROM supermasters WHERE ip = '$master_ip'");

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
		$db->query('DELETE FROM records WHERE id=' . $id . ' LIMIT 1');
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

	global $db;

	if (!level(5))
	{
		error(ERR_LEVEL_5);
	}

	// If domain, owner and mailip are given
	// OR
	// empty is given and owner and domain
	// OR
	// the domain is an arpa record and owner is given
	// OR
	// the type is slave, domain, owner and slave_master are given
	// THAN
	// Continue this function
	if (($domain && $owner && $webip && $mailip) || ($empty && $owner && $domain) || (eregi('in-addr.arpa', $domain) && $owner) || $type=="SLAVE" && $domain && $owner && $slave_master)
	{
                // First insert zone into domain table
                $db->query("INSERT INTO domains (name, type) VALUES ('$domain', '$type')");

                // Determine id of insert zone (in other words, find domain_id)
                $iddomain = $db->lastInsertId('domains', 'id');
                if (PEAR::isError($iddomain)) {
                        die($id->getMessage());
                }

                // Second, insert into zones tables
                $db->query("INSERT INTO zones (domain_id, owner) VALUES ('$iddomain', $owner)");

		if ($type == "SLAVE")
		{
			$db->query("UPDATE domains SET master = '$slave_master' WHERE id = '$iddomain';");
			
			// Done
			return true;
		}
		else
		{
			// Generate new timestamp. We need this one anyhow.
			$now = time();

			if ($empty && $iddomain)
			{
				// If we come into this if statement we dont want to apply templates.
				// Retrieve configuration settings.
				$ns1 = $GLOBALS["NS1"];
				$hm  = $GLOBALS["HOSTMASTER"];
				$ttl = $GLOBALS["DEFAULT_TTL"];

				// Build and execute query
				$sql = "INSERT INTO records (domain_id, name, content, type, ttl, prio, change_date) VALUES ('$iddomain', '$domain', '$ns1 $hm 1', 'SOA', $ttl, '', '$now')";
				$db->query($sql);

				// Done
				return true;
			}
			elseif ($iddomain)
			{
				// If we are here we want to apply templates.
				global $template;

				// Iterate over the template and apply it for each field.
				foreach ($template as $r)
				{
					// Same type of if statement as previous.
					if ((eregi('in-addr.arpa', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!eregi('in-addr.arpa', $domain)))
					{
						// Parse the template.
						$name     = parse_template_value($r["name"], $domain, $webip, $mailip);
						$type     = $r["type"];
						$content  = parse_template_value($r["content"], $domain, $webip, $mailip);
						$ttl      = $r["ttl"];
						$prio     = $r["prio"];

						// If no ttl is given, use the default.
						if (!$ttl)
						{
							$ttl = $GLOBALS["DEFAULT_TTL"];
						}

						$sql = "INSERT INTO records (domain_id, name, content, type, ttl, prio, change_date) VALUES ('$iddomain', '$name','$content','$type','$ttl','$prio','$now')";
						$db->query($sql);
					}
				}
				// All done.
				return true;
			 }
			 else
			 {
				error(sprintf(ERR_INV_ARGC, "add_domain", "could not create zone"));
			 }
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARG, "add_domain"));
	}
}


/*
 * Deletes a domain by a given id.
 * Function always succeeds. If the field is not found in the database, thats what we want anyway.
 */
function delete_domain($id)
{
	global $db;

	if (!level(5))
	{
		error(ERR_LEVEL_5);
	}

	// See if the ID is numeric.
	if (is_numeric($id))
	{
		$db->query("DELETE FROM zones WHERE domain_id=$id");
		$db->query("DELETE FROM domains WHERE id=$id");
		$db->query("DELETE FROM records WHERE domain_id=$id");
		// Nothing in the database. If the delete deleted 0 records it means the id is just not there.
		// therefore the is no need to check the affectedRows values.
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "delete_domain", "id must be a number"));
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
		$result = $db->query("SELECT domain_id FROM records WHERE id=$id");
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
 * Function should actually be in users.inc.php. But its more of a record modification than a user modification
 * return values: true when succesful.
 */
function add_owner($domain, $newowner)
{
	global $db;

	if (!level(5))
	{
		error(ERR_LEVEL_5);
	}

	if (is_numeric($domain) && is_numeric($newowner) && is_valid_user($newowner))
	{
		if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=$newowner AND domain_id=$domain") == 0)
		{
			$db->query("INSERT INTO zones (domain_id, owner) VALUES($domain, $newowner)");
		}
		return true;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "change_owner", "$domain / $newowner"));
	}
}


function delete_owner($domain, $owner)
{
	global $db;
	if($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=$owner AND domain_id=$domain") != 0)
	{
		$db->query("DELETE FROM zones WHERE owner=$owner AND domain_id=$domain");
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

	$result = $db->query("select id, type from records where domain_id=$recid and type='$type'");
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
		$result = $db->query("SELECT type FROM records WHERE id=$id");
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
		$result = $db->query("SELECT name FROM records WHERE id=$id");
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
		$result = $db->query("SELECT domains.id AS domain_id, domains.name AS name FROM domains LEFT JOIN zones ON domains.id=zones.domain_id WHERE owner=$id");

		$ret = array();

		// Put all the information in a big array.
		while ($r = $result->fetchRow())
		{
			$ret[] = array(
			"id"            =>              $r["domain_id"],
			"name"          =>              $r["name"]
			);
		}
		return $ret;
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
	if (!xs($id))
	{
		error(ERR_RECORD_ACCESS_DENIED);
	}
	if (is_numeric($id))
	{
		$result = $db->query("SELECT name FROM domains WHERE id=$id");
		if ($result->numRows() == 1)
		{
 			$r = $result->fetchRow();
 			return $r["name"];
		}
		else
		{
	 		error(sprintf(ERR_INV_ARGC, "get_domain_name_from_id", "more than one domain found?! whaaa! BAD! BAD! Contact admin!"));
		}
	}
	else
	{
		error(sprintf(ERR_INV_ARGC, "get_domain_name_from_id", "Not a valid domainid: $id"));
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
	
        WHERE record_owners.user_id = ".$_SESSION["userid"]."
        AND record_owners.record_id = records.id
	AND records.domain_id = ".$id."

	GROUP BY name, owner, users.fullname
	ORDER BY name";
	
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
			GROUP BY name, owner, users.fullname
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

	if (!level(5))
	{
		error(ERR_LEVEL_5);
	}
	if (is_valid_domain($domain))
	{
		$result = $db->query("SELECT id FROM domains WHERE name='$domain'");
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
		error(ERR_DOMAIN_INVALID);
	}
}

function get_supermasters()
{
        global $db;
        $result = $db->query("SELECT ip, nameserver, account FROM supermasters");
        $ret = array();

        if($result->numRows() == 0)
        {
                return -1;
        }
        else
        {
                while ($r = $result->fetchRow())
                {
                        $ret[] = array(
                        "master_ip"     => $r["ip"],
                        "ns_name"       => $r["nameserver"],
                        "account"       => $r["account"],
                        );
                        return $ret;
                }
        }
}

function supermaster_exists($master_ip)
{
        global $db;
        if (!level(5))
        {
                error(ERR_LEVEL_5);
        }
        if (is_valid_ip($master_ip) || is_valid_ip6($master_ip))
        {
                $result = $db->query("SELECT ip FROM supermasters WHERE ip = '$master_ip'");
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


/*
 * Get all domains from the database 
 * This function gets all the domains from the database unless a user id is below 5.
 * if a user id is below 5 this function will only retrieve records for that user.
 * return values: the array of domains or -1 if nothing is found.
 */
function get_domains($userid=true,$letterstart=all,$rowstart=0,$rowamount=999999)
{
	global $db;
	if((!level(5) || !$userid) && !level(10) && !level(5))
	{
		$add = " AND zones.owner=".$_SESSION["userid"];
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
	WHERE 1 $add ";
	if ($letterstart!=all && $letterstart!=1) {
	   $sqlq.=" AND domains.name LIKE '".$letterstart."%' ";
	} elseif ($letterstart==1) {
	      $sqlq.=" AND ";
	   for ($i=0;$i<=9;$i++) {
	      $sqlq.="domains.name LIKE '".$i."%'";
	      if ($i!=9) $sqlq.=" OR ";
	   }
	}
	$sqlq.=" GROUP BY domainname, domain_id
	ORDER BY domainname
	LIMIT $rowstart,$rowamount";

	$result = $db->query($sqlq);
	$result2 = $db->query($sqlq);

	$andnot="";
	while($r = $result2->fetchRow()) {
	   $andnot.=" AND domains.id!= '".$r["domain_id"]."' ";
	}

if ($letterstart!=all && $letterstart!=1) {

	$sqlq = "SELECT domains.id AS domain_id,
	count(DISTINCT record_owners.record_id) AS aantal,
	domains.name AS domainname
	FROM domains, record_owners,records, zones
	WHERE record_owners.user_id = '".$_SESSION["userid"]."'
	AND (records.id = record_owners.record_id
	AND domains.id = records.domain_id)
	$andnot 
	AND domains.name LIKE '".$letterstart."%' 
	AND (zones.domain_id != records.domain_id AND zones.owner!='".$_SESSION["userid"]."')
	GROUP BY domainname, domain_id
	ORDER BY domainname";

	$result_extra = $db->query($sqlq);

} else {

   for ($i=0;$i<=9;$i++) {
        $sqlq = "SELECT domains.id AS domain_id,
        count(DISTINCT record_owners.record_id) AS aantal,
        domains.name AS domainname
        FROM domains, record_owners,records, zones
        WHERE record_owners.user_id = '".$_SESSION["userid"]."'
        AND (records.id = record_owners.record_id
        AND domains.id = records.domain_id)
        $andnot 
        AND domains.name LIKE '".$i."%' 
        AND (zones.domain_id != records.domain_id AND zones.owner!='".$_SESSION["userid"]."')
        GROUP BY domainname, domain_id
        ORDER BY domainname";

        $result_extra[$i] = $db->query($sqlq);
   }

}

/*
	if ($result->numRows() == 0)
	{
		// Nothing found.
		return -1;
	}
*/

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


if ($letterstart!=all && $letterstart!=1) {

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
		$result = $db->query("SELECT id, domain_id, name, type, content, ttl, prio, change_date FROM records WHERE id=$id");
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

		$result = $db->query("SELECT record_owners.record_id as id
		FROM record_owners,domains,records
		WHERE record_owners.user_id = ".$_SESSION["userid"]."
		AND record_owners.record_id = records.id
		AND records.domain_id = ".$id."
		GROUP bY record_owners.record_id
		LIMIT $rowstart,$rowamount");

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

		$result = $db->query("SELECT id FROM records WHERE domain_id=$id LIMIT $rowstart,$rowamount");
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


function get_users_from_domain_id($id)
{
	global $db;
	$result = $db->queryCol("SELECT owner FROM zones WHERE domain_id=$id");
	$ret = array();
	foreach($result as $uid)
	{
		$fullname = $db->queryOne("SELECT fullname FROM users WHERE id=$uid");
		$ret[] = array(
		"id" 		=> 	$uid,
		"fullname"	=>	$fullname		
		);		
	}
	return $ret;	
}

function search_record($question)
{
	global $db;
	$question = trim($question);
	if (empty($question)) 
	{
		$S_INPUT_TYPE = -1;
	}

	/* now for some input-type searching */
	if (is_valid_ip($question) || is_valid_ip6($question))
	{
		$S_INPUT_TYPE = 0;
	}
	elseif(is_valid_domain($question) || 
		is_valid_hostname($question) ||
		is_valid_mboxfw($question)) // I guess this one can appear in records table too (content?!)
	{
		$S_INPUT_TYPE = 1;
	}	  
	else 
	{
		$S_INPUT_TYPE = -1;
	}
	switch($S_INPUT_TYPE)
	{
		case '0': 
			$sqlq = "SELECT * FROM `records` WHERE `content` = '".$question."' ORDER BY `type` DESC";
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
			break;
	    
		case '1' :
			$sqlq = "SELECT `domains`.*, count(`records`.`id`) AS `numrec`, `zones`.`owner`, `records`.`domain_id`
					FROM `domains`, `records`, `zones`  
					WHERE `domains`.`id` = `records`.`domain_id` 
					AND `zones`.`domain_id` = `domains`.`id` 
					AND `domains`.`name` = '".$question."' 
					GROUP BY (`domains`.`id`)";

			$result = $db->query($sqlq);
			$ret_d = array();
			while ($r = $result->fetchRow())
			{
			    if(xs($r['domain_id']))
			    {
				    $ret_d[] = array(
    					'id'			=>	$r['id'],
    					'name'		=>	$r['name'],
    					'numrec'		=>	$r['numrec'],
    					'owner'		=>	$r['owner']
    				);
				}
			}

			$sqlq = "SELECT * FROM `records` WHERE `name` = '".$question."' OR `content` = '".$question."' ORDER BY `type` DESC";
			$result = $db->query($sqlq);
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
    				);
    			}
			}
			break;
	}
	if($S_INPUT_TYPE == 1)
	{
		return array('domains' => $ret_d, 'records' => $ret_r);
	}
	return array('records' => $ret_r);
}

function get_domain_type($id)
{
	global $db;
        if (is_numeric($id))
	{
		$type = $db->queryOne("SELECT `type` FROM `domains` WHERE `id` = '".$id."'");
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
		$slave_master = $db->queryOne("SELECT `master` FROM `domains` WHERE `type` = 'SLAVE' and `id` = '".$id."'");
		return $slave_master;
        }
        else
        {
                error(sprintf(ERR_INV_ARG, "get_domain_slave_master", "no or no valid zoneid given"));
        }
}

function change_domain_type($type, $id)
{
	global $db;
	unset($add);
        if (is_numeric($id))
	{
		// It is not really neccesary to clear the master field if a 
		// zone is not of the type "slave" as powerdns will ignore that
		// fiedl, but it is cleaner anyway.
		if ($type != "SLAVE")
		{
			$add = ", master=''";
		}
		$result = $db->query("UPDATE `domains` SET `type` = '" .$type. "'".$add." WHERE `id` = '".$id."'");
	}
        else
        {
                error(sprintf(ERR_INV_ARG, "change_domain_type", "no or no valid zoneid given"));
        }
}

function change_domain_slave_master($id, $slave_master)
{
	global $db;
        if (is_numeric($id))
	{
       		if (is_valid_ip($slave_master) || is_valid_ip6($slave_master))
		{
			$result = $db->query("UPDATE `domains` SET `master` = '" .$slave_master. "' WHERE `id` = '".$id."'");
		}
		else
		{
			error(sprintf(ERR_INV_ARGC, "change_domain_slave_master", "This is not a valid IPv4 or IPv6 address: $slave_master"));
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
