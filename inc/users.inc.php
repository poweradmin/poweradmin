<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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

/**
 *  User profile functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");

/** Verify User has Permission Name
 *
 *  Function to see if user has right to do something. It will check if
 *  user has "ueberuser" bit set. If it isn't, it will check if the user has
 *  the specific permission. It returns "false" if the user doesn't have the
 *  right, and "true" if the user has. 
 *
 *  @param string $permission Permission name
 *
 *  @return boolean true if user has permission, false otherwise
 */
function verify_permission($permission) {
    global $db;

    if ((!isset($_SESSION['userid'])) || (!is_object($db))) {
        return 0;
    }

    // Set current user ID.
    $userid = $_SESSION['userid'];

    $query = 'SELECT id FROM perm_items WHERE name=' . $db->quote('user_is_ueberuser', 'text');
    $ueberUserId = $db->queryOne($query);

    // Find the template ID that this user has been assigned.
    $query = "SELECT perm_templ
			FROM users 
			WHERE id = " . $db->quote($userid, 'integer');
    $templ_id = $db->queryOne($query);

    // Does this user have ueberuser rights?
    $query = "SELECT id
			FROM perm_templ_items 
			WHERE templ_id = " . $db->quote($templ_id, 'integer') . " 
			AND perm_id = " . $ueberUserId;

    if ($db->queryOne($query)) {
        return true;
    }

    // Find the permission ID for the requested permission.
    $query = "SELECT id
			FROM perm_items 
			WHERE name = " . $db->quote($permission, 'text');
    $perm_id = $db->queryOne($query);

    // Check if the permission ID is assigned to the template ID.
    $query = "SELECT id
			FROM perm_templ_items 
			WHERE templ_id = " . $db->quote($templ_id, 'integer') . " 
			AND perm_id = " . $db->quote($perm_id, 'integer');

    return ($db->queryOne($query) ? true : false);
}

/** Get a list of all available permission templates
 *
 * @return mixed[] array of templates [id, name, descr]
 */
function list_permission_templates() {
    global $db;
    $query = "SELECT * FROM perm_templ ORDER BY name";
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    $template_list = array();
    while ($template = $response->fetchRow()) {
        $template_list[] = array(
            "id" => $template['id'],
            "name" => $template['name'],
            "descr" => $template['descr']
        );
    }
    return $template_list;
}

/** Retrieve all users
 *
 * Its to show_users therefore the odd name. Has to be changed.
 *
 * @param int $id Exclude User ID
 * @param int $rowstart Startring row number
 * @param int $rowamount Number of rows to return this query
 *
 * @return mixed[] array with all users [id,username,fullname,email,description,active,numdomains]
 */
function show_users($id = '', $rowstart = 0, $rowamount = 9999999) {
    global $db;
    $add = '';
    if (is_numeric($id)) {
        //When a user id is given, it is excluded from the userlist returned.
        $add = " WHERE users.id!=" . $db->quote($id, 'integer');
    }

    // Make a huge query.
    $query = "SELECT users.id AS id,
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
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }
    $ret = array();
    while ($r = $response->fetchRow()) {
        $ret[] = array(
            "id" => $r["id"],
            "username" => $r["username"],
            "fullname" => $r["fullname"],
            "email" => $r["email"],
            "description" => $r["description"],
            "active" => $r["active"],
            "numdomains" => $r["aantal"]
        );
    }
    return $ret;
}

/** Check if Valid User
 *
 * Check if the given $userid is connected to a valid user.
 *
 * @param int $id User ID
 *
 * @return boolean true if user exists, false if users doesnt exist
 */
function is_valid_user($id) {
    global $db;
    if (is_numeric($id)) {
        $response = $db->queryOne("SELECT id FROM users WHERE id=" . $db->quote($id, 'integer'));
        return ($response ? true : false);
    }
}

/** Check if Username Exists
 *
 * Checks if a given username exists in the database.
 *
 * @param string $user Username
 *
 * @return boolean true if exists, false if not
 */
function user_exists($user) {
    global $db;
    $response = $db->queryOne("SELECT id FROM users WHERE username=" . $db->quote($user, 'text'));
    return ($response ? true : false);
}

/** Delete User ID
 *
 * Delete a user from the system. Will also delete zones owned by user or
 * re-assign those zones to a new specified owner.
 * $zones is an array of zone 'zid's to delete or re-assign depending on
 * 'target' value [delete,new_owner] and 'newowner' value
 *
 * @param int $uid User ID to delete
 * @param mixed[] $zones Array of zones
 *
 * @return boolean true on success, false otherwise
 */
function delete_user($uid, $zones) {
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

        $query = "DELETE FROM zones WHERE owner = " . $db->quote($uid, 'integer');
        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        $query = "DELETE FROM users WHERE id = " . $db->quote($uid, 'integer');
        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        delete_zone_templ_userid($uid);
    }
    return true;
}

/** Delete Permission Template ID
 *
 * @param int $ptid Permission template ID
 *
 * @return boolean true on success, false otherwise
 */
function delete_perm_templ($ptid) {

    global $db;
    if (!(verify_permission('user_edit_templ_perm'))) {
        error(ERR_PERM_DEL_PERM_TEMPL);
    } else {
        $query = "SELECT id FROM users WHERE perm_templ = " . $ptid;
        $response = $db->queryOne($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        if ($response) {
            error(ERR_PERM_TEMPL_ASSIGNED);
            return false;
        } else {
            $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $ptid;
            $response = $db->query($query);
            if (PEAR::isError($response)) {
                error($response->getMessage());
                return false;
            }

            $query = "DELETE FROM perm_templ WHERE id = " . $ptid;
            $response = $db->query($query);
            if (PEAR::isError($response)) {
                error($response->getMessage());
                return false;
            }

            return true;
        }
    }
}

/** Modify User Details
 *
 * Edit the information of an user.. sloppy implementation with too many queries.. (2) :)
 *
 * @param int $id User ID
 * @param string $user Username
 * @param string $fullname Full Name
 * @param string $email Email address
 * @param string $perm_templ Permission Template Name
 * @param string $description Description
 * @param int $active Active User
 * @param string $password Password
 *
 * @return boolean true if succesful, false otherwise
 */
function edit_user($id, $user, $fullname, $email, $perm_templ, $description, $active, $password) {
    global $db;
    global $password_encryption;

    verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0";
    verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0";

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

        $query = "SELECT username FROM users WHERE id = " . $db->quote($id, 'integer');
        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        $usercheck = array();
        $usercheck = $response->fetchRow();

        if ($usercheck['username'] != $user) {

            // Username of user ID in the database is different from the name
            // we have been given. User wants a change of username. Now, make
            // sure it doesn't already exist.

            $query = "SELECT id FROM users WHERE username = " . $db->quote($user, 'text');
            $response = $db->queryOne($query);
            if ($response) {
                error(ERR_USER_EXIST);
                return false;
            }
        }

        // So, user doesn't want to change username or, if he wants, there is not
        // another user that goes by the wanted username. So, go ahead!

        $query = "UPDATE users SET
				username = " . $db->quote($user, 'text') . ",
				fullname = " . $db->quote($fullname, 'text') . ",
				email = " . $db->quote($email, 'text') . ",";
        if (verify_permission('user_edit_templ_perm')) {
            $query .= "perm_templ = " . $db->quote($perm_templ, 'integer') . ",";
        }
        $query .= "description = " . $db->quote($description, 'text') . ", 
				active = " . $db->quote($active, 'integer');

        if ($password != "") {
            if ($password_encryption == 'md5salt') {
                $query .= ", password = " . $db->quote(gen_mix_salt($password), 'text');
            } else {
                $query .= ", password = " . $db->quote(md5($password), 'text');
            }
        }

        $query .= " WHERE id = " . $db->quote($id, 'integer');

        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }
    } else {
        error(ERR_PERM_EDIT_USER);
        return false;
    }
    return true;
}

/** Change User Password
 *
 * Change the pass of the user.
 * The user is automatically logged out after the pass change.
 *
 * @param mixed[] $details User Details
 *
 * @return null
 */
function change_user_pass($details) {
    global $db;
    global $password_encryption;

    if ($details['newpass'] != $details['newpass2']) {
        error(ERR_USER_MATCH_NEW_PASS);
        return false;
    }

    $query = "SELECT id, password FROM users WHERE username = " . $db->quote($_SESSION["userlogin"], 'text');
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    $rinfo = $response->fetchRow();

    if ($password_encryption == 'md5salt') {
        $extracted_salt = extract_salt($rinfo['password']);
        $current_password = mix_salt($extracted_salt, $details['currentpass']);
    } else {
        $current_password = md5($details['currentpass']);
    }

    if ($current_password == $rinfo['password']) {
        if ($password_encryption == 'md5salt') {
            $query = "UPDATE users SET password = " . $db->quote(gen_mix_salt($details['newpass']), 'text') . " WHERE id = " . $db->quote($rinfo['id'], 'integer');
        } else {
            $query = "UPDATE users SET password = " . $db->quote(md5($details['newpass']), 'text') . " WHERE id = " . $db->quote($rinfo['id'], 'integer');
        }
        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        logout(_('Password has been changed, please login.'), 'success');
    } else {
        error(ERR_USER_WRONG_CURRENT_PASS);
        return false;
    }
}

/** Get User FullName from User ID
 *
 * Get a fullname when you have a userid.
 * @param int $id User ID
 * 
 * @return string Full Name
 */
function get_fullname_from_userid($id) {
    global $db;
    if (is_numeric($id)) {
        $response = $db->query("SELECT fullname FROM users WHERE id=" . $db->quote($id, 'integer'));
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }
        $r = $response->fetchRow();
        return $r["fullname"];
    } else {
        error(ERR_INV_ARG);
        return false;
    }
}

/** Get User FullName from User ID
 * fixme: Duplicate function
 *
 * Get a fullname when you have a userid.
 * @param int $id User ID
 * 
 * @return string Full Name
 */
function get_owner_from_id($id) {
    global $db;
    if (is_numeric($id)) {
        $response = $db->queryRow("SELECT fullname FROM users WHERE id=" . $db->quote($id, 'integer'));

        if ($response) {
            return $response["fullname"];
        } else {
            error(ERR_USER_NOT_EXIST);
        }
    }
    error(ERR_INV_ARG);
}

/** Get Full Names of owners for a Domain ID
 *
 * @todo also fetch the subowners
 *
 * @param int $id Domain ID
 *
 * @return string[] array of owners for domain
 */
function get_fullnames_owners_from_domainid($id) {

    global $db;
    if (is_numeric($id)) {
        $response = $db->query("SELECT users.id, users.fullname FROM users, zones WHERE zones.domain_id=" . $db->quote($id, 'integer') . " AND zones.owner=users.id ORDER by fullname");
        if ($response) {
            $names = array();
            while ($r = $response->fetchRow()) {
                $names[] = $r['fullname'];
            }
            return implode(', ', $names);
        }
        return "";
    }
    error(ERR_INV_ARG);
}

/** Verify User is Zone ID owner
 *
 * @param int $zoneid Zone ID
 *
 * @return int 1 if owner, 0 if not owner
 */
function verify_user_is_owner_zoneid($zoneid) {
    global $db;

    $userid = $_SESSION["userid"];
    if (is_numeric($zoneid)) {
        $response = $db->queryOne("SELECT zones.id FROM zones 
				WHERE zones.owner = " . $db->quote($userid, 'integer') . "
				AND zones.domain_id = " . $db->quote($zoneid, 'integer'));
        return ($response ? "1" : "0");
    }
    error(ERR_INV_ARG);
}

/** Get User Details
 *
 * Gets an array of all users and their details
 *
 * @param int $specific User ID (optional)
 *
 * @return mixed[] array of user details
 */
function get_user_detail_list($specific) {
    global $db;
    global $ldap_use;

    $userid = $_SESSION['userid'];

    // fixme: does this actually verify the permission?
    if (v_num($specific)) {
        $sql_add = "AND users.id = " . $db->quote($specific, 'integer');
    } else {
        if (verify_permission('user_view_others')) {
            $sql_add = "";
        } else {
            $sql_add = "AND users.id = " . $db->quote($userid, 'integer');
        }
    }

    $query = "SELECT users.id AS uid, 
			username, 
			fullname, 
			email, 
			description AS descr,
			active,";
    if ($ldap_use) {
        $query .= "use_ldap,";
    }

    $query .= "perm_templ.id AS tpl_id,
			perm_templ.name AS tpl_name,
			perm_templ.descr AS tpl_descr
			FROM users, perm_templ 
			WHERE users.perm_templ = perm_templ.id "
            . $sql_add . "
			ORDER BY username";

    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    while ($user = $response->fetchRow()) {
        $userlist[] = array(
            "uid" => $user['uid'],
            "username" => $user['username'],
            "fullname" => $user['fullname'],
            "email" => $user['email'],
            "descr" => $user['descr'],
            "active" => $user['active'],
            "use_ldap" => $user['use_ldap'],
            "tpl_id" => $user['tpl_id'],
            "tpl_name" => $user['tpl_name'],
            "tpl_descr" => $user['tpl_descr']
        );
    }
    return $userlist;
}

/** Get List of Permissions
 *
 * Get a list of permissions that are available. If first argument is "0", it
 * should return all available permissions. If the first argument is > "0", it
 * should return the permissions assigned to that particular template only. If
 * second argument is true, only the permission names are returned.
 *
 * @param int $templ_id Template ID (optional) [default=0]
 * @param boolean $return_name_only Return name only or all details (optional) [default=false]
 *
 * @return mixed[] array of permissions [id,name,descr] or permission names [name]
 */
function get_permissions_by_template_id($templ_id = 0, $return_name_only = false) {
    global $db;

    $limit = '';
    if ($templ_id > 0) {
        $limit = ", perm_templ_items 
			WHERE perm_templ_items.templ_id = " . $db->quote($templ_id, 'integer') . "
			AND perm_templ_items.perm_id = perm_items.id";
    }

    $query = "SELECT perm_items.id AS id, 
			perm_items.name AS name, 
			perm_items.descr AS descr
			FROM perm_items"
            . $limit . "
			ORDER BY name";
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    $permission_list = array();
    while ($permission = $response->fetchRow()) {
        if ($return_name_only == false) {
            $permission_list[] = array(
                "id" => $permission['id'],
                "name" => $permission['name'],
                "descr" => $permission['descr']
            );
        } else {
            $permission_list[] = $permission['name'];
        }
    }
    return $permission_list;
}

/** Get name and description of template from Template ID
 *
 * @param int $templ_id Template ID
 *
 * @return mixed[] Template details
 */
function get_permission_template_details($templ_id) {
    global $db;

    $query = "SELECT *
			FROM perm_templ
			WHERE perm_templ.id = " . $db->quote($templ_id, 'integer');

    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    $details = $response->fetchRow();
    return $details;
}

/** Add a Permission Template
 *
 * @param mixed[] $details Permission template details [templ_name,templ_descr,perm_id]
 *
 * @return boolean true on success, false otherwise
 */
function add_perm_templ($details) {
    global $db;
    global $db_layer;
    global $db_type;

    // Fix permission template name and description first. 

    $query = "INSERT INTO perm_templ (name, descr)
			VALUES ("
            . $db->quote($details['templ_name'], 'text') . ", "
            . $db->quote($details['templ_descr'], 'text') . ")";

    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    if ($db_layer == 'MDB2' && ($db_type == 'mysql' || $db_type == 'pgsql')) {
        $perm_templ_id = $db->lastInsertId('perm_templ', 'id');
    } else if ($db_layer == 'PDO' && $db_type == 'pgsql') {
        $perm_templ_id = $db->lastInsertId('perm_templ_id_seq');
    } else {
        $perm_templ_id = $db->lastInsertId();
    }

    if (isset($details['perm_id'])) {
        foreach ($details['perm_id'] AS $perm_id) {
            $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $db->quote($perm_templ_id, 'integer') . "," . $db->quote($perm_id, 'integer') . ")";
            $response = $db->query($query);
            if (PEAR::isError($response)) {
                error($response->getMessage());
                return false;
            }
        }
    }

    return true;
}

/** Update permission template details
 *
 * @param mixed[] $details Permission Template Details
 *
 * @return boolean true on success, false otherwise
 */
function update_perm_templ_details($details) {
    global $db;

    // Fix permission template name and description first. 

    $query = "UPDATE perm_templ 
			SET name = " . $db->quote($details['templ_name'], 'text') . ",
			descr = " . $db->quote($details['templ_descr'], 'text') . "
			WHERE id = " . $db->quote($details['templ_id'], 'integer');
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    // Now, update list of permissions assigned to this template. We could do 
    // this The Correct Way [tm] by comparing the list of permissions that are
    // currently assigned with a list of permissions that should be assigned and
    // apply the difference between these two lists to the database. That sounds 
    // like too much work. Just delete all the permissions currently assigned to 
    // the template, than assign all the permessions the template should have.

    $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $details['templ_id'];
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    if (isset($details['perm_id'])) {
        foreach ($details['perm_id'] AS $perm_id) {
            $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $db->quote($details['templ_id'], 'integer') . "," . $db->quote($perm_id, 'integer') . ")";
            $response = $db->query($query);
            if (PEAR::isError($response)) {
                error($response->getMessage());
                return false;
            }
        }
    }

    return true;
}

/** Update User Details
 *
 * @param mixed[] $details User details
 *
 * @return boolean true on success, false otherise
 */
function update_user_details($details) {

    global $db;
    global $password_encryption;

    verify_permission('user_edit_own') ? $perm_edit_own = "1" : $perm_edit_own = "0";
    verify_permission('user_edit_others') ? $perm_edit_others = "1" : $perm_edit_others = "0";
    verify_permission('templ_perm_edit') ? $perm_templ_perm_edit = "1" : $perm_templ_perm_edit = "0";
    verify_permission('user_is_ueberuser') ? $perm_is_godlike = "1" : $perm_is_godlike = "0";

    if (($details['uid'] == $_SESSION["userid"] && $perm_edit_own == "1") ||
            ($details['uid'] != $_SESSION["userid"] && $perm_edit_others == "1" )) {

        if (!is_valid_email($details['email'])) {
            error(ERR_INV_EMAIL);
            return false;
        }

        if (!isset($details['active']) || $details['active'] != "on") {
            $active = 0;
        } else {
            $active = 1;
        }
        if (isset($details['use_ldap'])) {
            $use_ldap = 1;
        } else {
            $use_ldap = 0;
        }

        // Before updating the database we need to check whether the user wants to 
        // change the username. If the user wants to change the username, we need 
        // to make sure it doesn't already exists. 
        //
		// First find the current username of the user ID we want to change. If the 
        // current username is not the same as the username that was given by the 
        // user, the username should apparantly changed. If so, check if the "new" 
        // username already exists.
        $query = "SELECT username FROM users WHERE id = " . $db->quote($details['uid'], 'integer');
        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }

        $usercheck = array();
        $usercheck = $response->fetchRow();

        if ($usercheck['username'] != $details['username']) {
            // Username of user ID in the database is different from the name
            // we have been given. User wants a change of username. Now, make
            // sure it doesn't already exist.
            $query = "SELECT id FROM users WHERE username = " . $db->quote($details['username'], 'text');
            $response = $db->queryOne($query);
            if ($response) {
                error(ERR_USER_EXIST);
                return false;
            }
        }

        // So, user doesn't want to change username or, if he wants, there is not
        // another user that goes by the wanted username. So, go ahead!

        $query = "UPDATE users SET
				username = " . $db->quote($details['username'], 'text') . ",
				fullname = " . $db->quote($details['fullname'], 'text') . ",
				email = " . $db->quote($details['email'], 'text') . ",
				description = " . $db->quote($details['descr'], 'text') . ", 
				active = " . $db->quote($active, 'integer');

        // If the user is alllowed to change the permission template, set it.
        if ($perm_templ_perm_edit == "1") {
            $query .= ", perm_templ = " . $db->quote($details['templ_id'], 'integer');
        }

        // If the user is allowed to change the use_ldap flag, set it.
        if ($perm_is_godlike == "1") {
            $query .= ", use_ldap = " . $db->quote($use_ldap, 'integer');
        }

        if (isset($details['password']) && $details['password'] != "") {
            if ($password_encryption == 'md5salt') {
                $query .= ", password = " . $db->quote(gen_mix_salt($details['password']), 'text');
            } else {
                $query .= ", password = " . $db->quote(md5($details['password']), 'text');
            }
        }

        $query .= " WHERE id = " . $db->quote($details['uid'], 'integer');

        $response = $db->query($query);
        if (PEAR::isError($response)) {
            error($response->getMessage());
            return false;
        }
    } else {
        error(ERR_PERM_EDIT_USER);
        return false;
    }
    return true;
}

/** Add a new user
 *
 * @param mixed[] $details Array of User details
 *
 * @return boolean true on success, false otherwise
 */
function add_new_user($details) {
    global $db;
    global $password_encryption;

    if (!verify_permission('user_add_new')) {
        error(ERR_PERM_ADD_USER);
        return false;
    } elseif (user_exists($details['username'])) {
        error(ERR_USER_EXIST);
        return false;
    } elseif (!is_valid_email($details['email'])) {
        error(ERR_INV_EMAIL);
        return false;
    } elseif ($details['active'] == 1) {
        $active = 1;
    } else {
        $active = 0;
    }

    $query = "INSERT INTO users (username, password, fullname, email, description,";
    if (verify_permission('user_edit_templ_perm')) {
        $query .= ' perm_templ,';
    }

    if ($password_encryption == 'md5salt') {
        $password_hash = gen_mix_salt($details['password']);
    } else {
        $password_hash = md5($details['password']);
    }

    $query .= " active) VALUES ("
            . $db->quote($details['username'], 'text') . ", "
            . $db->quote($password_hash, 'text') . ", "
            . $db->quote($details['fullname'], 'text') . ", "
            . $db->quote($details['email'], 'text') . ", "
            . $db->quote($details['descr'], 'text') . ", ";
    if (verify_permission('user_edit_templ_perm')) {
        $query .= $db->quote($details['perm_templ'], 'integer') . ", ";
    }
    $query .= $db->quote($active, 'integer')
            . ")";
    $response = $db->query($query);
    if (PEAR::isError($response)) {
        error($response->getMessage());
        return false;
    }

    return true;
}
