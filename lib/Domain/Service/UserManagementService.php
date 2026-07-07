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

namespace Poweradmin\Domain\Service;

use Exception;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Model\Pagination;

/**
 * Domain service for user management operations
 *
 * This service encapsulates business logic related to user operations
 * and coordinates between repositories and other domain services.
 */
class UserManagementService
{
    private UserRepository $userRepository;
    private PermissionService $permissionService;

    public function __construct(
        UserRepository $userRepository,
        PermissionService $permissionService
    ) {
        $this->userRepository = $userRepository;
        $this->permissionService = $permissionService;
    }

    /**
     * Get a user by ID with complete information including permissions
     *
     * @param int $userId User ID
     * @return array|null User data with permissions or null if not found
     */
    public function getUserById(int $userId): ?array
    {
        $user = $this->userRepository->getUserById($userId);

        if (!$user) {
            return null;
        }

        // Enrich user data with permissions and admin status
        $permissions = $this->permissionService->getUserPermissions($userId);
        $isAdmin = $this->permissionService->isAdmin($userId);

        return [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'email' => $user['email'] ?? '',
            'description' => $user['description'] ?? '',
            'active' => (bool)$user['active'],
            'is_admin' => $isAdmin,
            'permissions' => $permissions,
            'created_at' => $user['created_at'] ?? null,
            'updated_at' => $user['updated_at'] ?? null
        ];
    }

    /**
     * Get paginated list of users with their details and permissions
     *
     * @param Pagination $pagination Pagination parameters
     * @return array Array with 'data' and 'total_count' keys
     */
    public function getUsersList(Pagination $pagination): array
    {
        $users = $this->userRepository->getUsersList(
            $pagination->getOffset(),
            $pagination->getLimit()
        );

        $totalCount = $this->userRepository->getTotalUserCount();

        // Enrich each user with admin status and zone count
        $enrichedUsers = array_map(function ($user) {
            $isAdmin = $this->permissionService->isAdmin((int)$user['id']);

            return [
                'user_id' => (int)$user['id'],
                'username' => $user['username'],
                'fullname' => $user['fullname'] ?? '',
                'email' => $user['email'] ?? '',
                'description' => $user['description'] ?? '',
                'active' => (bool)$user['active'],
                'zone_count' => (int)($user['zone_count'] ?? 0),
                'is_admin' => $isAdmin
            ];
        }, $users);

        return [
            'data' => $enrichedUsers,
            'total_count' => $totalCount
        ];
    }

    /**
     * Check if a user exists by ID
     *
     * @param int $userId User ID
     * @return bool True if user exists, false otherwise
     */
    public function userExists(int $userId): bool
    {
        return $this->userRepository->getUserById($userId) !== null;
    }

    /**
     * Check if a user exists by username
     *
     * @param string $username Username to check
     * @return bool True if user exists, false otherwise
     */
    public function userExistsByUsername(string $username): bool
    {
        return $this->userRepository->getUserByUsername($username) !== null;
    }

    /**
     * Check if a user exists by email
     *
     * @param string $email Email to check
     * @return bool True if user exists, false otherwise
     */
    public function userExistsByEmail(string $email): bool
    {
        return $this->userRepository->getUserByEmail($email) !== null;
    }

    /**
     * Get user details by username (similar to list format)
     *
     * @param string $username Username to search for
     * @return array|null User data in list format or null if not found
     */
    public function getUserByUsername(string $username): ?array
    {
        $user = $this->userRepository->getUserByUsername($username);

        if (!$user) {
            return null;
        }

        $isAdmin = $this->permissionService->isAdmin((int)$user['id']);

        return [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'email' => $user['email'] ?? '',
            'description' => $user['description'] ?? '',
            'active' => (bool)$user['active'],
            'zone_count' => 0, // Would need additional query to get exact count
            'is_admin' => $isAdmin
        ];
    }

    /**
     * Get user details by email (similar to list format)
     *
     * @param string $email Email to search for
     * @return array|null User data in list format or null if not found
     */
    public function getUserByEmail(string $email): ?array
    {
        $user = $this->userRepository->getUserByEmail($email);

        if (!$user) {
            return null;
        }

        $isAdmin = $this->permissionService->isAdmin((int)$user['id']);

        return [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'email' => $user['email'] ?? '',
            'description' => $user['description'] ?? '',
            'active' => (bool)$user['active'],
            'zone_count' => 0, // Would need additional query to get exact count
            'is_admin' => $isAdmin
        ];
    }

    /**
     * Get basic user information for verification purposes
     *
     * @param int $userId User ID
     * @return array|null Basic user data or null if not found
     */
    public function getUserForVerification(int $userId): ?array
    {
        $user = $this->userRepository->getUserById($userId);

        if (!$user) {
            return null;
        }

        $permissions = $this->userRepository->getUserPermissions($userId);
        $isAdmin = $this->userRepository->hasAdminPermission($userId);

        return [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'is_admin' => $isAdmin,
            'permissions' => [
                'is_admin' => $isAdmin,
                'zone_creation_allowed' => in_array('zone_master_add', $permissions) || $isAdmin,
                'zone_management_allowed' => in_array('zone_content_view_others', $permissions) || $isAdmin
            ]
        ];
    }

    /**
     * Create a new user
     *
     * @param array $userData User data containing username, fullname, email, password, etc.
     * @return array Result with success status, message, and user ID if successful
     */
    public function createUser(array $userData): array
    {
        // Validate required fields
        if (empty($userData['username'])) {
            return [
                'success' => false,
                'message' => 'Username is required',
                'status' => 400
            ];
        }

        if (empty($userData['password'])) {
            return [
                'success' => false,
                'message' => 'Password is required',
                'status' => 400
            ];
        }

        // Check if username already exists
        if ($this->userRepository->getUserByUsername($userData['username'])) {
            return [
                'success' => false,
                'message' => 'Username already exists',
                'status' => 409
            ];
        }

        // Check if email already exists (if provided)
        if (!empty($userData['email']) && $this->userRepository->getUserByEmail($userData['email'])) {
            return [
                'success' => false,
                'message' => 'Email already exists',
                'status' => 409
            ];
        }

        // Reject invalid permission template ids; missing/null is allowed (repo defaults to 1).
        // Group templates are rejected to match the web UI flow.
        if (array_key_exists('perm_templ', $userData) && $userData['perm_templ'] !== null) {
            $permTemplId = $this->normalizePermTemplId($userData['perm_templ']);
            if ($permTemplId === null || !$this->permissionTemplateExists($permTemplId, 'user')) {
                return [
                    'success' => false,
                    'message' => 'Permission template not found',
                    'status' => 404
                ];
            }
            $userData['perm_templ'] = $permTemplId;
        }

        try {
            // Hash the password before storing
            $userData['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);

            $userId = $this->userRepository->createUser($userData);

            if (!$userId) {
                return [
                    'success' => false,
                    'message' => 'Failed to create user',
                    'status' => 500
                ];
            }

            return [
                'success' => true,
                'message' => 'User created successfully',
                'user_id' => $userId
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create user: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Update an existing user
     *
     * @param int $userId User ID to update
     * @param array $userData Updated user data
     * @return array Result with success status and message
     */
    public function updateUser(int $userId, array $userData): array
    {
        // Check if user exists
        if (!$this->userExists($userId)) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404
            ];
        }

        // Check if username already exists (exclude current user)
        if (!empty($userData['username'])) {
            $existingUser = $this->userRepository->getUserByUsername($userData['username']);
            if ($existingUser && (int)$existingUser['id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Username already exists',
                    'status' => 409
                ];
            }
        }

        // Check if email already exists (exclude current user)
        if (!empty($userData['email'])) {
            $existingUser = $this->userRepository->getUserByEmail($userData['email']);
            if ($existingUser && (int)$existingUser['id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Email already exists',
                    'status' => 409
                ];
            }
        }

        // Reject invalid permission template ids; on update path null also fails (no repo default).
        // Group templates are rejected to match the web UI flow.
        if (array_key_exists('perm_templ', $userData)) {
            $permTemplId = $userData['perm_templ'] === null
                ? null
                : $this->normalizePermTemplId($userData['perm_templ']);
            if ($permTemplId === null || !$this->permissionTemplateExists($permTemplId, 'user')) {
                return [
                    'success' => false,
                    'message' => 'Permission template not found',
                    'status' => 404
                ];
            }
            $userData['perm_templ'] = $permTemplId;
        }

        // Check if trying to disable the last remaining uberuser
        if (array_key_exists('active', $userData) && !$userData['active']) {
            if ($this->userRepository->isUberuser($userId)) {
                $uberuserCount = $this->userRepository->countUberusers();
                if ($uberuserCount <= 1) {
                    return [
                        'success' => false,
                        'message' => 'Cannot disable the last remaining super admin user. At least one active super admin must exist in the system.',
                        'status' => 409
                    ];
                }
            }
        }

        try {
            // Check if attempting to set password for external auth user
            if (!empty($userData['password'])) {
                $user = $this->userRepository->getUserById($userId);
                $authMethod = $user['auth_method'] ?? 'sql';
                $externalAuthMethods = ['oidc', 'saml', 'ldap'];

                if (in_array($authMethod, $externalAuthMethods, true)) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            'Cannot set password for %s authenticated users. This user authenticates via %s.',
                            strtoupper($authMethod),
                            strtoupper($authMethod)
                        ),
                        'status' => 400
                    ];
                }

                // Hash password if allowed
                $userData['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }

            $success = $this->userRepository->updateUser($userId, $userData);

            if (!$success) {
                return [
                    'success' => false,
                    'message' => 'Failed to update user',
                    'status' => 500
                ];
            }

            return [
                'success' => true,
                'message' => 'User updated successfully',
                'user_id' => $userId
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update user: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete a user and transfer their zones to another user
     *
     * @param int $userId User ID to delete
     * @param int|null $transferToUserId User ID to transfer zones to (required if user has zones)
     * @return array Result with success status and message
     */
    public function deleteUser(int $userId, ?int $transferToUserId = null): array
    {
        // Check if user exists
        if (!$this->userExists($userId)) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404
            ];
        }

        // Check if this is the last uberuser - prevent deletion to avoid system lockout
        if ($this->userRepository->isUberuser($userId)) {
            $uberuserCount = $this->userRepository->countUberusers();
            if ($uberuserCount <= 1) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete the last remaining super admin user. At least one super admin must exist in the system.',
                    'status' => 409
                ];
            }
        }

        // Get user's zones
        $userZones = $this->userRepository->getUserZones($userId);
        $zoneCount = count($userZones);

        try {
            // Handle zones owned by the user
            if ($zoneCount > 0) {
                if (!$transferToUserId) {
                    return [
                        'success' => false,
                        'message' => 'User owns zones. Please specify transfer_to_user_id to transfer zones to another user.',
                        'status' => 400
                    ];
                }

                // Check if transfer target user exists
                if (!$this->userExists($transferToUserId)) {
                    return [
                        'success' => false,
                        'message' => 'Transfer target user not found',
                        'status' => 404
                    ];
                }

                // Transfer zones to the specified user
                if (!$this->userRepository->transferUserZones($userId, $transferToUserId)) {
                    return [
                        'success' => false,
                        'message' => 'Failed to transfer zones to target user',
                        'status' => 500
                    ];
                }
            }

            // Delete the user
            if (!$this->userRepository->deleteUser($userId)) {
                return [
                    'success' => false,
                    'message' => 'Failed to delete user',
                    'status' => 500
                ];
            }

            $message = $zoneCount > 0
                ? "User deleted successfully. {$zoneCount} zones transferred"
                : 'User deleted successfully';

            return [
                'success' => true,
                'message' => $message,
                'zones_affected' => $zoneCount
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Assign permission template to a user
     *
     * @param int $userId User ID
     * @param int $permTemplId Permission template ID
     * @return array Result with success status and message
     */
    public function assignPermissionTemplate(int $userId, int $permTemplId): array
    {
        // Check if user exists
        if (!$this->userExists($userId)) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404
            ];
        }

        // Reject group-type templates for users, matching createUser/updateUser.
        if (!$this->permissionTemplateExists($permTemplId, 'user')) {
            return [
                'success' => false,
                'message' => 'Permission template not found',
                'status' => 404
            ];
        }

        try {
            // Assign permission template
            if (!$this->userRepository->assignPermissionTemplate($userId, $permTemplId)) {
                return [
                    'success' => false,
                    'message' => 'Failed to assign permission template',
                    'status' => 500
                ];
            }

            return [
                'success' => true,
                'message' => 'Permission template assigned successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to assign permission template: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Check if a permission template exists, optionally restricted to a template type
     *
     * @param int $permTemplId Permission template ID
     * @param string|null $templateType Optional template_type filter ('user' or 'group')
     * @return bool True if the permission template exists (and matches type when set)
     */
    private function permissionTemplateExists(int $permTemplId, ?string $templateType = null): bool
    {
        return $this->userRepository->permissionTemplateExists($permTemplId, $templateType);
    }

    /**
     * Coerce an API-supplied perm_templ to a positive int, or null if invalid.
     * Accepts ints and numeric strings; rejects malformed strings like "2foo"
     * (which (int) would silently truncate to 2).
     *
     * @param mixed $value
     * @return int|null
     */
    private function normalizePermTemplId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $intValue = (int)$value;
            return $intValue > 0 ? $intValue : null;
        }
        return null;
    }
}
