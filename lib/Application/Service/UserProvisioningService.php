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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Domain\ValueObject\SamlUserInfo;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionClass;

/**
 * User provisioning service for external authentication providers
 * Supports both OIDC and SAML user provisioning and linking
 */
class UserProvisioningService extends LoggingService
{
    // Authentication method constants
    public const AUTH_METHOD_SQL = 'sql';
    public const AUTH_METHOD_LDAP = 'ldap';
    public const AUTH_METHOD_OIDC = 'oidc';
    public const AUTH_METHOD_SAML = 'saml';

    private PDOCommon $db;
    private ConfigurationManager $configManager;
    private UserManager $userManager;
    private DbUserRepository $userRepository;

    public function __construct(
        PDOCommon $connection,
        ConfigurationManager $configManager,
        Logger $logger
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->db = $connection;
        $this->configManager = $configManager;
        $this->userManager = new UserManager($connection, $configManager);
        $this->userRepository = new DbUserRepository($connection, $configManager);
    }

    public function provisionUser(UserInfoInterface $userInfo, string $providerId): ?int
    {
        // Determine auth method from the actual UserInfo type being used
        $authMethod = $this->determineAuthMethodFromUserInfo($userInfo);
        $this->logInfo('Starting user provisioning for {method} user: {username}', [
            'method' => strtoupper($authMethod),
            'username' => $userInfo->getUsername()
        ]);

        if (!$userInfo->isValid()) {
            $this->logWarning('Invalid OIDC user info provided for provisioning: {details}', [
                'details' => [
                    'username' => $userInfo->getUsername(),
                    'email' => $userInfo->getEmail(),
                    'subject' => $userInfo->getSubject()
                ]
            ]);
            return null;
        }

        try {
            // First, try to find existing user by subject
            $existingUserId = $authMethod === self::AUTH_METHOD_SAML
                ? $this->findUserBySamlSubject($userInfo->getSubject(), $providerId)
                : $this->findUserByOidcSubject($userInfo->getSubject(), $providerId);

            if ($existingUserId) {
                $this->logInfo('Found existing user by {method} subject: {subject}', [
                    'method' => strtoupper($authMethod),
                    'subject' => $userInfo->getSubject()
                ]);
                $this->updateExistingUser($existingUserId, $userInfo, $authMethod);
                return $existingUserId;
            }

            // Try to find by email if email linking is enabled
            $authConfig = $this->getAuthMethodConfig($authMethod);
            if (($authConfig['link_by_email'] ?? true) && !empty($userInfo->getEmail())) {
                $existingUserId = $this->findUserByEmail($userInfo->getEmail());

                if ($existingUserId) {
                    $this->logInfo('Found existing user by email: {email}', ['email' => $userInfo->getEmail()]);
                    if ($authMethod === self::AUTH_METHOD_SAML) {
                        $this->linkSamlToExistingUser($existingUserId, $userInfo, $providerId);
                    } else {
                        $this->linkOidcToExistingUser($existingUserId, $userInfo, $providerId);
                    }
                    $this->updateExistingUser($existingUserId, $userInfo, $authMethod);
                    return $existingUserId;
                }
            }

            // Create new user if auto-provisioning is enabled
            if ($authConfig['auto_provision'] ?? true) {
                return $this->createNewUser($userInfo, $providerId, $authMethod);
            }

            $this->logWarning(
                'User not found and auto-provisioning disabled: {username}',
                ['username' => $userInfo->getUsername()]
            );
            return null;
        } catch (\Exception $e) {
            $this->logError('Error provisioning OIDC user {username}: {error}', [
                'username' => $userInfo->getUsername(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function findUserByOidcSubject(string $subject, string $providerId): ?int
    {
        try {
            $this->logInfo('Looking for existing user by OIDC subject: {subject} and provider: {provider}', [
                'subject' => $subject,
                'provider' => $providerId
            ]);

            $stmt = $this->db->prepare("
                SELECT user_id FROM oidc_user_links
                WHERE oidc_subject = ? AND provider_id = ?
            ");
            $stmt->execute([$subject, $providerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->logInfo('Found existing user by OIDC subject, user ID: {userId}', ['userId' => $result['user_id']]);
            } else {
                $this->logInfo('No existing user found by OIDC subject');
            }

            return $result ? (int)$result['user_id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding user by OIDC subject (table may not exist): {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function findUserByEmail(string $email): ?int
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND active = 1");
            $stmt->execute([$email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding user by email: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function createNewUser(UserInfoInterface $userInfo, string $providerId, string $authMethod = self::AUTH_METHOD_OIDC): ?int
    {
        try {
            $this->logInfo('Creating new user from {method}: {username}', [
                'method' => strtoupper($authMethod),
                'username' => $userInfo->getUsername()
            ]);

            // Determine permission template based on groups
            $permissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups(), $authMethod);

            if (!$permissionTemplateId) {
                $this->logError('No permission template ID determined for user: {username}', ['username' => $userInfo->getUsername()]);
                return null;
            }

            $this->logInfo('Permission template ID determined: {templateId}', ['templateId' => $permissionTemplateId]);

            // Generate a unique username if needed
            $username = $this->ensureUniqueUsername($userInfo->getUsername());
            $this->logInfo('Final username for creation: {username}', ['username' => $username]);

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
            $this->logInfo('User data to be inserted: {userData}', ['userData' => $userData]);

            // Create user
            $stmt = $this->db->prepare("
                INSERT INTO users (username, password, fullname, email, description, active, perm_templ, use_ldap, auth_method)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $success = $stmt->execute([
                $username,
                '', // No password for external auth users
                $userInfo->getDisplayName() ?: $userInfo->getFullName(),
                $userInfo->getEmail(),
                'Created via ' . strtoupper($authMethod) . ' from ' . $providerId,
                1, // Active
                $permissionTemplateId,
                0,  // use_ldap = 0 for external auth users
                $authMethod  // auth_method (oidc, saml, etc.)
            ]);

            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                $this->logError('Database INSERT failed. PDO Error: {error}', ['error' => $errorInfo]);
                throw new \RuntimeException('Failed to insert user. PDO Error: ' . implode(' - ', $errorInfo));
            }

            $userId = (int)$this->db->lastInsertId();
            $this->logInfo('User INSERT successful, new user ID: {userId}', ['userId' => $userId]);

            // Link identity to user
            $this->logInfo('Linking {method} identity to user ID: {userId}', ['method' => strtoupper($authMethod), 'userId' => $userId]);
            if ($authMethod === self::AUTH_METHOD_SAML) {
                $this->linkSamlToExistingUser($userId, $userInfo, $providerId);
            } else {
                $this->linkOidcToExistingUser($userId, $userInfo, $providerId);
            }

            $this->logInfo('Successfully created new user: {username} with ID: {id}', [
                'username' => $username,
                'id' => $userId
            ]);

            return $userId;
        } catch (\Exception $e) {
            $this->logError('Error creating new OIDC user: {error}. Stack trace: {trace}', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function updateExistingUser(int $userId, UserInfoInterface $userInfo, string $authMethod = self::AUTH_METHOD_OIDC): void
    {
        try {
            $updateFields = [];
            $updateValues = [];

            // Update user information if configured to sync
            $authConfig = $this->getAuthMethodConfig($authMethod);
            if ($authConfig['sync_user_info'] ?? true) {
                if (!empty($userInfo->getDisplayName())) {
                    $updateFields[] = 'fullname = ?';
                    $updateValues[] = $userInfo->getDisplayName();
                }

                if (!empty($userInfo->getEmail())) {
                    $updateFields[] = 'email = ?';
                    $updateValues[] = $userInfo->getEmail();
                }
            }

            // Only update auth_method if it's safe to do so (prevent overwriting LDAP/other methods)
            $currentAuthMethod = $this->getCurrentUserAuthMethod($userId);
            if ($this->shouldUpdateAuthMethod($currentAuthMethod, $authMethod)) {
                $updateFields[] = 'auth_method = ?';
                $updateValues[] = $authMethod;
                $this->logInfo('Updating auth_method from {old} to {new} for user {userId}', [
                    'old' => $currentAuthMethod,
                    'new' => $authMethod,
                    'userId' => $userId
                ]);
            } else {
                $this->logInfo('Preserving existing auth_method {current} for user {userId} (not overwriting with {new})', [
                    'current' => $currentAuthMethod,
                    'new' => $authMethod,
                    'userId' => $userId
                ]);
            }

            // Update permission template based on current groups
            $newPermissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups(), $authMethod);
            if ($newPermissionTemplateId) {
                $updateFields[] = 'perm_templ = ?';
                $updateValues[] = $newPermissionTemplateId;
            } else {
                $this->logWarning('No permission template mapping found for existing user groups - keeping current permissions unchanged', [
                    'groups' => $userInfo->getGroups(),
                    'userId' => $userId
                ]);
            }

            if (!empty($updateFields)) {
                $updateValues[] = $userId;
                $stmt = $this->db->prepare("
                    UPDATE users SET " . implode(', ', $updateFields) . " 
                    WHERE id = ?
                ");
                $stmt->execute($updateValues);

                $this->logInfo('Updated user information and permissions for user ID: {id}', ['id' => $userId]);
            }
        } catch (\Exception $e) {
            $this->logError('Error updating existing user: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function linkOidcToExistingUser(int $userId, UserInfoInterface $userInfo, string $providerId): void
    {
        try {
            // Check if link already exists
            $stmt = $this->db->prepare("
                SELECT id FROM oidc_user_links 
                WHERE user_id = ? AND provider_id = ?
            ");
            $stmt->execute([$userId, $providerId]);

            if ($stmt->fetch()) {
                // Update existing link
                $stmt = $this->db->prepare("
                    UPDATE oidc_user_links 
                    SET oidc_subject = ?, username = ?, email = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ? AND provider_id = ?
                ");
                $stmt->execute([
                    $userInfo->getSubject(),
                    $userInfo->getUsername(),
                    $userInfo->getEmail(),
                    $userId,
                    $providerId
                ]);
            } else {
                // Create new link
                $stmt = $this->db->prepare("
                    INSERT INTO oidc_user_links 
                    (user_id, provider_id, oidc_subject, username, email, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    $userId,
                    $providerId,
                    $userInfo->getSubject(),
                    $userInfo->getUsername(),
                    $userInfo->getEmail()
                ]);
            }

            $this->logInfo('Linked external identity to user ID: {id}', ['id' => $userId]);
        } catch (\Exception $e) {
            $this->logError('Error linking external identity: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function findUserBySamlSubject(string $subject, string $providerId): ?int
    {
        try {
            $this->logInfo('Looking for existing user by SAML subject: {subject} and provider: {provider}', [
                'subject' => $subject,
                'provider' => $providerId
            ]);

            $stmt = $this->db->prepare("
                SELECT user_id FROM saml_user_links
                WHERE saml_subject = ? AND provider_id = ?
            ");
            $stmt->execute([$subject, $providerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->logInfo('Found existing user by SAML subject, user ID: {userId}', ['userId' => $result['user_id']]);
            } else {
                $this->logInfo('No existing user found by SAML subject');
            }

            return $result ? (int)$result['user_id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding user by SAML subject (table may not exist): {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function linkSamlToExistingUser(int $userId, UserInfoInterface $userInfo, string $providerId): void
    {
        try {
            // Check if link already exists
            $stmt = $this->db->prepare("
                SELECT id FROM saml_user_links
                WHERE user_id = ? AND provider_id = ?
            ");
            $stmt->execute([$userId, $providerId]);

            if ($stmt->fetch()) {
                // Update existing link
                $stmt = $this->db->prepare("
                    UPDATE saml_user_links
                    SET saml_subject = ?, username = ?, email = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ? AND provider_id = ?
                ");
                $stmt->execute([
                    $userInfo->getSubject(),
                    $userInfo->getUsername(),
                    $userInfo->getEmail(),
                    $userId,
                    $providerId
                ]);
            } else {
                // Create new link
                $stmt = $this->db->prepare("
                    INSERT INTO saml_user_links
                    (user_id, provider_id, saml_subject, username, email, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    $userId,
                    $providerId,
                    $userInfo->getSubject(),
                    $userInfo->getUsername(),
                    $userInfo->getEmail()
                ]);
            }

            $this->logInfo('Linked SAML identity to user ID: {id}', ['id' => $userId]);
        } catch (\Exception $e) {
            $this->logError('Error linking SAML identity: {error}', ['error' => $e->getMessage()]);
        }
    }


    private function determinePermissionTemplate(array $groups, string $authMethod = self::AUTH_METHOD_OIDC): ?int
    {
        $this->logInfo('Determining permission template for groups: {groups}', ['groups' => $groups]);

        $permissionTemplateMapping = $this->configManager->get($authMethod, 'permission_template_mapping', []);
        $this->logInfo('Available permission template mappings: {mappings}', ['mappings' => $permissionTemplateMapping]);

        // Check if user's groups match any configured mappings
        foreach ($permissionTemplateMapping as $groupName => $templateName) {
            $this->logInfo('Checking if group {groupName} is in user groups', ['groupName' => $groupName]);
            if (in_array($groupName, $groups, true)) {
                $this->logInfo('Found matching group: {group}', ['group' => $groupName]);
                $templateId = $this->findPermissionTemplateByName($templateName);
                if ($templateId) {
                    $this->logInfo('Mapped OIDC group {group} to permission template: {template} (ID: {id})', [
                        'group' => $groupName,
                        'template' => $templateName,
                        'id' => $templateId
                    ]);
                    return $templateId;
                } else {
                    $this->logWarning('Permission template {template} not found for group {group}', [
                        'template' => $templateName,
                        'group' => $groupName
                    ]);
                }
            }
        }

        $this->logInfo('No matching groups found, proceeding to default template');

        // Fall back to default permission template
        $defaultTemplateName = $this->configManager->get($authMethod, 'default_permission_template', '');

        if (empty($defaultTemplateName)) {
            $this->logError('No default permission template configured and user has no matching groups. User provisioning failed.');
            return null;
        }

        $this->logInfo('Falling back to default permission template: {template}', ['template' => $defaultTemplateName]);

        $defaultTemplateId = $this->findPermissionTemplateByName($defaultTemplateName);

        if ($defaultTemplateId) {
            $this->logInfo('Using default permission template: {template} (ID: {id})', [
                'template' => $defaultTemplateName,
                'id' => $defaultTemplateId
            ]);
            return $defaultTemplateId;
        } else {
            $this->logError('Default permission template {template} not found in database!', ['template' => $defaultTemplateName]);
        }

        // Final fallback: find any available template (should not happen in normal operation)
        $this->logWarning('Default permission template not found, using first available template');
        $fallbackId = $this->findFirstAvailablePermissionTemplate();

        if ($fallbackId) {
            $this->logInfo('Using fallback permission template ID: {id}', ['id' => $fallbackId]);
        } else {
            $this->logError('No permission templates found in database at all!');
        }

        return $fallbackId;
    }

    /**
     * Get the database username for a user ID
     * Used to set correct session username when linking existing users
     */
    public function getDatabaseUsername(int $userId): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result['username'] : null;
        } catch (\Exception $e) {
            $this->logError('Error getting database username for user ID {userId}: {error}', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function findPermissionTemplateByName(string $templateName): ?int
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM perm_templ WHERE name = ?");
            $stmt->execute([$templateName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding permission template by name: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function findFirstAvailablePermissionTemplate(): ?int
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM perm_templ ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding first available permission template: {error}', ['error' => $e->getMessage()]);
            return null;
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
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            $this->logError('Error checking username existence: {error}', ['error' => $e->getMessage()]);
            return true; // Assume it exists to be safe
        }
    }


    /**
     * Determine authentication method from the UserInfo type being used
     * This prevents ambiguity when OIDC and SAML providers have the same provider ID
     */
    private function determineAuthMethodFromUserInfo(UserInfoInterface $userInfo): string
    {
        if ($userInfo instanceof SamlUserInfo) {
            return self::AUTH_METHOD_SAML;
        }
        if ($userInfo instanceof OidcUserInfo) {
            return self::AUTH_METHOD_OIDC;
        }

        // Fallback to OIDC for backward compatibility with unknown types
        return self::AUTH_METHOD_OIDC;
    }

    /**
     * Get configuration settings based on auth method
     */
    private function getAuthMethodConfig(string $authMethod): array
    {
        return $this->configManager->getGroup($authMethod);
    }

    /**
     * Get the current auth_method for a user
     */
    private function getCurrentUserAuthMethod(int $userId): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT auth_method FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result['auth_method'] : null;
        } catch (\Exception $e) {
            $this->logError('Error getting current auth method for user {userId}: {error}', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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

            // Find orphaned OIDC links
            $stmt = $this->db->prepare("
                SELECT oul.id, oul.user_id, oul.provider_id, oul.username
                FROM oidc_user_links oul
                LEFT JOIN users u ON oul.user_id = u.id
                WHERE u.id IS NULL
            ");
            $stmt->execute();
            $orphanedLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($orphanedLinks)) {
                $this->logInfo('Found {count} orphaned external auth links to clean up', ['count' => count($orphanedLinks)]);

                foreach ($orphanedLinks as $link) {
                    $this->logInfo('Cleaning up orphaned link: user_id={user_id}, provider={provider}, username={username}', [
                        'user_id' => $link['user_id'],
                        'provider' => $link['provider_id'],
                        'username' => $link['username']
                    ]);
                }

                // Delete all orphaned links
                $stmt = $this->db->prepare("
                    DELETE FROM oidc_user_links
                    WHERE user_id NOT IN (SELECT id FROM users)
                ");
                $stmt->execute();
                $cleanupCount = $stmt->rowCount();

                $this->logInfo('Successfully cleaned up {count} orphaned external auth links', ['count' => $cleanupCount]);
            } else {
                $this->logInfo('No orphaned external auth links found');
            }

            return [
                'success' => true,
                'cleaned_up_count' => $cleanupCount,
                'orphaned_links' => $orphanedLinks
            ];
        } catch (\Exception $e) {
            $this->logError('Error cleaning up orphaned auth links: {error}', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'cleaned_up_count' => 0
            ];
        }
    }
}
