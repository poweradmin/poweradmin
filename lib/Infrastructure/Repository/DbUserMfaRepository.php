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

use DateTime;
use PDO;
use PDOException;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DbUserMfaRepository implements UserMfaRepositoryInterface
{
    private PDOCommon $db;

    public function __construct(PDOCommon $db)
    {
        $this->db = $db;
    }

    public function findByUserId(int $userId): ?UserMfa
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, enabled, secret, recovery_codes, type, last_used_at, created_at, updated_at, verification_data
                FROM user_mfa
                WHERE user_id = :user_id
            ");

            $stmt->execute(['user_id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            return $this->hydrateUserMfa($result);
        } catch (PDOException $e) {
            // Log the error and rethrow it to be handled by the calling code
            error_log("DbUserMfaRepository::findByUserId failed: " . $e->getMessage());

            // Only return null for "table not found" error, otherwise rethrow
            if (strpos($e->getMessage(), "Base table or view not found") !== false) {
                return null;
            }

            throw $e;
        }
    }

    public function findById(int $id): ?UserMfa
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, enabled, secret, recovery_codes, type, last_used_at, created_at, updated_at, verification_data
                FROM user_mfa
                WHERE id = :id
            ");

            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            return $this->hydrateUserMfa($result);
        } catch (PDOException $e) {
            // Log the error and rethrow it to be handled by the calling code
            error_log("DbUserMfaRepository::findById failed: " . $e->getMessage());

            // Only return null for "table not found" error, otherwise rethrow
            if (strpos($e->getMessage(), "Base table or view not found") !== false) {
                return null;
            }

            throw $e;
        }
    }

    public function save(UserMfa $userMfa): UserMfa
    {
        try {
            if ($userMfa->getId() === 0) {
                return $this->insert($userMfa);
            }

            return $this->update($userMfa);
        } catch (PDOException $e) {
            // Log the error
            error_log("DbUserMfaRepository::save failed: " . $e->getMessage());

            // If the table doesn't exist, we want to fail gracefully
            if (strpos($e->getMessage(), "Base table or view not found") !== false) {
                error_log("MFA table not found, operations will be skipped");
                return $userMfa;
            }

            // Check for duplicate key error (integrity constraint violation)
            if (
                strpos($e->getMessage(), "Integrity constraint violation") !== false &&
                strpos($e->getMessage(), "idx_user_mfa_user_id") !== false
            ) {
                error_log("Duplicate user_id in user_mfa table, retrieving existing record");

                // Try to get the existing record
                $existingUserMfa = $this->findByUserId($userMfa->getUserId());
                if ($existingUserMfa) {
                    return $existingUserMfa;
                }
            }

            throw $e;
        }
    }

    public function delete(UserMfa $userMfa): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM user_mfa
            WHERE id = :id
        ");

        return $stmt->execute(['id' => $userMfa->getId()]);
    }

    public function findAllEnabled(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, enabled, secret, recovery_codes, type, last_used_at, created_at, updated_at
            FROM user_mfa
            WHERE enabled = 1
        ");

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userMfas = [];
        foreach ($results as $result) {
            $userMfas[] = $this->hydrateUserMfa($result);
        }

        return $userMfas;
    }

    private function insert(UserMfa $userMfa): UserMfa
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_mfa (
                user_id, enabled, secret, recovery_codes, type, last_used_at, created_at, updated_at, verification_data
            ) VALUES (
                :user_id, :enabled, :secret, :recovery_codes, :type, :last_used_at, :created_at, :updated_at, :verification_data
            )
        ");

        $params = [
            'user_id' => $userMfa->getUserId(),
            'enabled' => $userMfa->isEnabled() ? 1 : 0,
            'secret' => $userMfa->getSecret(),
            'recovery_codes' => $userMfa->getRecoveryCodes(),
            'type' => $userMfa->getType(),
            'last_used_at' => $userMfa->getLastUsedAt() ? $userMfa->getLastUsedAt()->format('Y-m-d H:i:s') : null,
            'created_at' => $userMfa->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $userMfa->getUpdatedAt() ? $userMfa->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            'verification_data' => $userMfa->getVerificationData(),
        ];

        $stmt->execute($params);
        $id = (int) $this->db->lastInsertId();

        return $this->findById($id);
    }

    private function update(UserMfa $userMfa): UserMfa
    {
        $stmt = $this->db->prepare("
            UPDATE user_mfa
            SET 
                enabled = :enabled,
                secret = :secret,
                recovery_codes = :recovery_codes,
                type = :type,
                last_used_at = :last_used_at,
                updated_at = :updated_at,
                verification_data = :verification_data
            WHERE id = :id
        ");

        $params = [
            'id' => $userMfa->getId(),
            'enabled' => $userMfa->isEnabled() ? 1 : 0,
            'secret' => $userMfa->getSecret(),
            'recovery_codes' => $userMfa->getRecoveryCodes(),
            'type' => $userMfa->getType(),
            'last_used_at' => $userMfa->getLastUsedAt() ? $userMfa->getLastUsedAt()->format('Y-m-d H:i:s') : null,
            'updated_at' => $userMfa->getUpdatedAt() ? $userMfa->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            'verification_data' => $userMfa->getVerificationData(),
        ];

        $stmt->execute($params);

        return $userMfa;
    }

    private function hydrateUserMfa(array $data): UserMfa
    {
        return new UserMfa(
            (int) $data['id'],
            (int) $data['user_id'],
            (bool) $data['enabled'],
            $data['secret'],
            $data['recovery_codes'],
            $data['type'],
            $data['last_used_at'] ? new DateTime($data['last_used_at']) : null,
            new DateTime($data['created_at']),
            $data['updated_at'] ? new DateTime($data['updated_at']) : null,
            $data['verification_data'] ?? null
        );
    }
}
