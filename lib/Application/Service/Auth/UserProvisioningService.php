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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\ClassContextLogger;
use Psr\Log\LoggerInterface;
use Poweradmin\Domain\Enum\AuthMethod;

/**
 * Creates or updates the local user for an LDAP, OIDC or SAML login and maps their groups to a permission template.
 */
class UserProvisioningService
{
    // Authentication method constants
    /** Aliases kept for callers; {@see AuthMethod} owns the vocabulary. */
    public const AUTH_METHOD_SQL = AuthMethod::SQL->value;
    public const AUTH_METHOD_LDAP = AuthMethod::LDAP->value;
    public const AUTH_METHOD_OIDC = AuthMethod::OIDC->value;
    public const AUTH_METHOD_SAML = AuthMethod::SAML->value;

    /** Methods with an identity link table; LDAP identity is the username itself. */
    private const LINKABLE_AUTH_METHODS = [self::AUTH_METHOD_OIDC, self::AUTH_METHOD_SAML];

    private LoggerInterface $logger;
    private ConfigurationInterface $configManager;
    private UserRepositoryInterface $userRepository;
    private ExternalIdentityRepositoryInterface $identities;
    private UserGroupLookupInterface $groups;
    private UserGroupMemberRepositoryInterface $groupMembers;

    public function __construct(
        ConfigurationInterface $configManager,
        LoggerInterface $logger,
        UserRepositoryInterface $userRepository,
        ExternalIdentityRepositoryInterface $identities,
        UserGroupLookupInterface $groups,
        UserGroupMemberRepositoryInterface $groupMembers
    ) {
        $this->logger = ClassContextLogger::for($logger, self::class);

        $this->configManager = $configManager;
        $this->userRepository = $userRepository;
        $this->identities = $identities;
        $this->groups = $groups;
        $this->groupMembers = $groupMembers;
    }

    /**
     * Sync an already-matched user from external auth data. Identity fields,
     * template mapping and group membership are driven by the auth method's
     * config section; nothing is written when the data is unchanged.
     */
    public function syncExistingUser(int $userId, UserInfoInterface $userInfo): void
    {
        $this->updateExistingUser($userId, $userInfo, $this->determineAuthMethodFromUserInfo($userInfo));
    }

    public function provisionUser(UserInfoInterface $userInfo, string $providerId): ?int
    {
        // Determine auth method from the actual UserInfo type being used
        $authMethod = $this->determineAuthMethodFromUserInfo($userInfo);
        $this->logger->info('Starting user provisioning for {method} user: {username}', [
            'method' => strtoupper($authMethod),
            'username' => $userInfo->getUsername()
        ]);

        if (!$userInfo->isValid()) {
            $this->logger->warning('Invalid OIDC user info provided for provisioning: {details}', [
                'details' => [
                    'username' => $userInfo->getUsername(),
                    'email' => $userInfo->getEmail(),
                    'subject' => $userInfo->getSubject()
                ]
            ]);
            return null;
        }

        try {
            // First, try to find existing user by subject (LDAP identity is the
            // username itself - there is no separate link table)
            $existingUserId = match ($authMethod) {
                self::AUTH_METHOD_SAML => $this->findUserBySamlSubject($userInfo->getSubject(), $providerId),
                self::AUTH_METHOD_LDAP => $this->findLdapUserByUsername($userInfo->getUsername()),
                default => $this->findUserByOidcSubject($userInfo->getSubject(), $providerId),
            };

            if ($existingUserId) {
                $this->logger->info('Found existing user by {method} subject: {subject}', [
                    'method' => strtoupper($authMethod),
                    'subject' => $userInfo->getSubject()
                ]);
                $this->updateExistingUser($existingUserId, $userInfo, $authMethod);
                return $existingUserId;
            }

            // Try to find by email if email linking is enabled
            $authConfig = $this->getAuthMethodConfig($authMethod);
            if (
                in_array($authMethod, self::LINKABLE_AUTH_METHODS, true)
                && ($authConfig['link_by_email'] ?? true)
                && !empty($userInfo->getEmail())
                && $this->emailClaimIsLinkable($userInfo)
            ) {
                $existingUserId = $this->findUserByEmail($userInfo->getEmail());

                if ($existingUserId !== null && $this->userHoldsSuperuserPermission($existingUserId)) {
                    // An address is not proof of identity, so it may never hand out
                    // the account that can rewrite every zone and every other user.
                    $this->logger->warning(
                        'Refusing to link {method} identity to superuser account {id} by email',
                        ['method' => strtoupper($authMethod), 'id' => $existingUserId]
                    );
                    $existingUserId = null;
                }

                if ($existingUserId) {
                    $this->logger->info('Found existing user by email: {email}', ['email' => $userInfo->getEmail()]);
                    $this->linkIdentity($existingUserId, $userInfo, $providerId, $authMethod);
                    $this->updateExistingUser($existingUserId, $userInfo, $authMethod);
                    return $existingUserId;
                }
            }

            // Create new user if auto-provisioning is enabled
            if ($authConfig['auto_provision'] ?? true) {
                return $this->createNewUser($userInfo, $providerId, $authMethod);
            }

            $this->logger->warning(
                'User not found and auto-provisioning disabled: {username}',
                ['username' => $userInfo->getUsername()]
            );
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error provisioning OIDC user {username}: {error}', [
                'username' => $userInfo->getUsername(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function findUserByOidcSubject(string $subject, string $providerId): ?int
    {
        try {
            $this->logger->info('Looking for existing user by OIDC subject: {subject} and provider: {provider}', [
                'subject' => $subject,
                'provider' => $providerId
            ]);

            $userId = $this->identities->findUserIdByOidcSubject($subject, $providerId);

            if ($userId !== null) {
                // Verify the user actually exists in the users table
                if ($this->userRepository->getUserById($userId) !== null) {
                    $this->logger->info('Found existing user by OIDC subject, user ID: {userId}', ['userId' => $userId]);
                    return $userId;
                } else {
                    $this->logger->warning('Found OIDC link for user ID {userId} but user no longer exists, cleaning up orphaned record', ['userId' => $userId]);
                    $this->cleanupOrphanedOidcLinks($subject, $providerId);
                }
            } else {
                $this->logger->info('No existing user found by OIDC subject');
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error finding user by OIDC subject (table may not exist): {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function findLdapUserByUsername(string $username): ?int
    {
        $user = $this->userRepository->findByUsername($username);

        return $user !== null && $user->isLdapUser() ? $user->getId() : null;
    }

    /**
     * Record the external identity for methods that keep a link table.
     * LDAP is a no-op: its identity is the username itself.
     */
    private function linkIdentity(int $userId, UserInfoInterface $userInfo, string $providerId, string $authMethod): void
    {
        if (!in_array($authMethod, self::LINKABLE_AUTH_METHODS, true)) {
            return;
        }

        $this->logger->info('Linking {method} identity to user ID: {userId}', ['method' => strtoupper($authMethod), 'userId' => $userId]);
        if ($authMethod === self::AUTH_METHOD_SAML) {
            $this->linkSamlToExistingUser($userId, $userInfo, $providerId);
        } else {
            $this->linkOidcToExistingUser($userId, $userInfo, $providerId);
        }
    }

    /**
     * Decide whether the provider's email claim may be used to match an account.
     *
     * OpenID Connect defines email_verified so a relying party can tell whether
     * the provider actually confirmed the address; a provider that says "false"
     * is stating the address is attacker-settable. SAML has no equivalent claim,
     * so an absent value stays permissive and the superuser rule carries the load.
     */
    private function emailClaimIsLinkable(UserInfoInterface $userInfo): bool
    {
        $claims = $userInfo->getRawData();
        if (!array_key_exists('email_verified', $claims)) {
            return true;
        }

        $verified = $claims['email_verified'];
        // SAML attribute bags wrap every value in an array; OIDC sends it bare.
        if (is_array($verified)) {
            $verified = $verified[0] ?? null;
        }

        // Providers serialize this as bool, "true"/"false", or 1/0.
        $isVerified = filter_var($verified, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        if (!$isVerified) {
            $this->logger->warning(
                'Provider reported email_verified=false; skipping email-based account linking for {email}',
                ['email' => $userInfo->getEmail()]
            );
        }

        return $isVerified;
    }

    /**
     * Check whether an account carries user_is_ueberuser, directly or via a group.
     */
    private function userHoldsSuperuserPermission(int $userId): bool
    {
        try {
            return $this->userRepository->hasAdminPermission($userId);
        } catch (\Exception $e) {
            // Fail closed: an unreadable permission state must not permit linking.
            $this->logger->error('Error checking superuser permission: {error}', ['error' => $e->getMessage()]);
            return true;
        }
    }

    private function findUserByEmail(string $email): ?int
    {
        try {
            return $this->userRepository->findActiveUserIdByEmail($email);
        } catch (\Exception $e) {
            $this->logger->error('Error finding user by email: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function createNewUser(UserInfoInterface $userInfo, string $providerId, string $authMethod = self::AUTH_METHOD_OIDC): ?int
    {
        try {
            $this->logger->info('Creating new user from {method}: {username}', [
                'method' => strtoupper($authMethod),
                'username' => $userInfo->getUsername()
            ]);

            // Determine permission template based on groups
            $permissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups(), $authMethod);

            if (!$permissionTemplateId) {
                $this->logger->error('No permission template ID determined for user: {username}', ['username' => $userInfo->getUsername()]);
                return null;
            }

            $this->logger->info('Permission template ID determined: {templateId}', ['templateId' => $permissionTemplateId]);

            // LDAP logins authenticate by exact username, so a suffixed variant
            // would never be matched again - fail instead of uniquifying.
            $username = $userInfo->getUsername();
            if ($authMethod === self::AUTH_METHOD_LDAP) {
                if ($this->usernameExists($username)) {
                    $this->logger->error('Cannot auto-provision LDAP user {username}: username is taken by a local account', ['username' => $username]);
                    return null;
                }
            } else {
                $username = $this->ensureUniqueUsername($username);
            }
            $this->logger->info('Final username for creation: {username}', ['username' => $username]);

            // Log all the data that will be inserted
            $userData = [
                'username' => $username,
                'password' => '',
                'fullname' => $userInfo->getDisplayName() ?: $userInfo->getFullName(),
                'email' => $userInfo->getEmail(),
                'description' => 'Created via ' . strtoupper($authMethod) . ' from ' . $providerId,
                'active' => 1,
                'perm_templ' => $permissionTemplateId
            ];
            $this->logger->info('User data to be inserted: {userData}', ['userData' => $userData]);

            $userId = $this->userRepository->createProvisionedUser([
                'username' => $username,
                'fullname' => $userData['fullname'],
                'email' => $userData['email'],
                'description' => $userData['description'],
                'perm_templ' => $permissionTemplateId,
                'perm_templ_source' => $authMethod, // Template ownership: only this provider may revoke it later
                'use_ldap' => $authMethod === self::AUTH_METHOD_LDAP ? 1 : 0,
                'auth_method' => $authMethod,
            ]);
            $this->logger->info('User INSERT successful, new user ID: {userId}', ['userId' => $userId]);

            $this->linkIdentity($userId, $userInfo, $providerId, $authMethod);

            // Apply group membership based on external groups
            $this->applyGroupMembership($userId, $userInfo->getGroups(), $authMethod);

            $this->logger->info('Successfully created new user: {username} with ID: {id}', [
                'username' => $username,
                'id' => $userId
            ]);

            return $userId;
        } catch (\Exception $e) {
            $this->logger->error('Error creating new {method} user: {error} at {origin}', [
                'method' => strtoupper($authMethod),
                'error' => $e->getMessage(),
                'origin' => $e->getFile() . ':' . $e->getLine()
            ]);
            return null;
        }
    }

    private function updateExistingUser(int $userId, UserInfoInterface $userInfo, string $authMethod = self::AUTH_METHOD_OIDC): void
    {
        try {
            $updates = [];

            $current = $this->userRepository->getProvisioningProfile($userId);

            // Update user information if configured to sync, skipping unchanged values
            $authConfig = $this->getAuthMethodConfig($authMethod);
            if ($authConfig['sync_user_info'] ?? true) {
                $displayName = $userInfo->getDisplayName();
                if (!empty($displayName) && $displayName !== ($current['fullname'] ?? null)) {
                    $updates['fullname'] = $displayName;
                }

                $email = $userInfo->getEmail();
                if (!empty($email) && $email !== ($current['email'] ?? null)) {
                    $updates['email'] = $email;
                }
            }

            // Only update auth_method if it's safe to do so (prevent overwriting LDAP/other methods)
            $currentAuthMethod = isset($current['auth_method']) ? (string)$current['auth_method'] : null;
            if ($currentAuthMethod !== $authMethod && $this->shouldUpdateAuthMethod($currentAuthMethod, $authMethod)) {
                $updates['auth_method'] = $authMethod;
                $this->logger->info('Updating auth_method from {old} to {new} for user {userId}', [
                    'old' => $currentAuthMethod,
                    'new' => $authMethod,
                    'userId' => $userId
                ]);
            } elseif ($currentAuthMethod !== $authMethod) {
                $this->logger->info('Preserving existing auth_method {current} for user {userId} (not overwriting with {new})', [
                    'current' => $currentAuthMethod,
                    'new' => $authMethod,
                    'userId' => $userId
                ]);
            }

            // Update permission template based on current groups
            $newPermissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups(), $authMethod, false);
            if ($newPermissionTemplateId) {
                if ($newPermissionTemplateId !== (int)($current['perm_templ'] ?? 0) || ($current['perm_templ_source'] ?? '') !== $authMethod) {
                    $updates['perm_templ'] = $newPermissionTemplateId;
                    $updates['perm_templ_source'] = $authMethod;
                }
            } else {
                // Only the provider that assigned the template may revoke it
                // ('sso' is the legacy label from before per-method sources)
                $currentSource = $current['perm_templ_source'] ?? null;
                if ($currentSource === $authMethod || $currentSource === 'sso') {
                    // User previously got template from SSO mapping but no longer matches any group
                    // Fall back to default permission template
                    $defaultTemplateId = $this->getDefaultPermissionTemplateId($authMethod);
                    if ($defaultTemplateId) {
                        $updates['perm_templ'] = $defaultTemplateId;
                        $this->logger->info('Revoked SSO group-mapped template for user {userId}, falling back to default template', [
                            'userId' => $userId
                        ]);
                    } else {
                        $this->logger->warning('SSO group-mapped template should be revoked for user {userId} but no default template configured - keeping current template', [
                            'userId' => $userId
                        ]);
                    }
                } else {
                    $this->logger->info('No matching group mapping for user {userId}, keeping admin-assigned permissions unchanged', [
                        'userId' => $userId
                    ]);
                }
            }

            if (!empty($updates)) {
                $this->userRepository->updateProvisionedUser($userId, $updates);

                $this->logger->info('Updated user information and permissions for user ID: {id}', ['id' => $userId]);
            }

            // Apply/sync group membership based on external groups
            $this->applyGroupMembership($userId, $userInfo->getGroups(), $authMethod);
        } catch (\Exception $e) {
            $this->logger->error('Error updating existing user: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function linkOidcToExistingUser(int $userId, UserInfoInterface $userInfo, string $providerId): void
    {
        try {
            $this->identities->linkOidc($userId, $providerId, $userInfo->getSubject(), $userInfo->getUsername(), $userInfo->getEmail());

            $this->logger->info('Linked external identity to user ID: {id}', ['id' => $userId]);
        } catch (\Exception $e) {
            $this->logger->error('Error linking external identity: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function findUserBySamlSubject(string $subject, string $providerId): ?int
    {
        try {
            $this->logger->info('Looking for existing user by SAML subject: {subject} and provider: {provider}', [
                'subject' => $subject,
                'provider' => $providerId
            ]);

            $userId = $this->identities->findUserIdBySamlSubject($subject, $providerId);

            if ($userId !== null) {
                // Verify the user actually exists in the users table
                if ($this->userRepository->getUserById($userId) !== null) {
                    $this->logger->info('Found existing user by SAML subject, user ID: {userId}', ['userId' => $userId]);
                    return $userId;
                } else {
                    $this->logger->warning('Found SAML link for user ID {userId} but user no longer exists, cleaning up orphaned record', ['userId' => $userId]);
                    $this->cleanupOrphanedSamlLinks($subject, $providerId);
                }
            } else {
                $this->logger->info('No existing user found by SAML subject');
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error finding user by SAML subject (table may not exist): {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function linkSamlToExistingUser(int $userId, UserInfoInterface $userInfo, string $providerId): void
    {
        try {
            $this->identities->linkSaml($userId, $providerId, $userInfo->getSubject(), $userInfo->getUsername(), $userInfo->getEmail());

            $this->logger->info('Linked SAML identity to user ID: {id}', ['id' => $userId]);
        } catch (\Exception $e) {
            $this->logger->error('Error linking SAML identity: {error}', ['error' => $e->getMessage()]);
        }
    }


    private function determinePermissionTemplate(array $groups, string $authMethod = self::AUTH_METHOD_OIDC, bool $useDefaultFallback = true): ?int
    {
        $this->logger->debug('Determining permission template for groups: {groups}', ['groups' => $groups]);

        $permissionTemplateMapping = $this->configManager->get($authMethod, 'permission_template_mapping', []);
        $this->logger->debug('Available permission template mappings: {mappings}', ['mappings' => $permissionTemplateMapping]);

        // Check if user's groups match any configured mappings
        foreach ($permissionTemplateMapping as $groupName => $templateName) {
            if ($this->groupMatches((string)$groupName, $groups)) {
                $this->logger->info('Found matching group: {group}', ['group' => $groupName]);
                $templateId = $this->findPermissionTemplateByName($templateName, $authMethod);
                if ($templateId) {
                    $this->logger->info('Mapped OIDC group {group} to permission template: {template} (ID: {id})', [
                        'group' => $groupName,
                        'template' => $templateName,
                        'id' => $templateId
                    ]);
                    return $templateId;
                } else {
                    $this->logger->warning('Permission template {template} not found for group {group}', [
                        'template' => $templateName,
                        'group' => $groupName
                    ]);
                }
            }
        }

        if (!$useDefaultFallback) {
            $this->logger->info('No matching group mapping found for existing user, keeping current permissions');
            return null;
        }

        $this->logger->info('No matching groups found, proceeding to default template');

        // Fall back to default permission template
        $defaultTemplateName = $this->configManager->get($authMethod, 'default_permission_template', '');

        if (empty($defaultTemplateName)) {
            $this->logger->error('No default permission template configured and user has no matching groups. User provisioning failed.');
            return null;
        }

        $this->logger->info('Falling back to default permission template: {template}', ['template' => $defaultTemplateName]);

        $defaultTemplateId = $this->findPermissionTemplateByName($defaultTemplateName, $authMethod);

        if ($defaultTemplateId) {
            $this->logger->info('Using default permission template: {template} (ID: {id})', [
                'template' => $defaultTemplateName,
                'id' => $defaultTemplateId
            ]);
            return $defaultTemplateId;
        }

        // Fail closed. Picking "any available template" resolved to the lowest id,
        // which is the bundled Administrator template, so a renamed or deleted
        // default silently provisioned external identities as superusers.
        $this->logger->error(
            'Default permission template {template} not found in database; refusing to provision user.',
            ['template' => $defaultTemplateName]
        );

        return null;
    }

    private function getDefaultPermissionTemplateId(string $authMethod): ?int
    {
        $defaultTemplateName = $this->configManager->get($authMethod, 'default_permission_template', '');
        if (empty($defaultTemplateName)) {
            return null;
        }
        return $this->findPermissionTemplateByName($defaultTemplateName, $authMethod);
    }

    /**
     * Get the database username for a user ID
     * Used to set correct session username when linking existing users
     */
    public function getDatabaseUsername(int $userId): ?string
    {
        try {
            $user = $this->userRepository->getUserById($userId);

            return $user !== null ? (string)$user['username'] : null;
        } catch (\Exception $e) {
            $this->logger->error('Error getting database username for user ID {userId}: {error}', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function findPermissionTemplateByName(string $templateName, string $authMethod): ?int
    {
        try {
            $templateId = $this->userRepository->findPermissionTemplateIdByName($templateName);
            if ($templateId === null) {
                return null;
            }

            if (!$this->superuserProvisioningAllowed($authMethod) && $this->templateGrantsSuperuser($templateId)) {
                $this->logger->warning(
                    'Refusing to provision superuser template {template} from {method}; '
                    . 'set allow_superuser_provisioning to permit it',
                    ['template' => $templateName, 'method' => strtoupper($authMethod)]
                );
                return null;
            }

            return $templateId;
        } catch (\Exception $e) {
            $this->logger->error('Error finding permission template by name: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Whether this auth method may hand out superuser rights without an admin acting.
     */
    private function superuserProvisioningAllowed(string $authMethod): bool
    {
        return (bool)$this->configManager->get($authMethod, 'allow_superuser_provisioning', false);
    }

    private function templateGrantsSuperuser(int $permTemplId): bool
    {
        try {
            return $this->userRepository->templateGrantsUberuser($permTemplId);
        } catch (\Exception $e) {
            // Fail closed: an unreadable template must not be assumed harmless.
            $this->logger->error('Error checking template permissions: {error}', ['error' => $e->getMessage()]);
            return true;
        }
    }

    /**
     * Whether membership of this group would confer superuser rights on its members.
     */
    private function groupGrantsSuperuser(int $groupId): bool
    {
        try {
            $group = $this->groups->findById($groupId);

            return $group !== null && $this->templateGrantsSuperuser($group->getPermTemplId());
        } catch (\Exception $e) {
            $this->logger->error('Error checking group permissions: {error}', ['error' => $e->getMessage()]);
            return true;
        }
    }

    private function ensureUniqueUsername(string $username): string
    {
        $originalUsername = $username;
        $counter = 1;

        while ($this->usernameExists($username)) {
            $username = $originalUsername . '_' . $counter;
            $counter++;

            // Prevent infinite loop
            if ($counter > 100) {
                $username = $originalUsername . '_' . uniqid();
                break;
            }
        }

        return $username;
    }

    private function usernameExists(string $username): bool
    {
        try {
            return $this->userRepository->getUserByUsername($username) !== null;
        } catch (\Exception $e) {
            $this->logger->error('Error checking username existence: {error}', ['error' => $e->getMessage()]);
            return true; // Assume it exists to be safe
        }
    }


    /**
     * Determine authentication method from the UserInfo type being used
     * This prevents ambiguity when OIDC and SAML providers have the same provider ID
     */
    private function determineAuthMethodFromUserInfo(UserInfoInterface $userInfo): string
    {
        return $userInfo->authMethod()->value;
    }

    /**
     * Get configuration settings based on auth method
     */
    private function getAuthMethodConfig(string $authMethod): array
    {
        return $this->configManager->getGroup($authMethod);
    }

    /**
     * Determine if we should update the auth_method field
     * Only update if:
     * - Current method is null/empty (new user or unset)
     * - Current method matches the new method (refreshing same auth type)
     * - Transitioning between SAML and OIDC (both external SSO methods)
     *
     * NOTE: We preserve SQL auth_method to allow users to continue using
     * SQL authentication even after logging in via external providers.
     */
    private function shouldUpdateAuthMethod(?string $currentAuthMethod, string $newAuthMethod): bool
    {
        // If no current auth method, it's safe to set
        if (empty($currentAuthMethod)) {
            return true;
        }

        // Safe to refresh the same auth method
        if ($currentAuthMethod === $newAuthMethod) {
            return true;
        }

        // Allow transitions between SAML and OIDC (both external SSO methods)
        $externalSsoMethods = [self::AUTH_METHOD_SAML, self::AUTH_METHOD_OIDC];
        if (
            in_array($currentAuthMethod, $externalSsoMethods, true) &&
            in_array($newAuthMethod, $externalSsoMethods, true)
        ) {
            return true;
        }

        // Don't overwrite SQL, LDAP or other auth methods to preserve existing login capabilities
        return false;
    }

    /**
     * Clean up orphaned external authentication links
     * This method finds and removes OIDC/SAML links that point to non-existent users
     *
     * @return array Array with counts of cleaned up links
     */
    public function cleanupOrphanedAuthLinks(): array
    {
        try {
            $cleanupCount = 0;

            $orphanedLinks = $this->identities->findOrphanedOidcLinks();

            if (!empty($orphanedLinks)) {
                $this->logger->info('Found {count} orphaned external auth links to clean up', ['count' => count($orphanedLinks)]);

                foreach ($orphanedLinks as $link) {
                    $this->logger->info('Cleaning up orphaned link: user_id={user_id}, provider={provider}, username={username}', [
                        'user_id' => $link['user_id'],
                        'provider' => $link['provider_id'],
                        'username' => $link['username']
                    ]);
                }

                $cleanupCount = $this->identities->deleteOrphanedOidcLinks();

                $this->logger->info('Successfully cleaned up {count} orphaned external auth links', ['count' => $cleanupCount]);
            } else {
                $this->logger->info('No orphaned external auth links found');
            }

            return [
                'success' => true,
                'cleaned_up_count' => $cleanupCount,
                'orphaned_links' => $orphanedLinks
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up orphaned auth links: {error}', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'cleaned_up_count' => 0
            ];
        }
    }

    /**
     * Clean up orphaned SAML user links that reference non-existent users
     */
    private function cleanupOrphanedSamlLinks(string $subject, string $providerId): void
    {
        try {
            $this->logger->info('Checking for orphaned SAML links for subject: {subject}', ['subject' => $subject]);

            $linkIds = $this->identities->findOrphanedSamlLinkIds($subject, $providerId);

            if (!empty($linkIds)) {
                $this->logger->warning('Found {count} orphaned SAML links for subject {subject}, cleaning up...', [
                    'count' => count($linkIds),
                    'subject' => $subject
                ]);

                $this->identities->deleteSamlLinks($linkIds);

                $this->logger->info('Successfully cleaned up {count} orphaned SAML links', ['count' => count($linkIds)]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up orphaned SAML links: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up orphaned OIDC user links that reference non-existent users
     */
    private function cleanupOrphanedOidcLinks(string $subject, string $providerId): void
    {
        try {
            $this->logger->info('Checking for orphaned OIDC links for subject: {subject}', ['subject' => $subject]);

            $linkIds = $this->identities->findOrphanedOidcLinkIds($subject, $providerId);

            if (!empty($linkIds)) {
                $this->logger->warning('Found {count} orphaned OIDC links for subject {subject}, cleaning up...', [
                    'count' => count($linkIds),
                    'subject' => $subject
                ]);

                $this->identities->deleteOidcLinks($linkIds);

                $this->logger->info('Successfully cleaned up {count} orphaned OIDC links', ['count' => count($linkIds)]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up orphaned OIDC links: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Synchronize group membership based on OIDC/SAML groups
     * Maps external groups to Poweradmin groups, adds user to matching groups,
     * and removes user from mapped groups they no longer belong to.
     *
     * @param int $userId User ID to sync groups for
     * @param array $externalGroups Groups from external identity provider
     * @param string $authMethod Authentication method (oidc or saml)
     */
    private function applyGroupMembership(int $userId, array $externalGroups, string $authMethod): void
    {
        $groupMapping = $this->configManager->get($authMethod, 'group_mapping', []);

        if (empty($groupMapping)) {
            $this->logger->debug('No group mapping configured for {method}, skipping group membership sync', [
                'method' => strtoupper($authMethod)
            ]);
            return;
        }

        $this->logger->debug('Synchronizing group membership for user {userId} based on {method} groups: {groups}', [
            'userId' => $userId,
            'method' => strtoupper($authMethod),
            'groups' => $externalGroups
        ]);

        // Resolve every mapped Poweradmin group name once (name => id). Each
        // mapping value may be a single group name (legacy format) or a list.
        $mappedGroupIds = [];
        foreach ($groupMapping as $mappedValue) {
            foreach ($this->normalizeMappedGroupNames($mappedValue) as $poweradminGroupName) {
                if (array_key_exists($poweradminGroupName, $mappedGroupIds)) {
                    continue;
                }
                $mappedGroupIds[$poweradminGroupName] = $this->findGroupByName($poweradminGroupName);
                if ($mappedGroupIds[$poweradminGroupName] === null) {
                    $this->logger->warning('Poweradmin group {group} from {method} group_mapping not found', [
                        'group' => $poweradminGroupName,
                        'method' => strtoupper($authMethod)
                    ]);
                }
            }
        }
        $idToName = array_flip(array_filter($mappedGroupIds));

        // Groups the user should be in, based on matching external groups
        $targetGroupIds = [];
        foreach ($groupMapping as $externalGroupName => $mappedValue) {
            if (!$this->groupMatches((string)$externalGroupName, $externalGroups)) {
                continue;
            }
            foreach ($this->normalizeMappedGroupNames($mappedValue) as $poweradminGroupName) {
                if (!empty($mappedGroupIds[$poweradminGroupName])) {
                    $targetGroupIds[] = $mappedGroupIds[$poweradminGroupName];
                }
            }
        }
        $targetGroupIds = array_values(array_unique($targetGroupIds));

        // A group whose template grants superuser turns an IdP claim into full access,
        // so membership of it is not something an assertion may hand out on its own.
        if (!$this->superuserProvisioningAllowed($authMethod)) {
            $targetGroupIds = array_values(array_filter($targetGroupIds, function (int $groupId) use ($idToName, $authMethod): bool {
                if (!$this->groupGrantsSuperuser($groupId)) {
                    return true;
                }

                $this->logger->warning(
                    'Refusing to add {method} user to superuser group {group}; '
                    . 'set allow_superuser_provisioning to permit it',
                    ['method' => strtoupper($authMethod), 'group' => $idToName[$groupId] ?? $groupId]
                );
                return false;
            }));
        }

        // Current memberships among mapped groups, so unchanged state costs no writes
        $currentGroupIds = [];
        $allMappedIds = array_keys($idToName);
        if ($allMappedIds !== []) {
            foreach ($this->groupMembers->findByUserId($userId) as $membership) {
                if (in_array($membership->getGroupId(), $allMappedIds, true)) {
                    $currentGroupIds[] = $membership->getGroupId();
                }
            }
        }

        foreach (array_diff($currentGroupIds, $targetGroupIds) as $groupId) {
            if ($this->removeUserFromGroup($userId, $groupId)) {
                $this->logger->info('Removed user {userId} from group: {group} (no longer in external group)', [
                    'userId' => $userId,
                    'group' => $idToName[$groupId] ?? $groupId
                ]);
            }
        }

        $addedGroups = [];
        foreach (array_diff($targetGroupIds, $currentGroupIds) as $groupId) {
            if ($this->addUserToGroup($userId, $groupId)) {
                $addedGroups[] = $idToName[$groupId] ?? $groupId;
            }
        }

        if (!empty($addedGroups)) {
            $this->logger->info('User {userId} membership synchronized, in groups: {groups}', [
                'userId' => $userId,
                'groups' => implode(', ', $addedGroups)
            ]);
        }
    }

    /**
     * True when a mapping key equals a group value exactly.
     *
     * Matching only the first RDN of a DN would discard the OU and DC that
     * distinguish two identically named groups, so a directory user able to
     * create a group in their own OU could satisfy a mapping meant for another.
     * DN-shaped groups must therefore be configured as the full DN.
     *
     * PHP stores a numeric array key as an int, and some providers emit the
     * groups claim as JSON numbers, so both sides are compared as strings.
     */
    private function groupMatches(string $configKey, array $groups): bool
    {
        foreach ($groups as $group) {
            if (is_scalar($group) && (string)$group === $configKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a group_mapping value into a list of Poweradmin group names.
     * Accepts the legacy single-string form ('team1' => 'Administrators') and the
     * 1:n array form ('team1' => ['lab1', 'lab2']) so existing configs keep working.
     *
     * @param mixed $value Raw mapping value from configuration
     * @return string[] Non-empty Poweradmin group names
     */
    private function normalizeMappedGroupNames(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }
        return $names;
    }

    /**
     * Find a Poweradmin group by name
     *
     * @param string $groupName Group name to find
     * @return int|null Group ID or null if not found
     */
    private function findGroupByName(string $groupName): ?int
    {
        try {
            return $this->groups->findIdByExactName($groupName);
        } catch (\Exception $e) {
            $this->logger->error('Error finding group by name: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Add a user to a group if not already a member
     *
     * @param int $userId User ID
     * @param int $groupId Group ID
     * @return bool True if user was added or already a member
     */
    private function addUserToGroup(int $userId, int $groupId): bool
    {
        try {
            // add() returns the existing membership when the user is already in the group
            $this->groupMembers->add($groupId, $userId);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error adding user {userId} to group {groupId}: {error}', [
                'userId' => $userId,
                'groupId' => $groupId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Remove a user from a group
     *
     * @param int $userId User ID
     * @param int $groupId Group ID
     * @return bool True if user was removed or wasn't a member
     */
    private function removeUserFromGroup(int $userId, int $groupId): bool
    {
        try {
            return $this->groupMembers->remove($groupId, $userId);
        } catch (\Exception $e) {
            $this->logger->error('Error removing user {userId} from group {groupId}: {error}', [
                'userId' => $userId,
                'groupId' => $groupId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
