<?php 
<<<<<<< Upstream, based on branch 'modular_auth' of https://github.com/henkloke/poweradmin.git
=======
//add_listener('verify_permission', 'verify_permission_wefact');
>>>>>>> 0263780 Merged
/*
 * 
 * these are the standard listeners.
 * if you want to use your own put them first or replace them
 * first read gets used;
 */
add_listener('verify_permission', 'verify_permission_local');
add_listener('show_users', 'show_users_local');
add_listener('change_user_pass', 'change_user_pass_local');
add_listener('list_permission_templates', 'list_permission_templates_local');
add_listener('is_valid_user', 'is_valid_user_local');
add_listener('delete_user', 'delete_user_local');
add_listener('delete_perm_templ', 'delete_perm_templ_local');
add_listener('edit_user', 'edit_user_local');
add_listener('get_fullname_from_userid', 'get_fullname_from_userid_local');
add_listener('get_owner_from_id', 'get_owner_from_id_local');
add_listener('get_fullnames_owners_from_domainid', 'get_fullnames_owners_from_domainid_local');
add_listener('verify_user_is_owner_zoneid', 'verify_user_is_owner_zoneid_local');
add_listener('get_user_detail_list', 'get_user_detail_list_local');
add_listener('get_permissions_by_template_id', 'get_permissions_by_template_id_local');
add_listener('get_permission_template_details', 'get_permission_template_details_local');
add_listener('add_perm_templ', 'add_perm_templ_local');
add_listener('update_perm_templ_details', 'update_perm_templ_details_local');
add_listener('update_user_details', 'update_user_details_local');
<<<<<<< Upstream, based on branch 'modular_auth' of https://github.com/henkloke/poweradmin.git
add_listener('add_new_user', 'add_new_user_local');
=======
add_listener('add_new_user', 'add_new_user_local');
//add_listener('', '_local');
//add_listener('', '_local');
//add_listener('', '_local');


>>>>>>> 0263780 Merged
