<?php 
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/*
 * these are the standard listeners.
 * if you want to use your own put them first or replace them
 * first read gets used;
 */

/**
 * Verify User has Permission Name
 *
 * Function to see if user has right to do something. It will check if
 * user has "ueberuser" bit set. If it isn't, it will check if the user has
 * the specific permission. It returns "false" if the user doesn't have the
 * right, and "true" if the user has.
 *
 * @param array arg[0] Permission name
 *
 * @return boolean true if user has permission, false otherwise
 *
 */
add_listener('verify_permission', 'verify_permission_local');

/**
 * Retrieve all users
 *
 * Its to show_users therefore the odd name. Has to be changed.
 *
 * @param int $id Exclude User ID
 * @param int $rowstart Startring row number
 * @param int $rowamount Number of rows to return this query
 *
 * @return mixed[] array with all users [id,username,fullname,email,description,active,numdomains]
 */
add_listener('show_users', 'show_users_local');

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
add_listener('change_user_pass', 'change_user_pass_local');

/**
 * Get a list of all available permission templates
 *
 * @return mixed[] array of templates [id, name, descr]
 */
add_listener('list_permission_templates', 'list_permission_templates_local');

/**
 * Check if Valid User
 *
 * Check if the given $userid is connected to a valid user.
 *
 * @param int $id User ID
 *
 * @return boolean true if user exists, false if users doesnt exist
 */
add_listener('is_valid_user', 'is_valid_user_local');

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
add_listener('delete_user', 'delete_user_local');

/**
 * Delete Permission Template ID
 *
 * @param int $ptid Permission template ID
 *
 * @return boolean true on success, false otherwise
 */
add_listener('delete_perm_templ', 'delete_perm_templ_local');

/**
 * Modify User Details
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
add_listener('edit_user', 'edit_user_local');

/**
 * Get User FullName from User ID
 *
 * Get a fullname when you have a userid.
 *
 * @param int $id
 *        	User ID
 *
 * @return string Full Name
 */
add_listener('get_fullname_from_userid', 'get_fullname_from_userid_local');

/**
 * Get User FullName from User ID
 * fixme: Duplicate function
 *
 * Get a fullname when you have a userid.
 *
 * @param int $id User ID
 *
 * @return string Full Name
 */
add_listener('get_owner_from_id', 'get_owner_from_id_local');

/**
 * Get Full Names of owners for a Domain ID
 *
 * @todo also fetch the subowners
 *
 * @param int $id Domain ID
 *
 * @return string[] array of owners for domain
 */
add_listener('get_fullnames_owners_from_domainid', 'get_fullnames_owners_from_domainid_local');

/**
 * Verify User is Zone ID owner
 *
 * @param int $zoneid Zone ID
 *
 * @return int 1 if owner, 0 if not owner
 */
add_listener('verify_user_is_owner_zoneid', 'verify_user_is_owner_zoneid_local');

/**
 * Get User Details
 *
 * Gets an array of all users and their details
 *
 * @param int $specific User ID (optional)
 *
 * @return mixed[] array of user details
 */
add_listener('get_user_detail_list', 'get_user_detail_list_local');

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
add_listener('get_permissions_by_template_id', 'get_permissions_by_template_id_local');

/**
 * Get name and description of template from Template ID
 *
 * @param int $templ_id Template ID
 *
 * @return mixed[] Template details
 */
add_listener('get_permission_template_details', 'get_permission_template_details_local');

/**
 * Add a Permission Template
 *
 * @param mixed[] $details Permission template details [templ_name,templ_descr,perm_id]
 *
 * @return boolean true on success, false otherwise
 */
add_listener('add_perm_templ', 'add_perm_templ_local');

/**
 * Update permission template details
 *
 * @param mixed[] $details Permission Template Details
 *
 * @return boolean true on success, false otherwise
 */
add_listener('update_perm_templ_details', 'update_perm_templ_details_local');

/**
 * Update User Details
 *
 * @param mixed[] $details User details
 *
 * @return boolean true on success, false otherwise
 */
add_listener('update_user_details', 'update_user_details_local');

/**
 * Add a new user
 *
 * @param mixed[] $details Array of User details
 *
 * @return boolean true on success, false otherwise
 */
add_listener('add_new_user', 'add_new_user_local');