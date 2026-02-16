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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\UserId;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class DbUserRepository implements UserRepository
{
    private object $db;
    private ConfigurationManager $config;

    public function __construct($db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function canViewOthersContent(UserId $user): bool
    {
        $query = "SELECT DISTINCT u.id
                  FROM users u
                  JOIN perm_templ pt ON u.perm_templ = pt.id
                  JOIN perm_templ_items pti ON pti.templ_id = pt.id
                  JOIN (SELECT id FROM perm_items WHERE name IN ('zone_content_view_others', 'user_is_ueberuser')) pit ON pti.perm_id = pit.id
                  WHERE u.id = :userId";

        $stmt = $this->db->prepare($query);
        $stmt->execute(['userId' => $user->getId()]);

        return (bool)$stmt->fetchColumn();
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare('SELECT id, password, use_ldap FROM users WHERE username = ?');
        $stmt->execute([$username]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            return null;
        }

        return new User($data['id'], $data['password'], (bool)$data['use_ldap']);
    }

    public function updatePassword(int $userId, string $hashedPassword): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        return $stmt->execute([$hashedPassword, $userId]);
    }

    /**
     * Get user by ID
     *
     * @param int $userId The user ID to fetch
     * @return array|null User data or null if not found
     */
    public function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, fullname, email, description, active, perm_templ FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        return $userData ?: null;
    }

    /**
     * Get all permissions for a specific user
     *
     * @param int $userId User ID to get permissions for
     * @return array Array of permission names
     */
    public function getUserPermissions(int $userId): array
    {
        // First get the user to check if they exist
        $user = $this->getUserById($userId);
        if (!$user) {
            return [];
        }

        // Query to get all permissions for the user from both direct template and groups
        // UNION automatically removes duplicates if same permission exists in both sources
        // Using positional parameters for UNION queries to avoid PDO binding issues
        $query = "
            SELECT perm_items.name AS permission
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            INNER JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            INNER JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = ?
                AND perm_items.name IS NOT NULL

            UNION

            SELECT pi.name AS permission
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE ugm.user_id = ?
                AND pi.name IS NOT NULL

            ORDER BY permission
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId, $userId]);

        $userPermissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['permission'] !== null) {
                $userPermissions[] = $row['permission'];
            }
        }

        return $userPermissions;
    }

    /**
     * Check if a user has admin permissions
     *
     * @param int $userId User ID to check
     * @return bool True if the user is an admin
     */
    public function hasAdminPermission(int $userId): bool
    {
        // Check both direct user permissions and group permissions
        // Uses same logic as UserManager::isUserSuperuser for consistency
        // Using positional parameters for UNION queries to avoid PDO binding issues
        $query = "
            SELECT perm_items.name AS permission
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            INNER JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            INNER JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = ?
                AND perm_items.name = 'user_is_ueberuser'
                AND perm_items.name IS NOT NULL

            UNION

            SELECT pi.name AS permission
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE ugm.user_id = ?
                AND pi.name = 'user_is_ueberuser'
                AND pi.name IS NOT NULL
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId, $userId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Get a paginated list of users with zone counts
     *
     * @param int $offset Starting offset for pagination
     * @param int $limit Maximum number of users to return
     * @return array Array of user data with zone counts
     */
    public function getUsersList(int $offset, int $limit): array
    {
        $query = "SELECT users.id AS id,
            users.username AS username,
            users.fullname AS fullname,
            users.email AS email,
            users.description AS description,
            users.active AS active,
            users.perm_templ AS perm_templ,
            COUNT(zones.owner) AS zone_count 
            FROM users
            LEFT JOIN zones ON users.id = zones.owner
            GROUP BY
            users.id,
            users.username,
            users.fullname,
            users.email,
            users.description,
            users.perm_templ,
            users.active
            ORDER BY users.id
            LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'fullname' => $row['fullname'],
                'email' => $row['email'],
                'description' => $row['description'],
                'active' => $row['active'],
                'perm_templ' => $row['perm_templ'],
                'zone_count' => $row['zone_count']
            ];
        }

        return $users;
    }

    /**
     * Get total count of users in the system
     *
     * @return int Total number of users
     */
    public function getTotalUserCount(): int
    {
        $query = "SELECT COUNT(*) FROM users";
        $stmt = $this->db->query($query);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete a user by ID
     *
     * @param int $userId User ID to delete
     * @return bool True if the user was deleted successfully
     */
    public function deleteUser(int $userId): bool
    {
        try {
            // Start transaction to ensure atomicity
            $this->db->beginTransaction();

            // Delete related OIDC/SAML authentication links first
            $this->cleanupExternalAuthLinks($userId);

            // Delete user preferences
            $stmt = $this->db->prepare("DELETE FROM user_preferences WHERE user_id = :userId");
            $stmt->execute([':userId' => $userId]);

            // Delete MFA settings
            $stmt = $this->db->prepare("DELETE FROM user_mfa WHERE user_id = :userId");
            $stmt->execute([':userId' => $userId]);

            // Delete login attempts
            $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE user_id = :userId");
            $stmt->execute([':userId' => $userId]);

            // Finally delete the user
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :userId");
            $result = $stmt->execute([':userId' => $userId]);

            if ($result) {
                $this->db->commit();
                return true;
            } else {
                $this->db->rollback();
                return false;
            }
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Clean up external authentication links for a user
     *
     * This method removes all OIDC and SAML authentication links associated with a user.
     * It's called during user deletion to ensure referential integrity.
     *
     * @param int $userId User ID
     * @return void
     */
    private function cleanupExternalAuthLinks(int $userId): void
    {
        // Clean up OIDC links
        $stmt = $this->db->prepare("DELETE FROM oidc_user_links WHERE user_id = :userId");
        $stmt->execute([':userId' => $userId]);

        // Clean up SAML links if the table exists
        // Use database-agnostic approach: try the DELETE and catch table not found errors
        try {
            $stmt = $this->db->prepare("DELETE FROM saml_user_links WHERE user_id = :userId");
            $stmt->execute([':userId' => $userId]);
        } catch (\Exception $e) {
            // saml_user_links table doesn't exist yet - this is expected until SAML user linking is fully implemented
            // We silently ignore table-not-found errors but would still throw for other SQL errors
            $errorMessage = $e->getMessage();
            $isTableNotFound = (
                strpos($errorMessage, 'saml_user_links') !== false && (
                    strpos($errorMessage, "doesn't exist") !== false ||
                    strpos($errorMessage, 'does not exist') !== false ||
                    strpos($errorMessage, 'no such table') !== false ||
                    strpos($errorMessage, 'Unknown table') !== false
                )
            );

            if (!$isTableNotFound) {
                // Re-throw if it's not a table-not-found error
                throw $e;
            }
        }
    }

    /**
     * Get zones owned by a user
     *
     * @param int $userId User ID
     * @return array Array of zone data owned by the user
     */
    public function getUserZones(int $userId): array
    {
        $query = "SELECT z.id, z.domain_id
                  FROM zones z
                  WHERE z.owner = :userId";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':userId' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Transfer zone ownership from one user to another
     *
     * @param int $fromUserId Source user ID
     * @param int $toUserId Target user ID
     * @return bool True if zones were transferred successfully
     */
    public function transferUserZones(int $fromUserId, int $toUserId): bool
    {
        $stmt = $this->db->prepare("UPDATE zones SET owner = :toUserId WHERE owner = :fromUserId");
        return $stmt->execute([':toUserId' => $toUserId, ':fromUserId' => $fromUserId]);
    }

    /**
     * Unassign all zones owned by a user (not used anymore - kept for interface compatibility)
     *
     * @param int $userId User ID
     * @return bool True if zones were unassigned successfully
     * @deprecated Use transferUserZones() instead
     */
    public function unassignUserZones(int $userId): bool
    {
        // This method is deprecated - zones should be transferred to another user
        // to avoid the NOT NULL constraint issue
        return false;
    }

    /**
     * Count total number of uberusers (super admins) in the system
     *
     * @return int Number of uberusers
     */
    public function countUberusers(): int
    {
        $query = "SELECT COUNT(DISTINCT users.id)
                  FROM users
                  JOIN perm_templ ON users.perm_templ = perm_templ.id
                  JOIN perm_templ_items ON perm_templ.id = perm_templ_items.templ_id
                  JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
                  WHERE perm_items.name = 'user_is_ueberuser'
                  AND users.active = 1";

        $stmt = $this->db->query($query);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a specific user is an uberuser
     *
     * @param int $userId User ID to check
     * @return bool True if user is an uberuser
     */
    public function isUberuser(int $userId): bool
    {
        $query = "SELECT COUNT(*)
                  FROM users
                  JOIN perm_templ ON users.perm_templ = perm_templ.id
                  JOIN perm_templ_items ON perm_templ.id = perm_templ_items.templ_id
                  JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
                  WHERE perm_items.name = 'user_is_ueberuser'
                  AND users.id = :userId
                  AND users.active = 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':userId' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function createUser(array $userData): ?int
    {
        $query = "INSERT INTO users (username, password, fullname, email, description, active, perm_templ, use_ldap)
                  VALUES (:username, :password, :fullname, :email, :description, :active, :perm_templ, :use_ldap)";

        $stmt = $this->db->prepare($query);
        $result = $stmt->execute([
            ':username' => $userData['username'],
            ':password' => $userData['password'],
            ':fullname' => $userData['fullname'] ?? '',
            ':email' => $userData['email'] ?? '',
            ':description' => $userData['description'] ?? '',
            ':active' => (int)($userData['active'] ?? 1),
            ':perm_templ' => (int)($userData['perm_templ'] ?? 1),
            ':use_ldap' => (int)($userData['use_ldap'] ?? 0)
        ]);

        if ($result) {
            return (int)$this->db->lastInsertId();
        }

        return null;
    }

    public function getUserByUsername(string $username): ?array
    {
        $query = "SELECT * FROM users WHERE username = :username LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function getUserByEmail(string $email): ?array
    {
        $query = "SELECT * FROM users WHERE email = :email LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function updateUser(int $userId, array $userData): bool
    {
        // Build dynamic update query based on provided fields
        $setFields = [];
        $params = [':id' => $userId];

        $allowedFields = ['username', 'password', 'fullname', 'email', 'description', 'active', 'perm_templ', 'use_ldap'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $userData)) {
                $setFields[] = "{$field} = :{$field}";

                // Handle type casting for specific fields
                if ($field === 'active' || $field === 'perm_templ' || $field === 'use_ldap') {
                    $params[":{$field}"] = (int)$userData[$field];
                } else {
                    $params[":{$field}"] = $userData[$field];
                }
            }
        }

        if (empty($setFields)) {
            return true; // No fields to update
        }

        $query = "UPDATE users SET " . implode(', ', $setFields) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);

        return $stmt->execute($params);
    }

    /**
     * Assign permission template to a user
     *
     * @param int $userId User ID
     * @param int $permTemplId Permission template ID
     * @return bool True if assignment was successful
     */
    public function assignPermissionTemplate(int $userId, int $permTemplId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET perm_templ = :permTemplId WHERE id = :userId");
        return $stmt->execute([':permTemplId' => $permTemplId, ':userId' => $userId]);
    }

    /**
     * Check if a permission template exists
     *
     * @param int $permTemplId Permission template ID
     * @return bool True if the permission template exists
     */
    public function permissionTemplateExists(int $permTemplId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM perm_templ WHERE id = :permTemplId");
        $stmt->execute([':permTemplId' => $permTemplId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
