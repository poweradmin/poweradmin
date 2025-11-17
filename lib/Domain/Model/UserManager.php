<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Model;

use PDO;
use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Service\MessageService;

class UserManager
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;

    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
    }

    /**
     * Verify User has Permission Name
     *
     * Function to see if user has right to do something. It will check if
     * user has "ueberuser" bit set. If it isn't, it will check if the user has
     * the specific permission from either their direct user template or from
     * any groups they belong to. It returns "false" if the user doesn't have the
     * right, and "true" if the user has.
     *
     * This function checks both:
     * 1. Direct user permissions (from user's perm_templ)
     * 2. Group permissions (from user_groups via user_group_members)
     *
     * @param string $arg Permission name
     *
     * @return boolean true if user has permission, false otherwise
     */
    public static function verifyPermission(object $db, string $arg): bool
    {
        $permission = $arg;

        static $cache = false;

        if ($cache !== false) {
            return array_key_exists('user_is_ueberuser', $cache) || array_key_exists($permission, $cache);
        }

        if ((!isset($_SESSION['userid'])) || (!is_object($db))) {
            return false;
        }

        // Query to get both direct user permissions and group permissions
        // UNION combines permissions from both sources
        // Note: We filter out NULL permission names to handle orphaned template items
        $query = $db->prepare("
            SELECT perm_items.name AS permission
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            INNER JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            INNER JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = ? AND perm_items.name IS NOT NULL

            UNION

            SELECT pi.name AS permission
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE ugm.user_id = ? AND pi.name IS NOT NULL
        ");
        $query->execute(array($_SESSION['userid'], $_SESSION['userid']));
        $cache = $query->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

        return array_key_exists('user_is_ueberuser', $cache) || array_key_exists($permission, $cache);
    }

    /**
     * Check if a specific user has superuser permission
     *
     * Function to check if a specific user ID has the "user_is_ueberuser" permission.
     *
     * @param PDOCommon $db Database connection
     * @param int $userId User ID to check
     *
     * @return bool true if user is superuser, false otherwise
     */
    public static function isUserSuperuser(PDOCommon $db, int $userId): bool
    {
        $query = $db->prepare("SELECT COUNT(*) as count
            FROM perm_templ_items
            LEFT JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            LEFT JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            LEFT JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = :userId AND perm_items.name = 'user_is_ueberuser'");
        $query->execute([':userId' => $userId]);
        $result = $query->fetch();

        return $result && $result['count'] > 0;
    }

    /**
     * Get a list of all available permission templates
     *
     * @return array array of templates [id, name, descr]
     */
    public static function listPermissionTemplates($db): array
    {
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
     * Get the permission template with the minimum number of permissions
     * Useful for setting a secure default when creating new users
     *
     * @param object $db Database connection
     * @return int|null Template ID with minimal permissions, or null if no templates exist
     */
    public static function getMinimalPermissionTemplateId($db): ?int
    {
        // Find the template with the fewest permissions assigned
        // If multiple templates have the same number of permissions, prefer by name order
        // This query returns the template with 0 or minimal permissions
        $query = "SELECT pt.id, pt.name, COUNT(pti.perm_id) as perm_count
                  FROM perm_templ pt
                  LEFT JOIN perm_templ_items pti ON pt.id = pti.templ_id
                  GROUP BY pt.id, pt.name
                  ORDER BY perm_count ASC, pt.name ASC
                  LIMIT 1";

        $stmt = $db->query($query);
        $result = $stmt->fetch();

        return $result ? (int)$result['id'] : null;
    }

    /**
     * Retrieve all users
     *
     * It's to show_users therefore the odd name. Has to be changed.
     *
     * @param int|string $id Exclude User ID
     * @param int $rowstart Startring row number
     * @param int $rowamount Number of rows to return this query
     *
     * @return array array with all users [id,username,fullname,email,description,active,numdomains]
     */
    public static function showUsers($db, int|string $id = '', int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS): array
    {
        $add = '';
        $params = [];
        if (is_numeric($id)) {
            // When a user id is given, it is excluded from the userlist returned.
            $add = " WHERE users.id != :exclude_id";
            $params[':exclude_id'] = $id;
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

        if ($rowamount < Constants::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowamount;
            if ($rowstart > 0) {
                $query .= " OFFSET " . $rowstart;
            }
        }

        // Execute the huge query.
        if (!empty($params)) {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $response = $stmt;
        } else {
            $response = $db->query($query);
        }

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
    public static function isValidUser($db, int $id): bool
    {
        $stmt = $db->prepare("SELECT id FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $response = $stmt->fetchColumn();
        return (bool)$response;
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
    public static function userExists($db, string $user): bool
    {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
        $stmt->execute([':username' => $user]);
        $response = $stmt->fetchColumn();
        return (bool)$response;
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
     * @param array $zones Array of zones
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteUser(int $uid, array $zones): bool
    {
        if (($uid != $_SESSION['userid'] && !self::verifyPermission($this->db, 'user_edit_others')) || ($uid == $_SESSION['userid'] && !self::verifyPermission($this->db, 'user_edit_own'))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete this user."));

            return false;
        } else {
            $dnsRecord = new DnsRecord($this->db, $this->config);
            foreach ($zones as $zone) {
                if ($zone ['target'] == "delete") {
                    $dnsRecord->deleteDomain($zone ['zid']);
                } elseif ($zone ['target'] == "new_owner") {
                    DnsRecord::addOwnerToZone($this->db, $zone ['zid'], $zone ['newowner']);
                }
            }

            $stmt = $this->db->prepare("DELETE FROM zones WHERE owner = :uid");
            $stmt->execute([':uid' => $uid]);

            // Clean up external authentication links
            $stmt = $this->db->prepare("DELETE FROM oidc_user_links WHERE user_id = :uid");
            $stmt->execute([':uid' => $uid]);

            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :uid");
            $stmt->execute([':uid' => $uid]);

            $zoneTemplate = new ZoneTemplate($this->db, $this->config);
            $zoneTemplate->deleteZoneTemplUserId($uid);
        }
        return true;
    }

    /**
     * Delete Permission Template ID
     *
     * @param int $id Permission template ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function deletePermTempl($db, int $id): bool
    {
        $stmt = $db->prepare("SELECT id FROM users WHERE perm_templ = :id");
        $stmt->execute([':id' => $id]);
        $response = $stmt->fetchColumn();

        if ($response) {
            // Create a new MessageService instance since this is a static method
            $messageService = new MessageService();
            $messageService->addSystemError(_('This template is assigned to at least one user.'));

            return false;
        } else {
            $stmt = $db->prepare("DELETE FROM perm_templ_items WHERE templ_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $db->prepare("DELETE FROM perm_templ WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return true;
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
    public function editUser(int $id, string $user, string $fullname, string $email, string $perm_templ, string $description, int $active, string $user_password, $i_use_ldap): bool
    {
        $perm_edit_own = self::verifyPermission($this->db, 'user_edit_own');
        $perm_edit_others = self::verifyPermission($this->db, 'user_edit_others');

        if (($id == $_SESSION["userid"] && $perm_edit_own) || ($id != $_SESSION["userid"] && $perm_edit_others)) {
            $validation = new Validator($this->db, $this->config);
            if (!$validation->isValidEmail($email)) {
                $this->messageService->addSystemError(_('Enter a valid email address.'));

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

            $stmt = $this->db->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $usercheck = $stmt->fetch();

            if ($usercheck ['username'] != $user) {
                // Username of user ID in the database is different from the name
                // we have been given. User wants a change of username. Now, make
                // sure it doesn't already exist.

                $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :username");
                $stmt->execute([':username' => $user]);
                $response = $stmt->fetchColumn();
                if ($response) {
                    $this->messageService->addSystemError(_('Username exist already, please choose another one.'));

                    return false;
                }
            }

            // So, user doesn't want to change username or, if he wants, there is not
            // another user that goes by the wanted username. So, go ahead!

            $query = "UPDATE users SET username = :username, fullname = :fullname, email = :email";

            if (self::verifyPermission($this->db, 'user_edit_templ_perm')) {
                $query .= ", perm_templ = :perm_templ";
            }

            $query .= ", description = :description, active = :active, use_ldap = :use_ldap, auth_method = :auth_method";

            $edit_own_perm = self::verifyPermission($this->db, 'user_edit_own');
            $passwd_edit_others_perm = self::verifyPermission($this->db, 'user_passwd_edit_others');

            $passwordHash = null;
            if ($user_password != "" && ($edit_own_perm || $passwd_edit_others_perm)) {
                $config = ConfigurationManager::getInstance();
                $config->initialize();
                $userAuthService = new UserAuthenticationService(
                    $config->get('security', 'password_encryption'),
                    $config->get('security', 'password_cost')
                );

                $passwordHash = $i_use_ldap ? 'LDAP_USER' : $userAuthService->hashPassword($user_password);
                $query .= ", password = :password";
            }

            $query .= " WHERE id = :id";

            $stmt = $this->db->prepare($query);

            $stmt->bindValue(':username', $user, PDO::PARAM_STR);
            $stmt->bindValue(':fullname', $fullname, PDO::PARAM_STR);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            $stmt->bindValue(':active', $active, PDO::PARAM_INT);
            $stmt->bindValue(':use_ldap', $i_use_ldap ?: 0, PDO::PARAM_INT);
            $stmt->bindValue(':auth_method', $i_use_ldap ? 'ldap' : 'sql', PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            if (self::verifyPermission($this->db, 'user_edit_templ_perm')) {
                $stmt->bindValue(':perm_templ', $perm_templ, PDO::PARAM_INT);
            }

            if ($user_password != "" && ($edit_own_perm || $passwd_edit_others_perm)) {
                $stmt->bindValue(':password', $passwordHash, PDO::PARAM_STR);
            }

            $stmt->execute();
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to edit this user."));
            return false;
        }
        return true;
    }

    /**
     * Change User Password
     *
     * @param $db
     * @param int $id User ID
     * @param $user_pass
     * @return void
     */
    public static function updateUserPassword($db, int $id, $user_pass): void
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();
        $userAuthService = new UserAuthenticationService(
            $config->get('security', 'password_encryption'),
            $config->get('security', 'password_cost')
        );
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([
            ':password' => $userAuthService->hashPassword($user_pass),
            ':id' => $id
        ]);
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
    public static function getFullnameFromUserId($db, int $id): string
    {
        $stmt = $db->prepare("SELECT fullname FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r["fullname"];
    }

    /**
     * Get Full Names of owners for a Domain ID
     *
     * @param int $id Domain ID
     *
     * @return string List of owners for domain as a comma-separated string
     */
    public static function getFullnamesOwnersFromFomainId($db, int $id)
    {
        $stmt = $db->prepare("SELECT users.id, users.fullname FROM users, zones WHERE zones.domain_id = :id AND zones.owner = users.id ORDER by fullname");
        $stmt->execute([':id' => $id]);
        $response = $stmt;
        if ($response) {
            $names = array();
            while ($r = $response->fetch()) {
                $names [] = $r ['fullname'];
            }
            return implode(', ', $names);
        }
        return "";
    }

    /**
     * Verify User is Zone ID owner
     *
     * Checks if user owns the zone directly or via group membership
     *
     * @param int $zoneid Zone ID
     *
     * @return bool true if owner (directly or via group), false otherwise
     */
    public static function verifyUserIsOwnerZoneId($db, int $zoneid): bool
    {
        $userid = $_SESSION["userid"];

        // Check direct ownership
        $stmt = $db->prepare("SELECT zones.id FROM zones WHERE zones.owner = :userid AND zones.domain_id = :zoneid");
        $stmt->execute([
            ':userid' => $userid,
            ':zoneid' => $zoneid
        ]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        // Check group ownership
        $stmt = $db->prepare("
            SELECT zg.id
            FROM zones_groups zg
            INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
            WHERE ugm.user_id = :userid AND zg.domain_id = :zoneid
        ");
        $stmt->execute([
            ':userid' => $userid,
            ':zoneid' => $zoneid
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get User Details
     *
     * Gets an array of all users and their details
     *
     * @param $db
     * @param $ldap_use
     * @param int|null $specific User ID (optional)
     *
     * @return array array of user details
     */
    public static function getUserDetailList($db, $ldap_use, ?int $specific = null): array
    {
        $userid = $_SESSION['userid'];

        if ($specific) {
            $sql_add = "AND users.id = :specific";
        } elseif (self::verifyPermission($db, 'user_view_others')) {
            $sql_add = "";
        } else {
            $sql_add = "AND users.id = :userid";
        }

        $query = "SELECT users.id AS uid,
        username,
        fullname,
        email,
        description AS descr,
        active,
        auth_method,";

        if ($ldap_use) {
            $query .= "use_ldap,";
        }

        $query .= "perm_templ.id AS tpl_id,
        perm_templ.name AS tpl_name,
        perm_templ.descr AS tpl_descr
        FROM users, perm_templ
        WHERE users.perm_templ = perm_templ.id " . $sql_add . "
        ORDER BY username";

        $stmt = $db->prepare($query);

        if ($specific) {
            $stmt->bindValue(':specific', $specific, PDO::PARAM_INT);
        } elseif (!self::verifyPermission($db, 'user_view_others')) {
            $stmt->bindValue(':userid', $userid, PDO::PARAM_INT);
        }

        $stmt->execute();
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userList = array();
        foreach ($response as $user) {
            $userList[] = array(
                "uid" => $user['uid'],
                "username" => $user['username'],
                "fullname" => $user['fullname'],
                "email" => $user['email'],
                "descr" => $user['descr'],
                "active" => $user['active'],
                "use_ldap" => $user['use_ldap'] ?? 0,
                "auth_type" => $user['auth_method'] ?? 'sql',
                "tpl_id" => $user['tpl_id'],
                "tpl_name" => $user['tpl_name'],
                "tpl_descr" => $user['tpl_descr']
            );
        }
        return $userList;
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
     * @return array array of permissions [id,name,descr] or permission names [name]
     */
    public static function getPermissionsByTemplateId($db, int $templ_id = 0, bool $return_name_only = false): array
    {
        $limit = '';
        if ($templ_id > 0) {
            $query = "SELECT perm_items.id AS id,
			perm_items.name AS name,
			perm_items.descr AS descr
			FROM perm_items, perm_templ_items
			WHERE perm_templ_items.templ_id = :templ_id
			AND perm_templ_items.perm_id = perm_items.id
			ORDER BY name";
            $stmt = $db->prepare($query);
            $stmt->execute([':templ_id' => $templ_id]);
            $response = $stmt;
        } else {
            $query = "SELECT perm_items.id AS id,
			perm_items.name AS name,
			perm_items.descr AS descr
			FROM perm_items
			ORDER BY name";
            $response = $db->query($query);
        }

        $permission_list = array();
        while ($permission = $response->fetch()) {
            if (!$return_name_only) {
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
     * Update User Details
     *
     * @param array $details User details
     *
     * @return boolean true on success, false otherwise
     */
    public function updateUserDetails(array $details): bool
    {
        $perm_edit_own = self::verifyPermission($this->db, 'user_edit_own');
        $perm_edit_others = self::verifyPermission($this->db, 'user_edit_others');
        $perm_templ_perm_edit = self::verifyPermission($this->db, 'templ_perm_edit');
        $perm_is_godlike = self::verifyPermission($this->db, 'user_is_ueberuser');

        if (($details['uid'] == $_SESSION["userid"] && $perm_edit_own) || ($details['uid'] != $_SESSION["userid"] && $perm_edit_others)) {
            $validation = new Validator($this->db, $this->config);
            if (!$validation->isValidEmail($details['email'])) {
                $this->messageService->addSystemError(_('Enter a valid email address.'));

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
            $query = "SELECT username FROM users WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $details['uid'], PDO::PARAM_INT);
            $stmt->execute();
            $userCheck = $stmt->fetch();

            if ($userCheck['username'] != $details['username']) {
                // Username of user ID in the database is different from the name
                // we have been given. User wants a change of username. Now, make
                // sure it doesn't already exist.
                $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :username");
                $stmt->execute([':username' => $details['username']]);
                $response = $stmt->fetchColumn();
                if ($response) {
                    $this->messageService->addSystemError(_('Username exist already, please choose another one.'));

                    return false;
                }
            }

            // So, user doesn't want to change username or, if he wants, there is not
            // another user that goes by the wanted username. So, go ahead!
            $query = "UPDATE users SET
                username = :username,
                fullname = :fullname,
                email = :email,
                active = :active";

            // If the user is allowed to change the permission template, set it.
            if ($perm_templ_perm_edit == "1") {
                $query .= ", perm_templ = :templ_id";
            }

            // If the user is allowed to change the use_ldap flag, set it.
            if ($perm_is_godlike == "1") {
                $query .= ", use_ldap = :use_ldap, auth_method = :auth_method";
            }

            $passwd_edit_others_perm = self::verifyPermission($this->db, 'user_passwd_edit_others');
            $hashedPassword = null;
            if (isset($details['password']) && $details['password'] != "" && $passwd_edit_others_perm) {
                $config = ConfigurationManager::getInstance();
                $config->initialize();
                $userAuthService = new UserAuthenticationService(
                    $config->get('security', 'password_encryption'),
                    $config->get('security', 'password_cost')
                );
                $hashedPassword = $userAuthService->hashPassword($details['password']);
                $query .= ", password = :password";
            }

            $query .= " WHERE id = :uid";

            $stmt = $this->db->prepare($query);

            $stmt->bindValue(':username', $details['username'], PDO::PARAM_STR);
            $stmt->bindValue(':fullname', $details['fullname'], PDO::PARAM_STR);
            $stmt->bindValue(':email', $details['email'], PDO::PARAM_STR);
            $stmt->bindValue(':active', $active, PDO::PARAM_INT);

            if ($perm_templ_perm_edit == "1") {
                $stmt->bindValue(':templ_id', $details['templ_id'], PDO::PARAM_INT);
            }
            if ($perm_is_godlike == "1") {
                $stmt->bindValue(':use_ldap', $use_ldap, PDO::PARAM_INT);
                $stmt->bindValue(':auth_method', $use_ldap ? 'ldap' : 'sql', PDO::PARAM_STR);
            }
            if (isset($details['password']) && $details['password'] != "" && $passwd_edit_others_perm) {
                $stmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
            }

            $stmt->bindValue(':uid', $details['uid'], PDO::PARAM_INT);

            $stmt->execute();
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to edit this user."));

            return false;
        }
        return true;
    }

    /**
     * Add a new user
     *
     * @param array $details Array of User details
     *
     * @return boolean true on success, false otherwise
     */
    public function addNewUser(array $details): bool
    {
        $ldap_use = $this->config->get('ldap', 'enabled');
        $validation = new Validator($this->db, $this->config);

        if (!self::verifyPermission($this->db, 'user_add_new')) {
            $this->messageService->addSystemError(_("You do not have the permission to add a new user."));

            return false;
        } elseif (self::userExists($this->db, $details['username'])) {
            $this->messageService->addSystemError(_('Username exist already, please choose another one.'));

            return false;
        } elseif ($details['username'] === '') {
            $this->messageService->addSystemError(_('Enter a valid user name.'));

            return false;
        } elseif (!$validation->isValidEmail($details['email'])) {
            $this->messageService->addSystemError(_('Enter a valid email address.'));

            return false;
        }

        // Set active status (defaults to 0 if not set)
        $active = isset($details['active']) && $details['active'] == 1 ? 1 : 0;

        if ($ldap_use && isset($details['use_ldap']) && $details['use_ldap'] == 1) {
            $use_ldap = 1;
            $auth_method = 'ldap';
            $password_hash = 'LDAP_USER';
        } else {
            $use_ldap = 0;
            $auth_method = 'sql';
            $config = ConfigurationManager::getInstance();
            $config->initialize();
            $userAuthService = new UserAuthenticationService(
                $config->get('security', 'password_encryption'),
                $config->get('security', 'password_cost')
            );
            $password_hash = $userAuthService->hashPassword($details['password']);
        }

        $query = "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) VALUES (:username, :password, :fullname, :email, :description, :perm_templ, :active, :use_ldap, :auth_method)";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':username', $details['username']);
        $stmt->bindValue(':password', $password_hash);
        $stmt->bindValue(':fullname', $details['fullname'] ?? '');
        $stmt->bindValue(':email', $details['email']);
        $stmt->bindValue(':description', $details['descr'] ?? '');

        if (self::verifyPermission($this->db, 'user_edit_templ_perm')) {
            $stmt->bindValue(':perm_templ', $details['perm_templ'], PDO::PARAM_INT);
        } else {
            $current_user = self::getUserDetailList($this->db, $ldap_use, $_SESSION['userid']);
            $stmt->bindValue(':perm_templ', $current_user[0]['tpl_id'], PDO::PARAM_INT);
        }

        $stmt->bindValue(':active', $active, PDO::PARAM_INT);
        $stmt->bindValue(':use_ldap', $use_ldap, PDO::PARAM_INT);
        $stmt->bindValue(':auth_method', $auth_method);
        $stmt->execute();

        return true;
    }

    /**
     * Check if user can perform a specific action on a zone using hybrid permissions
     *
     * This method validates both ownership (direct or via group) AND that the user's
     * permission template (or group's template) grants the required permission.
     *
     * @param PDO $db Database connection
     * @param int $userId User ID
     * @param int $domainId Domain/Zone ID
     * @param string $permissionName Permission name (e.g., 'zone_content_edit_own')
     * @return bool True if user has the permission for this zone
     */
    public static function canUserPerformZoneAction($db, int $userId, int $domainId, string $permissionName): bool
    {
        // Check if user is überuser - they have all permissions
        if (self::isUserSuperuser($db, $userId)) {
            return true;
        }

        // Use HybridPermissionService for granular permission checking
        static $hybridPermissionService = null;
        if ($hybridPermissionService === null) {
            $groupRepository = new DbUserGroupRepository($db);
            $memberRepository = new DbUserGroupMemberRepository($db);
            $zoneGroupRepository = new DbZoneGroupRepository($db);

            $hybridPermissionService = new HybridPermissionService(
                $db,
                $groupRepository,
                $memberRepository,
                $zoneGroupRepository
            );
        }

        return $hybridPermissionService->canUserPerformAction($userId, $domainId, $permissionName);
    }

    /**
     * Get all permissions a user has for a specific zone
     *
     * Returns an array with permissions from all sources (direct ownership + group memberships).
     * Useful for debugging and displaying effective permissions in the UI.
     *
     * @param PDO $db Database connection
     * @param int $userId User ID
     * @param int $domainId Domain/Zone ID
     * @return array{permissions: string[], sources: array} Permissions and their sources
     */
    public static function getUserZonePermissions($db, int $userId, int $domainId): array
    {
        // Check if user is überuser
        if (self::isUserSuperuser($db, $userId)) {
            return [
                'permissions' => ['user_is_ueberuser'], // All permissions implied
                'sources' => [
                    [
                        'type' => 'überuser',
                        'id' => $userId,
                        'permissions' => ['user_is_ueberuser']
                    ]
                ]
            ];
        }

        // Use HybridPermissionService
        static $hybridPermissionService = null;
        if ($hybridPermissionService === null) {
            $groupRepository = new DbUserGroupRepository($db);
            $memberRepository = new DbUserGroupMemberRepository($db);
            $zoneGroupRepository = new DbZoneGroupRepository($db);

            $hybridPermissionService = new HybridPermissionService(
                $db,
                $groupRepository,
                $memberRepository,
                $zoneGroupRepository
            );
        }

        return $hybridPermissionService->getUserPermissionsForZone($userId, $domainId);
    }
}
