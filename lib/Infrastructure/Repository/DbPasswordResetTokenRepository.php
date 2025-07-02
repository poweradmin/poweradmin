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

use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;

class DbPasswordResetTokenRepository
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Create a new password reset token
     */
    public function create(array $data): bool
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "INSERT INTO password_reset_tokens (email, token, created_at, expires_at, ip_address) 
                VALUES (:email, :token, " . DbCompat::now($db_type) . ", :expires_at, :ip_address)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':email' => $data['email'],
            ':token' => $data['token'],
            ':expires_at' => $data['expires_at'],
            ':ip_address' => $data['ip_address'] ?? null
        ]);
    }

    /**
     * Find all active (non-expired) tokens
     */
    public function findActiveTokens(): array
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT * FROM password_reset_tokens 
                WHERE expires_at > " . DbCompat::now($db_type) . " 
                ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a token by its value
     */
    public function findByToken(string $token): ?array
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT * FROM password_reset_tokens 
                WHERE token = :token 
                AND expires_at > " . DbCompat::now($db_type) . " 
                AND used = " . DbCompat::boolFalse($db_type) . " 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token' => $token]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Mark a token as used
     */
    public function markAsUsed(int $tokenId): bool
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "UPDATE password_reset_tokens 
                SET used = " . DbCompat::boolTrue($db_type) . " 
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $tokenId]);
    }

    /**
     * Count recent attempts for an email address
     */
    public function countRecentAttempts(string $email, int $seconds): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT COUNT(*) 
                FROM password_reset_tokens 
                WHERE email = :email 
                AND created_at > " . DbCompat::dateSubtract($db_type, $seconds);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count recent attempts by IP address
     */
    public function countRecentAttemptsByIp(string $ip, int $seconds): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT COUNT(*) 
                FROM password_reset_tokens 
                WHERE ip_address = :ip 
                AND created_at > " . DbCompat::dateSubtract($db_type, $seconds);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':ip' => $ip
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get the last attempt time for an email
     */
    public function getLastAttemptTime(string $email): ?string
    {
        $sql = "SELECT created_at 
                FROM password_reset_tokens 
                WHERE email = :email 
                ORDER BY created_at DESC 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Delete expired tokens
     *
     * Current cleanup strategy:
     * - Delete tokens that have passed their expiration time
     * - Delete used tokens that are older than 7 days (kept temporarily for audit trail)
     *
     * This method is called automatically:
     * - When creating new password reset requests
     * - When validating tokens
     * - After successful password resets
     */
    public function deleteExpired(): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "DELETE FROM password_reset_tokens 
                WHERE expires_at < " . DbCompat::now($db_type) . " 
                OR (used = " . DbCompat::boolTrue($db_type) . " AND created_at < " . DbCompat::dateSubtract($db_type, 604800) . ")";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Delete all tokens for a specific email
     */
    public function deleteByEmail(string $email): int
    {
        $sql = "DELETE FROM password_reset_tokens WHERE email = :email";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        return $stmt->rowCount();
    }

    /**
     * Delete a specific token by ID
     */
    public function deleteById(int $tokenId): bool
    {
        $sql = "DELETE FROM password_reset_tokens WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $tokenId]);
    }
}
