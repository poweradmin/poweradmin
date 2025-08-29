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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionClass;

class OidcUserProvisioningService extends LoggingService
{
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

    public function provisionUser(OidcUserInfo $userInfo, string $providerId): ?int
    {
        if (!$userInfo->isValid()) {
            $this->logWarning('Invalid OIDC user info provided for provisioning');
            return null;
        }

        try {
            // First, try to find existing user by OIDC subject
            $existingUserId = $this->findUserByOidcSubject($userInfo->getSubject(), $providerId);

            if ($existingUserId) {
                $this->logInfo('Found existing user by OIDC subject: {subject}', ['subject' => $userInfo->getSubject()]);
                $this->updateExistingUser($existingUserId, $userInfo);
                return $existingUserId;
            }

            // Try to find by email if email linking is enabled
            if ($this->configManager->get('oidc', 'link_by_email', true) && !empty($userInfo->getEmail())) {
                $existingUserId = $this->findUserByEmail($userInfo->getEmail());

                if ($existingUserId) {
                    $this->logInfo('Found existing user by email: {email}', ['email' => $userInfo->getEmail()]);
                    $this->linkOidcToExistingUser($existingUserId, $userInfo, $providerId);
                    $this->updateExistingUser($existingUserId, $userInfo);
                    return $existingUserId;
                }
            }

            // Create new user if auto-provisioning is enabled
            if ($this->configManager->get('oidc', 'auto_provision', true)) {
                return $this->createNewUser($userInfo, $providerId);
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
            $stmt = $this->db->prepare("
                SELECT user_id FROM oidc_user_links 
                WHERE oidc_subject = ? AND provider_id = ?
            ");
            $stmt->execute([$subject, $providerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['user_id'] : null;
        } catch (\Exception $e) {
            $this->logError('Error finding user by OIDC subject: {error}', ['error' => $e->getMessage()]);
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

    private function createNewUser(OidcUserInfo $userInfo, string $providerId): ?int
    {
        try {
            $this->logInfo('Creating new user from OIDC: {username}', ['username' => $userInfo->getUsername()]);

            // Determine permission template based on groups
            $permissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups());

            // Generate a unique username if needed
            $username = $this->ensureUniqueUsername($userInfo->getUsername());

            // Create user
            $stmt = $this->db->prepare("
                INSERT INTO users (username, password, fullname, email, description, active, perm_templ)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $success = $stmt->execute([
                $username,
                '', // No password for OIDC users
                $userInfo->getDisplayName() ?: $userInfo->getFullName(),
                $userInfo->getEmail(),
                'Created via OIDC from ' . $providerId,
                1, // Active
                $permissionTemplateId
            ]);

            if (!$success) {
                throw new \RuntimeException('Failed to insert user');
            }

            $userId = (int)$this->db->lastInsertId();

            // Link OIDC identity to user
            $this->linkOidcToExistingUser($userId, $userInfo, $providerId);

            $this->logInfo('Successfully created new user: {username} with ID: {id}', [
                'username' => $username,
                'id' => $userId
            ]);

            return $userId;
        } catch (\Exception $e) {
            $this->logError('Error creating new OIDC user: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function updateExistingUser(int $userId, OidcUserInfo $userInfo): void
    {
        try {
            $updateFields = [];
            $updateValues = [];

            // Update user information if configured to sync
            if ($this->configManager->get('oidc', 'sync_user_info', true)) {
                if (!empty($userInfo->getDisplayName())) {
                    $updateFields[] = 'fullname = ?';
                    $updateValues[] = $userInfo->getDisplayName();
                }

                if (!empty($userInfo->getEmail())) {
                    $updateFields[] = 'email = ?';
                    $updateValues[] = $userInfo->getEmail();
                }
            }

            // Update permission template based on current groups
            $newPermissionTemplateId = $this->determinePermissionTemplate($userInfo->getGroups());
            if ($newPermissionTemplateId) {
                $updateFields[] = 'perm_templ = ?';
                $updateValues[] = $newPermissionTemplateId;
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

    private function linkOidcToExistingUser(int $userId, OidcUserInfo $userInfo, string $providerId): void
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

            $this->logInfo('Linked OIDC identity to user ID: {id}', ['id' => $userId]);
        } catch (\Exception $e) {
            $this->logError('Error linking OIDC identity: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function determinePermissionTemplate(array $groups): ?int
    {
        $permissionTemplateMapping = $this->configManager->get('oidc', 'permission_template_mapping', []);

        // Check if user's OIDC groups match any configured mappings
        foreach ($permissionTemplateMapping as $groupName => $templateName) {
            if (in_array($groupName, $groups, true)) {
                $templateId = $this->findPermissionTemplateByName($templateName);
                if ($templateId) {
                    $this->logInfo('Mapped OIDC group {group} to permission template: {template}', [
                        'group' => $groupName,
                        'template' => $templateName
                    ]);
                    return $templateId;
                }
            }
        }

        // Fall back to default permission template
        $defaultTemplateName = $this->configManager->get('oidc', 'default_permission_template', 'Administrator');
        $defaultTemplateId = $this->findPermissionTemplateByName($defaultTemplateName);

        if ($defaultTemplateId) {
            $this->logInfo('Using default permission template: {template}', ['template' => $defaultTemplateName]);
            return $defaultTemplateId;
        }

        // Final fallback: find any available template (should not happen in normal operation)
        $this->logWarning('Default permission template not found, using first available template');
        return $this->findFirstAvailablePermissionTemplate();
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

    public function createOidcUserLinksTable(): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS oidc_user_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                provider_id VARCHAR(50) NOT NULL,
                oidc_subject VARCHAR(255) NOT NULL,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_provider (user_id, provider_id),
                UNIQUE KEY unique_subject_provider (oidc_subject, provider_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";

            $this->db->exec($sql);
            $this->logInfo('Created or verified oidc_user_links table');
        } catch (\Exception $e) {
            $this->logError('Error creating oidc_user_links table: {error}', ['error' => $e->getMessage()]);
        }
    }
}
