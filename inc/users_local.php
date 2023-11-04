<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * User profile functions
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2023 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 *
 */

use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\UI\ErrorPresenter;
use Poweradmin\LegacyConfiguration;
use Poweradmin\DnsRecord;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/session.inc.php';

/**
 * Verify User has Permission Name
 *
 * Function to see if user has right to do something. It will check if
 * user has "ueberuser" bit set. If it isn't, it will check if the user has
 * the specific permission. It returns "false" if the user doesn't have the
 * right, and "true" if the user has.
 *
 * @param array $arg Permission name
 *
 * @return boolean true if user has permission, false otherwise
 */
function verify_permission_local($arg)
{
    if (is_array($arg)) {
        $permission = $arg [0];
    } else {
        $permission = $arg;
    }

    static $cache = false;

    if ($cache !== false) {
        return array_key_exists('user_is_ueberuser', $cache) || array_key_exists($permission, $cache);
    }

    global $db;
    if ((!isset($_SESSION['userid'])) || (!is_object($db))) {
        return 0;
    }
    // Set current user ID.
    $userid = $_SESSION['userid'];

    $query = $db->prepare("SELECT
        perm_items.name AS permission
        FROM perm_templ_items
        LEFT JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
        LEFT JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
        LEFT JOIN users ON perm_templ.id = users.perm_templ
        WHERE users.id = ?");
    $query->execute(array($userid));
    $cache = $query->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

    return array_key_exists('user_is_ueberuser', $cache) || array_key_exists($permission, $cache);
}

/**
 * Get a list of all available permission templates
 *
 * @return mixed[] array of templates [id, name, descr]
 */
function list_permission_templates_local()
{
    global $db;
    $query = "SELECT * FROM perm_templ ORDER BY name";
    $response = $db->query($query);

    $template_list = array();
    while ($template = $response->fetch()) {
        $template_list [] = array(
            "id" => $template ['id'],
            "name" => $template ['name'],
            "descr" => $template ['descr']
        );
    }
    return $template_list;
}

/**
 * Retrieve all users
 *
 * It's to show_users therefore the odd name. Has to be changed.
 *
 * @param int $id Exclude User ID
 * @param int $rowstart Startring row number
 * @param int $rowamount Number of rows to return this query
 *
 * @return mixed[] array with all users [id,username,fullname,email,description,active,numdomains]
 */
function show_users_local($id = '', $rowstart = 0, $rowamount = 9999999)
{

    global $db;
    $add = '';
    if (is_numeric($id)) {
        // When a user id is given, it is excluded from the userlist returned.
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
    $ret = array();
    while ($r = $response->fetch()) {
        $ret [] = array(
            "id" => $r ["id"],
            "username" => $r ["username"],
            "fullname" => $r ["fullname"],
            "email" => $r ["email"],
            "description" => $r ["description"],
            "active" => $r ["active"],
            "numdomains" => $r ["aantal"]
        );
    }
    return $ret;
}

/**
 * Check if Valid User
 *
 * Check if the given $userid is connected to a valid user.
 *
 * @param int $id User ID
 *
 * @return boolean true if user exists, false if users doesnt exist
 */
function is_valid_user_local($id)
{
    global $db;
    if (is_numeric($id)) {
        $response = $db->queryOne("SELECT id FROM users WHERE id=" . $db->quote($id, 'integer'));
        return ($response ? true : false);
    }
}

/**
 * Check if Username Exists
 *
 * Checks if a given username exists in the database.
 *
 * @param string $user Username
 *
 * @return boolean true if exists, false if not
 */
function user_exists($user)
{
    global $db;
    $response = $db->queryOne("SELECT id FROM users WHERE username=" . $db->quote($user, 'text'));
    return ($response ? true : false);
}

/**
 * Delete User ID
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
function delete_user_local($uid, $zones)
{
    global $db;

    if (($uid != $_SESSION ['userid'] && !verify_permission_local('user_edit_others')) || ($uid == $_SESSION ['userid'] && !verify_permission_local('user_edit_own'))) {
        $error = new ErrorMessage(_("You do not have the permission to delete this user."));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    } else {

        if (is_array($zones)) {
            foreach ($zones as $zone) {
                if ($zone ['target'] == "delete") {
                    DnsRecord::delete_domain($zone ['zid']);
                } elseif ($zone ['target'] == "new_owner") {
                    DnsRecord::add_owner_to_zone($zone ['zid'], $zone ['newowner']);
                }
            }
        }

        $query = "DELETE FROM zones WHERE owner = " . $db->quote($uid, 'integer');
        $db->query($query);

        $query = "DELETE FROM users WHERE id = " . $db->quote($uid, 'integer');
        $db->query($query);

        ZoneTemplate::delete_zone_templ_userid($uid);
    }
    return true;
}

/**
 * Delete Permission Template ID
 *
 * @param int $ptid Permission template ID
 *
 * @return boolean true on success, false otherwise
 */
function delete_perm_templ_local($ptid)
{
    global $db;

    if (!(verify_permission_local('user_edit_templ_perm'))) {
        $error = new ErrorMessage(_("You do not have the permission to delete permission templates."));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
    } else {
        $query = "SELECT id FROM users WHERE perm_templ = " . $ptid;
        $response = $db->queryOne($query);

        if ($response) {
            $error = new ErrorMessage(_('This template is assigned to at least one user.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $ptid;
            $db->query($query);

            $query = "DELETE FROM perm_templ WHERE id = " . $ptid;
            $db->query($query);
            return true;
        }
    }
}

/**
 * Modify User Details
 *
 * Edit the information of a user. Sloppy implementation with too many queries.
 *
 * @param int $id User ID
 * @param string $user Username
 * @param string $fullname Full Name
 * @param string $email Email address
 * @param string $perm_templ Permission Template Name
 * @param string $description Description
 * @param int $active Active User
 * @param string $user_password Password
 *
 * @return boolean true if succesful, false otherwise
 */
function edit_user_local($id, $user, $fullname, $email, $perm_templ, $description, $active, $user_password, $i_use_ldap)
{
    global $db;

    $perm_edit_own = verify_permission_local('user_edit_own');
    $perm_edit_others = verify_permission_local('user_edit_others');

    if (($id == $_SESSION ["userid"] && $perm_edit_own) || ($id != $_SESSION ["userid"] && $perm_edit_others)) {

        if (!Validation::is_valid_email($email)) {
            $error = new ErrorMessage(_('Enter a valid email address.'));

            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($active != 1) {
            $active = 0;
        }

        // Before updating the database we need to check whether the user wants to
        // change the username. If the user wants to change the username, we need
        // to make sure it doesn't already exist.
        //
        // First find the current username of the user ID we want to change. If the
        // current username is not the same as the username that was given by the
        // user, the username should apparently be changed. If so, check if the "new"
        // username already exists.

        $query = "SELECT username FROM users WHERE id = " . $db->quote($id, 'integer');
        $response = $db->query($query);

        $usercheck = $response->fetch();

        if ($usercheck ['username'] != $user) {

            // Username of user ID in the database is different from the name
            // we have been given. User wants a change of username. Now, make
            // sure it doesn't already exist.

            $query = "SELECT id FROM users WHERE username = " . $db->quote($user, 'text');
            $response = $db->queryOne($query);
            if ($response) {
                $error = new ErrorMessage(_('Username exist already, please choose another one.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        }

        // So, user doesn't want to change username or, if he wants, there is not
        // another user that goes by the wanted username. So, go ahead!

        $query = "UPDATE users SET username = " . $db->quote($user, 'text') . ",
fullname = " . $db->quote($fullname, 'text') . ",
email = " . $db->quote($email, 'text') . ",";
        if (verify_permission_local('user_edit_templ_perm')) {
            $query .= "perm_templ = " . $db->quote($perm_templ, 'integer') . ",";
        }
        $query .= "description = " . $db->quote($description, 'text') . ",
				active = " . $db->quote($active, 'integer') . ",
				use_ldap = " . $db->quote($i_use_ldap ?: 0, 'integer');

        $edit_own_perm = verify_permission_local('user_edit_own');
        $passwd_edit_others_perm = verify_permission_local('user_passwd_edit_others');

        if ($user_password != "" && $edit_own_perm || $passwd_edit_others_perm) {
            $config = new LegacyConfiguration();
            $userAuthService = new UserAuthenticationService(
                $config->get('password_encryption'),
                $config->get('password_encryption_cost')
            );

            $passwordHash = $i_use_ldap ? 'LDAP_USER' : $userAuthService->hashPassword($user_password);
            $query .= ", password = " . $db->quote($passwordHash, 'text');
        }

        $query .= " WHERE id = " . $db->quote($id, 'integer');
        $db->query($query);
    } else {
        $error = new ErrorMessage(_("You do not have the permission to edit this user."));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
        return false;
    }
    return true;
}

/**
 * Change User Password
 *
 * @param int $id User ID
 * @param string $password New password
 * @return void
 */
function update_user_password($id, $user_pass): void
{
    global $db;

    $config = new LegacyConfiguration();
    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );
    $query = "UPDATE users SET password = " . $db->quote($userAuthService->hashPassword($user_pass), 'text') . " WHERE id = " . $db->quote($id, 'integer');
    $db->query($query);
}

/**
 * Change User Password
 *
 * Change the pass of the user.
 * The user is automatically logged out after the pass change.
 *
 * @param mixed[] $details User Details
 *
 * @return null
 */
function change_user_pass_local(array $details)
{
    global $db;

    if ($details['new_password'] != $details['new_password2']) {
        $error = new ErrorMessage(_('The two new password fields do not match.'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    }

    $query = "SELECT id, password FROM users WHERE username = {$db->quote($_SESSION ["userlogin"], 'text')}";
    $response = $db->queryRow($query);

    $config = new LegacyConfiguration();
    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );

    if ($userAuthService->verifyPassword($details['old_password'], $response['password'])) {
        $query = "UPDATE users SET password = {$db->quote($userAuthService->hashPassword($details['new_password']), 'text')} WHERE id = {$db->quote($response['id'], 'integer')}";
        $db->query($query);

        logout(_('Password has been changed, please login.'), 'success');
    }

    $error = new ErrorMessage(_('You did not enter the correct current password.'));
    $errorPresenter = new ErrorPresenter();
    $errorPresenter->present($error);

    return false;
}

/**
 * Get User FullName from User ID
 *
 * Get a fullname when you have an userid.
 *
 * @param int $id User ID
 *
 * @return string Full Name
 */
function get_fullname_from_userid_local($id)
{
    global $db;
    if (is_numeric($id)) {
        $response = $db->query("SELECT fullname FROM users WHERE id=" . $db->quote($id, 'integer'));
        $r = $response->fetch();
        return $r["fullname"];
    } else {
        $error = new ErrorMessage(_('Invalid argument(s) given to function %s'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    }
}

/**
 * Get Full Names of owners for a Domain ID
 *
 * @param int $id Domain ID
 *
 * @return string|void array of owners for domain
 * @todo also fetch the subowners
 *
 */
function get_fullnames_owners_from_domainid_local($id)
{
    global $db;
    if (is_numeric($id)) {
        $response = $db->query("SELECT users.id, users.fullname FROM users, zones WHERE zones.domain_id=" . $db->quote($id, 'integer') . " AND zones.owner=users.id ORDER by fullname");
        if ($response) {
            $names = array();
            while ($r = $response->fetch()) {
                $names [] = $r ['fullname'];
            }
            return implode(', ', $names);
        }
        return "";
    }
    $error = new ErrorMessage(_('Invalid argument(s) given to function %s'));
    $errorPresenter = new ErrorPresenter();
    $errorPresenter->present($error);
}

/**
 * Verify User is Zone ID owner
 *
 * @param int $zoneid Zone ID
 *
 * @return string|void 1 if owner, 0 if not owner
 */
function verify_user_is_owner_zoneid_local($zoneid)
{
    global $db;

    $userid = $_SESSION ["userid"];
    if (is_numeric($zoneid)) {
        $response = $db->queryOne("SELECT zones.id FROM zones
				WHERE zones.owner = " . $db->quote($userid, 'integer') . "
				AND zones.domain_id = " . $db->quote($zoneid, 'integer'));
        return (bool)$response;
    }
    $error = new ErrorMessage(_('Invalid argument(s) given to function %s'));
    $errorPresenter = new ErrorPresenter();
    $errorPresenter->present($error);
}

/**
 * Get User Details
 *
 * Gets an array of all users and their details
 *
 * @param int $specific User ID (optional)
 *
 * @return mixed[] array of user details
 */
function get_user_detail_list_local($specific)
{
    global $db;
    global $ldap_use;

    $userid = $_SESSION ['userid'];

    // fixme: does this actually verify the permission?
    if (Validation::is_number($specific)) {
        $sql_add = "AND users.id = " . $db->quote($specific, 'integer');
    } else {
        if (verify_permission_local('user_view_others')) {
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
			WHERE users.perm_templ = perm_templ.id " . $sql_add . "
			ORDER BY username";

    $response = $db->query($query);

    while ($user = $response->fetch()) {
        $userlist [] = array(
            "uid" => $user ['uid'],
            "username" => $user ['username'],
            "fullname" => $user ['fullname'],
            "email" => $user ['email'],
            "descr" => $user ['descr'],
            "active" => $user ['active'],
            "use_ldap" => $user['use_ldap'] ?? 0,
            "tpl_id" => $user ['tpl_id'],
            "tpl_name" => $user ['tpl_name'],
            "tpl_descr" => $user ['tpl_descr']
        );
    }
    return $userlist;
}

/**
 * Get List of Permissions
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
function get_permissions_by_template_id_local($templ_id = 0, $return_name_only = false)
{
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
			FROM perm_items" . $limit . "
			ORDER BY name";
    $response = $db->query($query);

    $permission_list = array();
    while ($permission = $response->fetch()) {
        if ($return_name_only == false) {
            $permission_list [] = array(
                "id" => $permission ['id'],
                "name" => $permission ['name'],
                "descr" => $permission ['descr']
            );
        } else {
            $permission_list [] = $permission ['name'];
        }
    }
    return $permission_list;
}

/**
 * Get name and description of template from Template ID
 *
 * @param int $templ_id Template ID
 *
 * @return mixed[] Template details
 */
function get_permission_template_details_local($templ_id)
{
    global $db;

    $query = "SELECT *
			FROM perm_templ
			WHERE perm_templ.id = " . $db->quote($templ_id, 'integer');

    $response = $db->query($query);
    return $response->fetch();
}

/**
 * Add a Permission Template
 *
 * @param mixed[] $details Permission template details [templ_name,templ_descr,perm_id]
 *
 * @return boolean true on success, false otherwise
 */
function add_perm_templ_local($details)
{
    global $db;
    global $db_type;

    $query = "INSERT INTO perm_templ (name, descr)
			VALUES (" . $db->quote($details['templ_name'], 'text') . ", " . $db->quote($details['templ_descr'], 'text') . ")";

    $db->query($query);

    if ($db_type == 'pgsql') {
        $perm_templ_id = $db->lastInsertId('perm_templ_id_seq');
    } else {
        $perm_templ_id = $db->lastInsertId();
    }

    if (isset($details['perm_id'])) {
        foreach ($details['perm_id'] as $perm_id) {
            $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $db->quote($perm_templ_id, 'integer') . "," . $db->quote($perm_id, 'integer') . ")";
            $db->query($query);
        }
    }

    return true;
}

/**
 * Update permission template details
 *
 * @param mixed[] $details Permission Template Details
 *
 * @return boolean true on success, false otherwise
 */
function update_perm_templ_details_local($details)
{
    global $db;

    // Fix permission template name and description first.

    $query = "UPDATE perm_templ
			SET name = " . $db->quote($details['templ_name'], 'text') . ",
			descr = " . $db->quote($details['templ_descr'], 'text') . "
			WHERE id = " . $db->quote($details['templ_id'], 'integer');
    $db->query($query);

    // Now, update list of permissions assigned to this template. We could do
    // this The Correct Way [tm] by comparing the list of permissions that are
    // currently assigned with a list of permissions that should be assigned and
    // apply the difference between these two lists to the database. That sounds
    // like too much work. Just delete all the permissions currently assigned to
    // the template, then assign all the permissions the template should have.

    $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $details['templ_id'];
    $db->query($query);

    if (isset($details['perm_id'])) {
        foreach ($details['perm_id'] as $perm_id) {
            $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $db->quote($details['templ_id'], 'integer') . "," . $db->quote($perm_id, 'integer') . ")";
            $db->query($query);
        }
    }

    return true;
}

/**
 * Update User Details
 *
 * @param mixed[] $details User details
 *
 * @return boolean true on success, false otherwise
 */
function update_user_details_local($details)
{
    global $db;

    $perm_edit_own = (bool)verify_permission_local('user_edit_own');
    $perm_edit_others = (bool)verify_permission_local('user_edit_others');
    $perm_templ_perm_edit = (bool)verify_permission_local('templ_perm_edit');
    $perm_is_godlike = (bool)verify_permission_local('user_is_ueberuser');

    if (($details['uid'] == $_SESSION ["userid"] && $perm_edit_own) || ($details['uid'] != $_SESSION ["userid"] && $perm_edit_others)) {

        if (!Validation::is_valid_email($details['email'])) {
            $error = new ErrorMessage(_('Enter a valid email address.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if (!isset($details['active']) || $details['active'] != "on") {
            $active = 0;
        } else {
            $active = 1;
        }

        if (isset($details['use_ldap']) && $details['use_ldap'] == "1") {
            $use_ldap = 1;
        } else {
            $use_ldap = 0;
        }

        // Before updating the database we need to check whether the user wants to
        // change the username. If the user wants to change the username, we need
        // to make sure it doesn't already exist.
        //
        // First find the current username of the user ID we want to change. If the
        // current username is not the same as the username that was given by the
        // user, the username should apparently be changed. If so, check if the "new"
        // username already exists.
        $query = "SELECT username FROM users WHERE id = " . $db->quote($details['uid'], 'integer');
        $response = $db->query($query);

        $usercheck = $response->fetch();

        if ($usercheck ['username'] != $details['username']) {
            // Username of user ID in the database is different from the name
            // we have been given. User wants a change of username. Now, make
            // sure it doesn't already exist.
            $query = "SELECT id FROM users WHERE username = " . $db->quote($details['username'], 'text');
            $response = $db->queryOne($query);
            if ($response) {
                $error = new ErrorMessage(_('Username exist already, please choose another one.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        }

        // So, user doesn't want to change username or, if he wants, there is not
        // another user that goes by the wanted username. So, go ahead!

        $query = "UPDATE users SET username = " . $db->quote($details['username'], 'text') . ",
            fullname = " . $db->quote($details['fullname'], 'text') . ",
            email = " . $db->quote($details['email'], 'text') . ",
            active = " . $db->quote($active, 'integer');

        // If the user is allowed to change the permission template, set it.
        if ($perm_templ_perm_edit == "1") {
            $query .= ", perm_templ = " . $db->quote($details['templ_id'], 'integer');
        }

        // If the user is allowed to change the use_ldap flag, set it.
        if ($perm_is_godlike == "1") {
            $query .= ", use_ldap = " . $db->quote($use_ldap, 'integer');
        }

        $passwd_edit_others_perm = (bool)verify_permission_local('user_passwd_edit_others');
        if (isset($details['password']) && $details['password'] != "" && $passwd_edit_others_perm) {
            $config = new LegacyConfiguration();
            $userAuthService = new UserAuthenticationService(
                $config->get('password_encryption'),
                $config->get('password_encryption_cost')
            );
            $query .= ", password = " . $db->quote($userAuthService->hashPassword($details['password'], 'text'));
        }

        $query .= " WHERE id = " . $db->quote($details['uid'], 'integer');

        $db->query($query);
    } else {
        $error = new ErrorMessage(_("You do not have the permission to edit this user."));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    }
    return true;
}

/**
 * Add a new user
 *
 * @param mixed[] $details Array of User details
 *
 * @return boolean true on success, false otherwise
 */
function add_new_user_local($details)
{
    global $db;
    global $ldap_use;

    if (!verify_permission_local('user_add_new')) {
        $error = new ErrorMessage(_("You do not have the permission to add a new user."));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    } elseif (user_exists($details['username'])) {
        $error = new ErrorMessage(_('Username exist already, please choose another one.'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    } elseif ($details['username'] === '') {
        $error = new ErrorMessage(_('Enter a valid user name.'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    } elseif (!Validation::is_valid_email($details['email'])) {
        $error = new ErrorMessage(_('Enter a valid email address.'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);

        return false;
    } elseif ($details['active'] == 1) {
        $active = 1;
    } else {
        $active = 0;
    }

    if ($ldap_use && $details['use_ldap'] == 1) {
        $use_ldap = 1;
        $password_hash = 'LDAP_USER';
    } else {
        $use_ldap = 0;
        $config = new LegacyConfiguration();
        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );
        $password_hash = $userAuthService->hashPassword($details['password']);
    }

    $query = "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (" . $db->quote($details['username'], 'text') . ", " . $db->quote($password_hash, 'text') . ", " . $db->quote($details['fullname'], 'text') . ", " . $db->quote($details['email'], 'text') . ", " . $db->quote($details['descr'], 'text') . ", ";

    if (verify_permission_local('user_edit_templ_perm')) {
        $query .= $db->quote($details['perm_templ'], 'integer') . ", ";
    } else {
        $current_user = get_user_detail_list_local($_SESSION['userid']);
        $query .= $db->quote($current_user[0]['tpl_id'], 'integer') . ", ";
    }
    $query .= $db->quote($active, 'integer') . ", " . $db->quote($use_ldap, 'integer') . ")";
    $db->query($query);

    return true;
}
