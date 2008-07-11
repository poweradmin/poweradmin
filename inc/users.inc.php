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

require_once("inc/toolkit.inc.php");


/* 
 *  Function to see if user has right to do something. It will check if
 *  user has "ueberuser" bit set. If it isn't, it will check if the user has
 *  the specific permission. It returns "false" if the user doesn't have the
 *  right, and "true" if the user has. 
 */

function verify_permission($permission) {

        global $db;

	if ((!isset($_SESSION['userid'])) || (!is_object($db))) {
		return 0;
	}

        // Set current user ID.
        $userid=$_SESSION['userid'];

		$query = 'SELECT id FROM perm_items WHERE name='.$db->quote('user_is_ueberuser');
		$ueberUserId = $db->queryOne($query);

        // Find the template ID that this user has been assigned.
        $query = "SELECT perm_templ
			FROM users 
			WHERE id = " . $db->quote($userid) ;
        $templ_id = $db->queryOne($query);

        // Does this user have ueberuser rights?
        $query = "SELECT id 
			FROM perm_templ_items 
			WHERE templ_id = " . $db->quote($templ_id) . " 
			AND perm_id = ".$ueberUserId;
        $result = $db->query($query);
        if ( $result->numRows() > 0 ) {
                return 1;
        }

        // Find the permission ID for the requested permission.
        $query = "SELECT id 
			FROM perm_items 
			WHERE name = " . $db->quote($permission) ;
        $perm_id = $db->queryOne($query);

        // Check if the permission ID is assigned to the template ID. 
        $query = "SELECT id 
			FROM perm_templ_items 
			WHERE templ_id = " . $db->quote($templ_id) . " 
			AND perm_id = " . $db->quote($perm_id) ;
        $result = $db->query($query);
        if ( $result->numRows() > 0 ) {
                return 1;
        } else {
                return 0;
        }
}

function list_permission_templates() {
	global $db;
	$query = "SELECT * FROM perm_templ";
	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$template_list = array();
	while ($template= $response->fetchRow()) {
		$tempate_list[] = array(
			"id"	=>	$template['id'],
			"name"	=>	$template['name'],
			"descr"	=>	$template['descr']
			);
	}
	return $tempate_list;
}

/*
 * Retrieve all users.
 * Its to show_users therefore the odd name. Has to be changed.
 * return values: an array with all users in it.
 */
function show_users($id='',$rowstart=0,$rowamount=9999999)
{
 	global $db;
	$add = '';
 	if(is_numeric($id))
 	{
                 //When a user id is given, it is excluded from the userlist returned.
                 $add = " WHERE users.id!=".$db->quote($id);
	}

	// Make a huge query.
	$sqlq = "SELECT users.id AS id,
		users.username AS username,
		users.fullname AS fullname,
		users.email AS email,
		users.description AS description,
		users.active AS active,
		users.perm_templ AS perm_templ,
		count(zones.owner) AS aantal FROM users
		LEFT JOIN zones ON users.id=zones.owner$add
		GROUP BY
			users.id,
			users.username,
			users.fullname,
			users.email,
			users.description,
			users.perm_templ,
			users.active
		ORDER BY
			users.fullname";

	// Execute the huge query.
	$db->setLimit($rowamount, $rowstart);
	$result = $db->query($sqlq);
	$ret = array();
	$retcount = 0;
	while ($r = $result->fetchRow())
	{
		$ret[] = array(
		 "id"                    =>              $r["id"],
		 "username"              =>              $r["username"],
		 "fullname"              =>              $r["fullname"],
		 "email"                 =>              $r["email"],
		 "description"           =>              $r["description"],
//		 "level"                 =>              $r["level"],
		 "active"                =>              $r["active"],
		 "numdomains"            =>              $r["aantal"]
		);
	}
	return $ret;
}


/*
 * Check if the given $userid is connected to a valid user.
 * return values: true if user exists, false if users doesnt exist.
 */
 function is_valid_user($id)
{
	global $db;
	if(is_numeric($id))
	{
		$result = $db->query("SELECT id FROM users WHERE id=".$db->quote($id));
		if ($result->numRows() == 1)
		{
			return true;
		}
		else
		{
			return false;
		}
	}
}


/*
 * Checks if a given username exists in the database.
 * return values: true if exists, false if not.
 */
function user_exists($user)
{
	global $db;
	$result = $db->query("SELECT id FROM users WHERE username=".$db->quote($user));
	if ($result->numRows() == 0)
	{
                 return false;
	}
	elseif($result->numRows() == 1)
	{
        	return true;
	}
        else
        {
        	error(ERR_UNKNOWN);
	}
}



/*
 * Delete a user from the system
 * return values: true if user doesnt exist.
 */
function delete_user($uid,$zones)
{
	global $db;

	if (($uid != $_SESSION['userid'] && !verify_permission('user_edit_others')) || ($uid == $_SESSION['userid'] && !verify_permission('user_edit_own'))) {
		 error(ERR_PERM_DEL_USER);
		 return false;
	} else {

		if (is_array($zones)) {
			foreach ($zones as $zone) {
				if ($zone['target'] == "delete") {
					delete_domain($zone['zid']);
				} elseif ($zone['target'] == "new_owner") {
					add_owner_to_zone($zone['zid'], $zone['newowner']);
				}
			}
		}

		$query = "DELETE FROM zones WHERE owner = " . $db->quote($uid) ;
		$result = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }

		$query = "DELETE FROM users WHERE id = " . $db->quote($uid) ;
		$result = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	}
	return true;
}

function delete_perm_templ($ptid) {

	global $db;
	if (!(verify_permission('user_edit_templ_perm'))) {
		error(ERR_PERM_DEL_PERM_TEMPL);
	} else {
		$query = "SELECT id FROM users WHERE perm_templ = " . $ptid;
		$result = $db->query($query);
		if (PEAR::isError($result)) { error($response->getMessage()); return false; }

		if($result->numRows() > 0) {
			error(ERR_PERM_TEMPL_ASSIGNED);
			return false;
		} else {
			$query = "DELETE FROM perm_templ_items WHERE templ_id = " . $ptid;
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($response->getMessage()); return false; }

			$query = "DELETE FROM perm_templ WHERE id = " . $ptid;
			$result = $db->query($query);
			if (PEAR::isError($result)) { error($response->getMessage()); return false; }

			return true;
		}
	}
}

/*
 * Edit the information of an user.. sloppy implementation with too many queries.. (2) :)
 * return values: true if succesful
 */
function edit_user($id, $user, $fullname, $email, $perm_templ, $description, $active, $password)
{
	global $db;

	verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
	verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0" ;

	if (($id == $_SESSION["userid"] && $perm_edit_own == "1") || ($id != $_SESSION["userid"] && $perm_edit_others == "1" )) {

		if (!is_valid_email($email)) {
			error(ERR_INV_EMAIL);
			return false;
		}

		if ($active != 1) {
			$active = 0;
		}
		
		// Before updating the database we need to check whether the user wants to 
		// change the username. If the user wants to change the username, we need 
		// to make sure it doesn't already exists. 
		//
		// First find the current username of the user ID we want to change. If the 
		// current username is not the same as the username that was given by the 
		// user, the username should apparantly changed. If so, check if the "new" 
		// username already exists.

		$query = "SELECT username FROM users WHERE id = " . $db->quote($id);
		$response = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }

		$usercheck = array();
		$usercheck = $response->fetchRow();

		if ($usercheck['username'] != $user) {
			
			// Username of user ID in the database is different from the name
			// we have been given. User wants a change of username. Now, make
			// sure it doesn't already exist.
			
			$query = "SELECT id FROM users WHERE username = " . $db->quote($user);
			$result = $db->query($query);
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			if($result->numRows() > 0) {
				error(ERR_USER_EXIST);
				return false;
			}
		}

		// So, user doesn't want to change username or, if he wants, there is not
		// another user that goes by the wanted username. So, go ahead!

		$query = "UPDATE users SET
				username = " . $db->quote($user) . ",
				fullname = " . $db->quote($fullname) . ",
				email = " . $db->quote($email) . ",
				perm_templ = " . $db->quote($perm_templ) . ",
				description = " . $db->quote($description) . ", 
				active = " . $db->quote($active) ;

		if($password != "") {
			$query .= ", password = " . $db->quote(md5($password)) ;
		}

		$query .= " WHERE id = " . $db->quote($id) ;

		$response = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }
		
	} else {
		error(ERR_PERM_EDIT_USER);
		return false;
	}
	return true;
}

/*
 * Change the pass of the user.
 * The user is automatically logged out after the pass change.
 * return values: none.
 */
function change_user_pass($details) {
	global $db;
	
	if ($details['newpass'] != $details['newpass2']) {
		error(ERR_USER_MATCH_NEW_PASS);
		return false;
	}

	$query = "SELECT id, password FROM users WHERE username = " . $db->quote($_SESSION["userlogin"]);
	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$rinfo = $result->fetchRow();

	if(md5($details['currentpass']) == $rinfo['password']) {
		$query = "UPDATE users SET password = " . $db->quote(md5($details['newpass'])) . " WHERE id = " . $db->quote($rinfo['id']) ;
		$result = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }

		logout( _('Password has been changed, please login.')); 
	} else {
		error(ERR_USER_WRONG_CURRENT_PASS);
		return false;
	}
}


/*
 * Get a fullname when you have a userid.
 * return values: gives the fullname from a userid.
 */
function get_fullname_from_userid($id) {
	global $db;
	if (is_numeric($id)) {
		$result = $db->query("SELECT fullname FROM users WHERE id=".$db->quote($id));
		$r = $result->fetchRow();
		return $r["fullname"];
	} else {
		error(ERR_INV_ARG);
		return false;
	}
}


/*
 * Get a fullname when you have a userid.
 * return values: gives the fullname from a userid.
 */
function get_owner_from_id($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT fullname FROM users WHERE id=".$db->quote($id));
		if ($result->numRows() == 1)
		{
			$r = $result->fetchRow();
			return $r["fullname"];
		}
		else
		{
			error(ERR_USER_NOT_EXIST);
		}
	}
	error(ERR_INV_ARG);
}

/**
 * get_owners_from_domainid
 *
 * @todo also fetch the subowners
 * @param $id integer the id of the domain
 * @return String the list of owners for this domain
 */
function get_fullnames_owners_from_domainid($id) {
      
      global $db;
      if (is_numeric($id))
      {
              $result = $db->query("SELECT users.id, users.fullname FROM users, zones WHERE zones.domain_id=".$db->quote($id)." AND zones.owner=users.id ORDER by fullname");
              if ($result->numRows() == 0)
              {
		      return "";
              } 
	      else 
	      {
                      $names = array();
                      while ($r = $result->fetchRow()) 
		      {
                              $names[] = $r['fullname'];
                      }
                      return implode(', ', $names);
              }
      }
      error(ERR_INV_ARG);
}



function verify_user_is_owner_zoneid($zoneid) {
	global $db;

	$userid=$_SESSION["userid"];

	if (is_numeric($zoneid)) {
		$result = $db->query("SELECT zones.id 
				FROM zones 
				WHERE zones.owner = " . $db->quote($userid) . "
				AND zones.domain_id = ". $db->quote($zoneid)) ;
		if ($result->numRows() == 0) {
			return "0";
		} else {
			return "1";
		}
	}
	error(ERR_INV_ARG);
}


function get_user_detail_list($specific) {

	global $db;
	$userid=$_SESSION['userid'];


	if (v_num($specific)) {
		$sql_add = "AND users.id = " . $db->quote($specific) ;
	} else {
		if (verify_permission('user_view_others')) {
			$sql_add = "";
		} else {
			$sql_add = "AND users.id = " . $db->quote($userid) ;
		}
	}

	$query = "SELECT users.id AS uid, 
			username, 
			fullname, 
			email, 
			description AS descr,
			active,
			perm_templ.id AS tpl_id,
			perm_templ.name AS tpl_name,
			perm_templ.descr AS tpl_descr
			FROM users, perm_templ 
			WHERE users.perm_templ = perm_templ.id " 
			. $sql_add . "
			ORDER BY username";

	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	
	while ($user = $response->fetchRow()) {
		$userlist[] = array(
			"uid"		=>	$user['uid'],
			"username"	=>	$user['username'],
			"fullname"	=>	$user['fullname'],
			"email"		=>	$user['email'],
			"descr"		=>	$user['descr'],
			"active"	=>	$user['active'],
			"tpl_id"	=>	$user['tpl_id'],
			"tpl_name"	=>	$user['tpl_name'],
			"tpl_descr"	=>	$user['tpl_descr']
			);
	}
	return $userlist;
}


// Get a list of permissions that are available. If first argument is "0", it
// should return all available permissions. If the first argument is > "0", it
// should return the permissions assigned to that particular template only. If
// second argument is true, only the permission names are returned.

function get_permissions_by_template_id($templ_id=0,$return_name_only=false) {
	global $db;
	
	if ($templ_id > 0) {
		$limit = ", perm_templ_items 
			WHERE perm_templ_items.templ_id = " . $db->quote($templ_id) . "
			AND perm_templ_items.perm_id = perm_items.id";
	}

	$query = "SELECT perm_items.id AS id, 
			perm_items.name AS name, 
			perm_items.descr AS descr
			FROM perm_items" 
			. $limit . "
			ORDER BY descr";
	$response = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$permission_list = array();
	while ($permission = $response->fetchRow()) {
		if ($return_name_only == false) {
			$permission_list[] = array(
				"id"	=>	$permission['id'],
				"name"	=>	$permission['name'],
				"descr"	=>	$permission['descr']
				);
		} else {
			$permission_list[] = $permission['name'];
		}
	}
	return $permission_list;
}


// Get name and description of template based on template ID.

function get_permission_template_details($templ_id) {
	global $db;

	$query = "SELECT *
			FROM perm_templ
			WHERE perm_templ.id = " . $db->quote($templ_id);

	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$details = $result->fetchRow(); 
	return $details;
}	


// Get a list of all available permission templates.

function get_list_permission_templates() {
	global $db;

	$query = "SELECT * FROM perm_templ";
	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$perm_templ_list = array();
	while ($perm_templ = $result->fetchRow()) {
		$perm_templ_list[] = array(
			"id"	=>	$perm_templ['id'],
			"name"	=>	$perm_templ['name'],
			"descr"	=>	$perm_templ['descr']
			);
	}
	return $perm_templ_list;
}


// Add a permission template.

function add_perm_templ($details) {
	global $db;

	// Fix permission template name and description first. 

	$query = "INSERT INTO perm_templ (name, descr)
			VALUES (" 
				. $db->quote($details['templ_name']) . ", " 
				. $db->quote($details['templ_descr']) . ")";

	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	$perm_templ_id = $db->lastInsertId('perm_templ', 'id');

	foreach ($details['perm_id'] AS $perm_id) {
		$r_insert_values[] = "(" . $db->quote($perm_templ_id) . "," . $db->quote($perm_id) . ")";
	}
	$query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES " . implode(',', $r_insert_values) ;
	$result = $db->query($query);
	if (pear::iserror($response)) { error($response->getmessage()); return false; }

	return true;
}

// Update all details of a permission template.

function update_perm_templ_details($details) {
	global $db;

	// Fix permission template name and description first. 

	$query = "UPDATE perm_templ 
			SET name = " . $db->quote($details['templ_name']) . ",
			descr = " . $db->quote($details['templ_descr']) . "
			WHERE id = " . $db->quote($details['templ_id']) ;
	
	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	// Now, update list of permissions assigned to this template. We could do 
	// this The Correct Way [tm] by comparing the list of permissions that are
	// currently assigned with a list of permissions that should be assigned and
	// apply the difference between these two lists to the database. That sounds 
	// like to much work. Just delete all the permissions currently assigned to 
	// the template, than assign all the permessions the template should have.

	$query = "DELETE FROM perm_templ_items WHERE templ_id = " . $details['templ_id'] ;
	$result = $db->query($query);
	if (pear::iserror($response)) { error($response->getmessage()); return false; }

	foreach ($details['perm_id'] AS $perm_id) {
		$r_insert_values[] = "(" . $db->quote($details['templ_id']) . "," . $db->quote($perm_id) . ")";
	}
	$query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES " . implode(',', $r_insert_values) ;
	$result = $db->query($query);
	if (pear::iserror($response)) { error($response->getmessage()); return false; }

	return true;
}

function update_user_details($details) {

	global $db;

	verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0" ;
	verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0" ;
	verify_permission('templ_perm_edit') ? $perm_templ_perm_edit = "1" : $perm_templ_perm_edit = "0" ;

	if (($details['uid'] == $_SESSION["userid"] && $perm_edit_own == "1") || 
			($details['uid'] != $_SESSION["userid"] && $perm_edit_others == "1" )) {

		if (!is_valid_email($details['email'])) {
			error(ERR_INV_EMAIL);
			return false;
		}

		if (!isset($details['active']) || $details['active'] != "on" ) {
			$active = 0;
		} else {
			$active = 1;
		}

		// Before updating the database we need to check whether the user wants to 
		// change the username. If the user wants to change the username, we need 
		// to make sure it doesn't already exists. 
		//
		// First find the current username of the user ID we want to change. If the 
		// current username is not the same as the username that was given by the 
		// user, the username should apparantly changed. If so, check if the "new" 
		// username already exists.
		$query = "SELECT username FROM users WHERE id = " . $db->quote($details['uid']);
		$result = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }

		$usercheck = array();
		$usercheck = $result->fetchRow();

		if ($usercheck['username'] != $details['username']) {
			// Username of user ID in the database is different from the name
			// we have been given. User wants a change of username. Now, make
			// sure it doesn't already exist.
			$query = "SELECT id FROM users WHERE username = " . $db->quote($details['username']);
			$result = $db->query($query);
			if (PEAR::isError($response)) { error($response->getMessage()); return false; }

			if($result->numRows() > 0) {
				error(ERR_USER_EXIST);
				return false;
			}
		}

		// So, user doesn't want to change username or, if he wants, there is not
		// another user that goes by the wanted username. So, go ahead!

		$query = "UPDATE users SET
				username = " . $db->quote($details['username']) . ",
				fullname = " . $db->quote($details['fullname']) . ",
				email = " . $db->quote($details['email']) . ",
				description = " . $db->quote($details['descr']) . ", 
				active = " . $db->quote($active) ;

		// If the user is alllowed to change the permission template, set it.
		if ($perm_templ_perm_edit == "1") {
			$query .= ", perm_templ = " . $db->quote($details['templ_id']) ;

		}

		if(isset($details['password']) && $details['password'] != "") {
			$query .= ", password = '" . md5($db->quote($details['password'])) . "' ";
		}

		$query .= " WHERE id = " . $db->quote($details['uid']) ;

		$result = $db->query($query);
		if (PEAR::isError($response)) { error($response->getMessage()); return false; }

	} else {
		error(ERR_PERM_EDIT_USER);
		return false;
	}
	return true;		
}

// Add a new user

function add_new_user($details) {
	global $db;

	if (!verify_permission('user_add_new')) {
		error(ERR_PERM_ADD_USER);

	} elseif (user_exists($details['username'])) {
		error(ERR_USER_EXISTS);

	} elseif (!is_valid_email($details['email'])) {
		error(ERR_INV_EMAIL);
	
	} elseif ($details['active'] == 1) {
		$active = 1;
	} else {
		$active = 0;
	}

	$query = "INSERT INTO users (username, password, fullname, email, description, perm_templ, active) VALUES ("
			. $db->quote($details['username']) . ", "
			. $db->quote(md5($details['password'])) . ", "
			. $db->quote($details['fullname']) . ", "
			. $db->quote($details['email']) . ", "
			. $db->quote($details['descr']) . ", "
			. $db->quote($details['perm_templ']) . ", "
			. $db->quote($active) 
			. ")";

	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	
	return true;
}

			

?>
