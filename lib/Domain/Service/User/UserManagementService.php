<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Domain\Service\User;

use Exception;
use Poweradmin\Domain\Port\PasswordHasherInterface;
use Poweradmin\Domain\Port\PasswordPolicyInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Domain service for user management operations
 *
 * This service encapsulates business logic related to user operations
 * and coordinates between repositories and other domain services.
 */
class UserManagementService
{
    public const ERR_USERNAME_REQUIRED = 'username_required';
    public const ERR_INVALID_LDAP = 'invalid_ldap';
    public const ERR_PASSWORD_REQUIRED = 'password_required';
    public const ERR_PASSWORD_POLICY = 'password_policy';
    public const ERR_FIELD_LENGTH = 'field_length';
    public const ERR_USERNAME_EXISTS = 'username_exists';
    public const ERR_EMAIL_EXISTS = 'email_exists';
    public const ERR_NO_TEMPLATE = 'no_template';
    public const ERR_TEMPLATE_NOT_FOUND = 'template_not_found';
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_PASSWORD_FORBIDDEN = 'password_forbidden';
    public const ERR_LAST_ADMIN = 'last_admin';
    public const ERR_TRANSFER_TARGET = 'transfer_target';
    public const ERR_ZONE_DELETE_FORBIDDEN = 'zone_delete_forbidden';
    public const ERR_ZONE_META_FORBIDDEN = 'zone_meta_forbidden';
    public const ERR_ZONE_WRITE = 'zone_write';
    public const ERR_WRITE = 'write';

    private UserRepositoryInterface $userRepository;
    private PermissionService $permissions;
    private UserProfileAssembler $profileAssembler;
    private PasswordHasherInterface $authService;
    private PasswordPolicyInterface $passwordPolicy;
    private bool $ldapEnabled;
    private DomainManagerInterface $domainManager;
    private ZoneManagementService $zones;

    public function __construct(
        UserRepositoryInterface $userRepository,
        PermissionService $permissionService,
        UserProfileAssembler $profileAssembler,
        PasswordHasherInterface $authService,
        PasswordPolicyInterface $passwordPolicy,
        bool $ldapEnabled,
        DomainManagerInterface $domainManager,
        ZoneManagementService $zones
    ) {
        $this->userRepository = $userRepository;
        $this->permissions = $permissionService;
        $this->profileAssembler = $profileAssembler;
        $this->authService = $authService;
        $this->passwordPolicy = $passwordPolicy;
        $this->ldapEnabled = $ldapEnabled;
        $this->domainManager = $domainManager;
        $this->zones = $zones;
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

        return $user ? $this->profileAssembler->assembleDetail($user) : null;
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

        return [
            'data' => $this->profileAssembler->assembleList($users),
            'total_count' => $this->userRepository->getTotalUserCount()
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

        return $this->profileAssembler->assembleLookup($user);
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

        return $this->profileAssembler->assembleLookup($user);
    }

    /**
     * Create a new user
     *
     * @return array Result with success status, message, and user ID if successful
     */
    public function createUser(CreateUserCommand $command): array
    {
        if ($command->username === '') {
            return [
                'success' => false,
                'message' => 'Username is required',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_USERNAME_REQUIRED,
            ];
        }

        if (($ldapError = $this->useLdapError($command->useLdap)) !== null) {
            return $ldapError;
        }
        $useLdap = $command->useLdap;

        if (!$useLdap && !$command->passwordGiven()) {
            return [
                'success' => false,
                'message' => 'Password is required',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_PASSWORD_REQUIRED,
            ];
        }

        if (!$useLdap && ($policyError = $this->passwordPolicyError((string)$command->password)) !== null) {
            return $policyError;
        }

        $lengthError = $this->validateFieldLengths($command->username, $command->fullname, $command->email, $command->description);
        if ($lengthError !== null) {
            return $lengthError;
        }

        // Check if username already exists
        if ($this->userRepository->getUserByUsername($command->username)) {
            return [
                'success' => false,
                'message' => 'Username already exists',
                'refusal' => Refusal::CONFLICT,
                'code' => self::ERR_USERNAME_EXISTS,
            ];
        }

        // Check if email already exists (if provided)
        if ($command->email !== '' && $this->userRepository->getUserByEmail($command->email)) {
            return [
                'success' => false,
                'message' => 'Email already exists',
                'refusal' => Refusal::CONFLICT,
                'code' => self::ERR_EMAIL_EXISTS,
            ];
        }

        // A template must be resolved by now; without one the row would inherit
        // Administrator. Group templates are rejected to match the web UI flow.
        if ($command->permissionTemplateId === null) {
            return [
                'success' => false,
                'message' => 'No permission template available to assign',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_NO_TEMPLATE,
            ];
        }

        if (!$this->permissionTemplateExists($command->permissionTemplateId, 'user')) {
            return $this->templateNotFound();
        }

        try {
            $stored = $command->withPassword($useLdap
                ? AuthMethod::LDAP_PASSWORD_PLACEHOLDER
                : $this->authService->hashPassword((string)$command->password));

            $userId = $this->userRepository->createUser($stored);

            if (!$userId) {
                return [
                    'success' => false,
                    'message' => 'Failed to create user',
                    'refusal' => Refusal::BACKEND_FAILURE,
                    'code' => self::ERR_WRITE,
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
                'refusal' => Refusal::BACKEND_FAILURE,
                'code' => self::ERR_WRITE,
            ];
        }
    }

    /**
     * Update an existing user
     *
     * @param int $userId User ID to update
     * @return array Result with success status and message
     */
    public function updateUser(int $userId, UpdateUserCommand $command): array
    {
        $user = $this->userRepository->getUserById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'message' => 'User not found',
                'refusal' => Refusal::NOT_FOUND,
                'code' => self::ERR_NOT_FOUND,
            ];
        }

        $lengthError = $this->validateFieldLengths($command->username, $command->fullname, $command->email, $command->description);
        if ($lengthError !== null) {
            return $lengthError;
        }

        if (($emptyError = $this->validateFieldsNotEmpty($command->username)) !== null) {
            return $emptyError;
        }

        if (($ldapError = $this->useLdapError($command->useLdap)) !== null) {
            return $ldapError;
        }

        // Judge by the method the repository will persist, so switching an LDAP
        // account back to SQL in the same request may (and must) set a password.
        $storedMethod = AuthMethod::fromDb($user['auth_method'] ?? null);
        $targetMethod = $command->useLdap !== null
            ? AuthMethod::resolve($command->useLdap, $user['auth_method'] ?? null)
            : $storedMethod;
        $passwordGiven = $command->passwordGiven();

        if ($passwordGiven && $targetMethod->isExternal()) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Cannot set password for %s authenticated users. This user authenticates via %s.',
                    strtoupper($targetMethod->value),
                    strtoupper($targetMethod->value)
                ),
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_PASSWORD_FORBIDDEN,
            ];
        }

        if ($targetMethod === AuthMethod::LDAP && $storedMethod !== AuthMethod::LDAP) {
            $command = $command->withPassword(AuthMethod::LDAP_PASSWORD_PLACEHOLDER);
        }

        if ($storedMethod === AuthMethod::LDAP && $targetMethod === AuthMethod::SQL && !$passwordGiven) {
            return [
                'success' => false,
                'message' => 'Password is required when disabling LDAP authentication',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_PASSWORD_REQUIRED,
            ];
        }

        if ($passwordGiven && ($policyError = $this->passwordPolicyError((string)$command->password)) !== null) {
            return $policyError;
        }

        // A changed username must be free; an unchanged one is not looked up, since
        // legacy data may hold it twice and the lookup could land on the other row.
        if ($command->username !== null && $command->username !== (string)($user['username'] ?? '')) {
            $existingUser = $this->userRepository->getUserByUsername($command->username);
            if ($existingUser && (int)$existingUser['id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Username already exists',
                    'refusal' => Refusal::CONFLICT,
                    'code' => self::ERR_USERNAME_EXISTS,
                ];
            }
        }

        // A changed email must be free; an unchanged one stays editable even where
        // older data already holds duplicates.
        if ($command->email !== null && $command->email !== '' && strcasecmp($command->email, (string)($user['email'] ?? '')) !== 0) {
            $existingUser = $this->userRepository->getUserByEmail($command->email);
            if ($existingUser && (int)$existingUser['id'] !== $userId) {
                return [
                    'success' => false,
                    'message' => 'Email already exists',
                    'refusal' => Refusal::CONFLICT,
                    'code' => self::ERR_EMAIL_EXISTS,
                ];
            }
        }

        // Group templates are rejected to match the web UI flow.
        if ($command->permissionTemplateId !== null && !$this->permissionTemplateExists($command->permissionTemplateId, 'user')) {
            return $this->templateNotFound();
        }

        // Check if trying to disable the last remaining uberuser
        if ($command->active === false && $this->userRepository->isLastUberuser($userId)) {
            return [
                'success' => false,
                'message' => 'Cannot disable the last remaining super admin user. At least one active super admin must exist in the system.',
                'refusal' => Refusal::CONFLICT,
                'code' => self::ERR_LAST_ADMIN,
            ];
        }

        try {
            if ($passwordGiven) {
                $command = $command->withPassword($this->authService->hashPassword((string)$command->password));
            }

            $success = $this->userRepository->updateUser($userId, $command);

            if (!$success) {
                return [
                    'success' => false,
                    'message' => 'Failed to update user',
                    'refusal' => Refusal::BACKEND_FAILURE,
                    'code' => self::ERR_WRITE,
                ];
            }

            if ($command->permissionTemplateId !== null) {
                $this->permissions->forgetUser($userId);
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
                'refusal' => Refusal::BACKEND_FAILURE,
                'code' => self::ERR_WRITE,
            ];
        }
    }

    /**
     * Delete a user and transfer their zones to another user
     *
     * @param int $userId User ID to delete
     * @param int|null $transferToUserId User ID to transfer zones to (required if user has zones)
     * @param int|null $actingUserId When given, the transfer needs meta-edit rights on every zone, as the web form requires
     * @return array Result with success status and message
     */
    public function deleteUser(int $userId, ?int $transferToUserId = null, ?int $actingUserId = null): array
    {
        if (($refusal = $this->deleteRefusal($userId)) !== null) {
            return $refusal;
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
                        'refusal' => Refusal::INVALID_INPUT,
                        'code' => self::ERR_TRANSFER_TARGET,
                    ];
                }

                // The target must differ from the user being deleted, otherwise the
                // zones are transferred to an account that is deleted moments later
                // and left orphaned (there is no FK from zones.owner to users.id).
                if ($transferToUserId === $userId) {
                    return [
                        'success' => false,
                        'message' => 'Cannot transfer zones to the user being deleted. Specify a different transfer_to_user_id.',
                        'refusal' => Refusal::INVALID_INPUT,
                        'code' => self::ERR_TRANSFER_TARGET,
                    ];
                }

                // Check if transfer target user exists
                if (!$this->userExists($transferToUserId)) {
                    return [
                        'success' => false,
                        'message' => 'Transfer target user not found',
                        'refusal' => Refusal::NOT_FOUND,
                        'code' => self::ERR_TRANSFER_TARGET,
                    ];
                }

                // Giving away a zone is a meta edit; a user manager without that right must not gain zones this way.
                if ($actingUserId !== null && $this->permissions->getZoneMetaEditPermissionLevel($actingUserId) !== 'all') {
                    foreach ($userZones as $zone) {
                        if (!$this->permissions->canEditZoneMeta($actingUserId, (int)$zone['domain_id'])) {
                            return [
                                'success' => false,
                                'message' => 'You do not have permission to reassign zone ' . (int)$zone['domain_id'],
                                'refusal' => Refusal::FORBIDDEN,
                                'code' => self::ERR_ZONE_META_FORBIDDEN,
                            ];
                        }
                    }
                }

                // Transfer zones to the specified user
                if (!$this->userRepository->transferUserZones($userId, $transferToUserId)) {
                    return [
                        'success' => false,
                        'message' => 'Failed to transfer zones to target user',
                        'refusal' => Refusal::BACKEND_FAILURE,
                        'code' => self::ERR_ZONE_WRITE,
                    ];
                }
                $this->permissions->forgetUser($transferToUserId);
            }

            // Delete the user
            if (!$this->userRepository->deleteUser($userId)) {
                return [
                    'success' => false,
                    'message' => 'Failed to delete user',
                    'refusal' => Refusal::BACKEND_FAILURE,
                    'code' => self::ERR_WRITE,
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
                'refusal' => Refusal::BACKEND_FAILURE,
                'code' => self::ERR_WRITE,
            ];
        }
    }

    /**
     * Delete a user, deciding zone by zone what happens to what they own: each
     * decision names a zone id and a target, 'delete' or 'new_owner' (with
     * 'newowner'); any other target leaves the zone as it is. Every decision is
     * checked against the acting user's zone rights before any zone is touched,
     * so a later refusal cannot leave the earlier ones half applied. That the
     * acting user may delete this user at all is the caller's check.
     *
     * @param list<mixed> $zoneDecisions Entries that are not a decision (no zid, unknown target) are ignored
     * @return array{success: true, message: string, zones_affected: int}|array{success: false, message: string, refusal: Refusal, code: string}
     */
    public function deleteUserWithZoneDecisions(int $actingUserId, int $userId, array $zoneDecisions): array
    {
        if (($refusal = $this->deleteRefusal($userId)) !== null) {
            return $refusal;
        }

        $zoneDecisions = array_values(array_filter(
            $zoneDecisions,
            fn(mixed $decision): bool => is_array($decision) && isset($decision['zid']) && in_array($decision['target'] ?? null, ['delete', 'new_owner'], true)
        ));
        foreach ($zoneDecisions as $decision) {
            $zoneId = (int)$decision['zid'];
            if ($decision['target'] === 'delete' && !$this->permissions->canDeleteZoneById($actingUserId, $zoneId)) {
                return ['success' => false, 'message' => 'You do not have permission to delete zone ' . $zoneId, 'refusal' => Refusal::FORBIDDEN, 'code' => self::ERR_ZONE_DELETE_FORBIDDEN];
            }
            if ($decision['target'] === 'new_owner' && !$this->permissions->canEditZoneMeta($actingUserId, $zoneId)) {
                return ['success' => false, 'message' => 'You do not have permission to reassign zone ' . $zoneId, 'refusal' => Refusal::FORBIDDEN, 'code' => self::ERR_ZONE_META_FORBIDDEN];
            }
        }

        foreach ($zoneDecisions as $decision) {
            $zoneId = (int)$decision['zid'];
            if ($decision['target'] === 'delete') {
                // Permission was checked above; the zone service deletes keys, comments,
                // records and metadata with the zone, as the web and API deletes do
                $deleted = $this->zones->deleteZone($zoneId);
                if (!$deleted['success']) {
                    return ['success' => false, 'message' => $deleted['message'], 'refusal' => $deleted['refusal'] ?? Refusal::BACKEND_FAILURE, 'code' => self::ERR_ZONE_WRITE];
                }
                continue;
            }
            $result = $this->domainManager->addOwnerToZone($zoneId, (int)($decision['newowner'] ?? 0));
            if (!$result->success) {
                return ['success' => false, 'message' => (string)$result->message, 'refusal' => $result->refusal ?? Refusal::BACKEND_FAILURE, 'code' => self::ERR_ZONE_WRITE];
            }
            $this->permissions->forgetZone($zoneId);
        }

        // Row cleanup (auth links, preferences, MFA, memberships, templates) is shared with the API.
        if (!$this->userRepository->deleteUser($userId)) {
            return ['success' => false, 'message' => 'Failed to delete user', 'refusal' => Refusal::BACKEND_FAILURE, 'code' => self::ERR_WRITE];
        }

        return ['success' => true, 'message' => 'User deleted successfully', 'zones_affected' => count($zoneDecisions)];
    }

    /**
     * Why the user cannot be deleted at all, or null: unknown, or the last super admin.
     *
     * @return array{success: false, message: string, refusal: Refusal, code: string}|null
     */
    private function deleteRefusal(int $userId): ?array
    {
        if (!$this->userExists($userId)) {
            return ['success' => false, 'message' => 'User not found', 'refusal' => Refusal::NOT_FOUND, 'code' => self::ERR_NOT_FOUND];
        }

        // The last super admin cannot go, or nobody could administer the system.
        if ($this->userRepository->isLastUberuser($userId)) {
            return [
                'success' => false,
                'message' => 'Cannot delete the last remaining super admin user. At least one super admin must exist in the system.',
                'refusal' => Refusal::CONFLICT,
                'code' => self::ERR_LAST_ADMIN,
            ];
        }

        return null;
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
                'refusal' => Refusal::NOT_FOUND
            ];
        }

        // Reject group-type templates for users, matching createUser/updateUser.
        if (!$this->permissionTemplateExists($permTemplId, 'user')) {
            return [
                'success' => false,
                'message' => 'Permission template not found',
                'refusal' => Refusal::INVALID_INPUT
            ];
        }

        // Guard against demoting the last active super admin: if this user is the
        // only remaining ueberuser and the new template drops that permission, the
        // system would be left with zero super admins.
        if (
            $this->userRepository->isLastUberuser($userId)
            && !$this->userRepository->templateGrantsUberuser($permTemplId)
        ) {
            return [
                'success' => false,
                'message' => 'Cannot remove super admin from the last remaining super admin user. At least one active super admin must exist in the system.',
                'refusal' => Refusal::CONFLICT
            ];
        }

        try {
            // Assign permission template
            if (!$this->userRepository->assignPermissionTemplate($userId, $permTemplId)) {
                return [
                    'success' => false,
                    'message' => 'Failed to assign permission template',
                    'refusal' => Refusal::BACKEND_FAILURE
                ];
            }
            $this->permissions->forgetUser($userId);

            return [
                'success' => true,
                'message' => 'Permission template assigned successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to assign permission template: ' . $e->getMessage(),
                'refusal' => Refusal::BACKEND_FAILURE
            ];
        }
    }

    /** The error for a request that asks for LDAP while it is disabled, or null. */
    private function useLdapError(?bool $useLdap): ?array
    {
        if ($useLdap === true && !$this->ldapEnabled) {
            return [
                'success' => false,
                'message' => 'LDAP authentication is not enabled',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_INVALID_LDAP,
            ];
        }

        return null;
    }

    private function templateNotFound(): array
    {
        return [
            'success' => false,
            'message' => 'Permission template not found',
            'refusal' => Refusal::INVALID_INPUT,
            'code' => self::ERR_TEMPLATE_NOT_FOUND,
        ];
    }

    /** First policy violation as a 400 result, or null when the password passes. */
    private function passwordPolicyError(#[\SensitiveParameter] string $password): ?array
    {
        $errors = $this->passwordPolicy->validatePassword($password);
        if ($errors === []) {
            return null;
        }

        return [
            'success' => false,
            'message' => $errors[0],
            'refusal' => Refusal::INVALID_INPUT,
            'code' => self::ERR_PASSWORD_POLICY,
        ];
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
     * Reject field values longer than their database column so an over-long value
     * returns a clear 400 instead of surfacing as a database truncation 500.
     * Returns a service error array on the first offending field, or null.
     */
    private function validateFieldLengths(?string $username, ?string $fullname, ?string $email, ?string $description): ?array
    {
        $limits = ['username' => [$username, 64], 'fullname' => [$fullname, 255], 'email' => [$email, 255], 'description' => [$description, 1024]];
        foreach ($limits as $field => [$value, $max]) {
            if ($value !== null && mb_strlen($value) > $max) {
                return [
                    'success' => false,
                    'message' => ucfirst($field) . " must not exceed $max characters",
                    'refusal' => Refusal::INVALID_INPUT,
                    'code' => self::ERR_FIELD_LENGTH,
                ];
            }
        }
        return null;
    }

    /**
     * Reject an empty username. The uniqueness check skips empty input, so writing one
     * through would leave an account that can never authenticate.
     * Email is deliberately not checked: IdP-managed accounts legitimately carry an
     * empty address, and a client echoing that value back must not be rejected.
     * An empty password means "leave unchanged" and is filtered by the repository.
     */
    private function validateFieldsNotEmpty(?string $username): ?array
    {
        if ($username !== null && trim($username) === '') {
            return [
                'success' => false,
                'message' => 'Username cannot be empty',
                'refusal' => Refusal::INVALID_INPUT,
                'code' => self::ERR_USERNAME_REQUIRED,
            ];
        }
        return null;
    }
}
