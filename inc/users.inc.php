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

require_once("inc/toolkit.inc.php");


/* 
 *  Function to see if user has right to do something. It will check if
 *  user has "ueberuser" bit set. If it isn't, it will check if the user has
 *  the specific permission. It returns "false" if the user doesn't have the
 *  right, and "true" if the user has. 
 */

function verify_permission($permission) {

        global $db;

        // Set current user ID.
        $userid=$_SESSION['userid'];

        // Find the template ID that this user has been assigned.
        $query = "SELECT perm_templ
			FROM users 
			WHERE id = " . $db->quote($userid) ;
        $templ_id = $db->queryOne($query);

        // Does this user have ueberuser rights?
        $query = "SELECT id 
			FROM perm_templ_items 
			WHERE templ_id = " . $db->quote($templ_id) . " 
			AND perm_id = '30'";
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
		users.level AS level,
		users.active AS active,
		count(zones.owner) AS aantal FROM users
		LEFT JOIN zones ON users.id=zones.owner$add
		GROUP BY
			users.id,
			users.username,
			users.fullname,
			users.email,
			users.description,
			users.level,
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
		 "level"                 =>              $r["level"],
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
 * Gives a textdescribed value of the given levelid
 * return values: the text associated with the level
 */
function leveldescription($id)
{
	switch($id)
	{
		case 1:
			global $NAME_LEVEL_1;
			return $NAME_LEVEL_1;
			break;
		case 5:
			global $NAME_LEVEL_5;
			return $NAME_LEVEL_5;
			break;
		case 10:
			global $NAME_LEVEL_10;
			return $NAME_LEVEL_10;
			break;
		default:
			return "Unknown";
			break;
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
 * Get all user info for the given user in an array.
 * return values: the database style array with the information about the user.
 */
function get_user_info($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT id, username, fullname, email, description, level, active from users where id=".$db->quote($id));
		$r = $result->fetchRow();
		return $r;
	}
	else
	{
		error(sprintf(ERR_INV_ARGC,"get_user_info", "you gave illegal arguments: $id"));
	}
}


/*
 * Delete a user from the system
 * return values: true if user doesnt exist.
 */
function delete_user($id)
{
	global $db;
	if (!level(10))
	{
		error(ERR_LEVEL_10);
	}
	if (is_numeric($id))
	{
        	$db->query("DELETE FROM users WHERE id=".$db->quote($id));
        	$db->query("DELETE FROM zones WHERE owner=".$db->quote($id));
        	return true;
        	// No need to check the affected rows. If the affected rows would be 0,
        	// the user isnt in the dbase, just as we want.
        }
	else
	{
		error(ERR_INV_ARG);
	}
}


/*
 * Adds a user to the system.
 * return values: true if succesfully added.
 */
function add_user($user, $password, $fullname, $email, $level, $description, $active)
{
	global $db;
	if (!level(10))
	{
		error(ERR_LEVEL_10);
	}
	if (!user_exists($user))
	{
		if (!is_valid_email($email)) 
		{
			error(ERR_INV_EMAIL);
		}
		if ($active != 1) {
			$active = 0;
		}
		$db->query("INSERT INTO users (username, password, fullname, email, description, level, active) VALUES (".$db->quote($user).", '" . md5($password) . "', ".$db->quote($fullname).", ".$db->quote($email).", ".$db->quote($description).", ".$db->quote($level).", ".$db->quote($active).")");
		return true;
	}
	else
	{
		error(ERR_USER_EXISTS);
	}
}


/*
 * Edit the information of an user.. sloppy implementation with too many queries.. (2) :)
 * return values: true if succesful
 */
function edit_user($id, $user, $fullname, $email, $level, $description, $active, $password)
{
	global $db;
	if(!level(10)) {
		error(ERR_LEVEL_10);
	}

	if (!is_valid_email($email)) 
	{
		error(ERR_INV_EMAIL);
	}
	if ($active != 1) {
		$active = 0;
	}
	$sqlquery = "UPDATE users set username=".$db->quote($user).", fullname=".$db->quote($fullname).", email=".$db->quote($email).", level=".$db->quote($level).", description=".$db->quote($description).", active=".$db->quote($active);

	if($password != "")
	{
		$sqlquery .= ", password= '" . md5($password) . "' ";
	}

	$sqlquery .= " WHERE id=".$db->quote($id) ;

  	// Search the username that right now goes with this ID.
	$result = $db->query("SELECT username from users where id=".$db->quote($id));
	$r = array();
	$r = $result->fetchRow();

  	// If the found username with this ID is the given username with the command.. execute.

	if($r["username"] == $user)
	{
		$db->query($sqlquery);
  		return true;
  	}

  	// Its not.. so the user wants to change.
  	// Find if there is an id that has the wished username.
  	$otheruser = $db->query("SELECT id from users where username=".$db->query($user));
  	if($otheruser->numRows() > 0)
  	{
  		error(ERR_USER_EXIST);
  	}

  	// Its fine it seems.. :)
  	// Lets execute it.
  	else
  	{
		$db->query($sqlquery);
		return true;
	}
}

/*
 * Change the pass of the user.
 * The user is automatically logged out after the pass change.
 * return values: none.
 */
function change_user_pass($currentpass, $newpass, $newpass2)
{
	global $db;

	// Check if the passwords are equal.
	if($newpass != $newpass2)
	{
		error(ERR_USER_MATCH_NEW_PASS);
	}

	// Retrieve the users password.
	$result = $db->query("SELECT password, id FROM users WHERE username=".$db->quote($_SESSION["userlogin"]));
	$rinfo = $result->fetchRow();

	// Check the current password versus the database password and execute the update.
	if(md5($currentpass) == $rinfo["password"])
	{
		$sqlquery = "update users set password='" . md5($newpass) . "' where id='" . $rinfo["id"] . "'";
		$db->query($sqlquery);

		// Logout the user.
		logout("Pass changed please re-login");
	}
	else
	{
		error(ERR_USER_WRONG_CURRENT_PASS);
	}
}


/*
 * Get a fullname when you have a userid.
 * return values: gives the fullname from a userid.
 */
function get_fullname_from_userid($id)
{
	global $db;
	if (is_numeric($id))
	{
		$result = $db->query("SELECT fullname FROM users WHERE id=".$db->quote($id));
		$r = $result->fetchRow();
		return $r["fullname"];
	}
	else
	{
		error(ERR_INV_ARG);
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

function get_user_list() {

	global $db;
	$userid=$_SESSION['userid'];
	(verify_permission(user_view_others)) ? $sql_add = "" :  $sql_add = "AND users.id = " . $db->quote($userid) ;
	$query = "SELECT users.id AS uid, 
			username, 
			fullname, 
			email, 
			description AS descr,
			active,
			perm_templ.id AS tpl_id,
			perm_templ.name AS tpl_name 
			FROM users, perm_templ 
			WHERE users.perm_templ = perm_templ.id " 
			. $sql_add . "
			ORDER BY username";

	$result = $db->query($query);
	if (PEAR::isError($response)) { error($response->getMessage()); return false; }
	$userlist = array();
	while ($user = $result->fetchRow()) {
		$userlist[] = array(
			"uid"		=>	$user['uid'],
			"username"	=>	$user['username'],
			"fullname"	=>	$user['fullname'],
			"email"		=>	$user['email'],
			"descr"		=>	$user['descr'],
			"active"	=>	$user['active'],
			"tpl_id"	=>	$user['tpl_id'],
			"tpl_name"	=>	$user['tpl_name']
			);
	}
	return $userlist;
}

?>
