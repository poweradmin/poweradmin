<?php

namespace Poweradmin\Infrastructure\Repository;

use Poweradmin\Infrastructure\Database\PDOCommon;
use PDO;

class DbPasswordResetTokenRepository
{
    private PDOCommon $db;

    public function __construct(PDOCommon $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new password reset token
     */
    public function create(array $data): bool
    {
        $sql = "INSERT INTO password_reset_tokens (email, token, created_at, expires_at, ip_address) 
                VALUES (:email, :token, NOW(), :expires_at, :ip_address)";

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
        $sql = "SELECT * FROM password_reset_tokens 
                WHERE expires_at > NOW() 
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
        $sql = "SELECT * FROM password_reset_tokens 
                WHERE token = :token 
                AND expires_at > NOW() 
                AND used = 0 
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
        $sql = "UPDATE password_reset_tokens 
                SET used = 1 
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $tokenId]);
    }

    /**
     * Count recent attempts for an email address
     */
    public function countRecentAttempts(string $email, int $seconds): int
    {
        $sql = "SELECT COUNT(*) 
                FROM password_reset_tokens 
                WHERE email = :email 
                AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':seconds' => $seconds
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count recent attempts by IP address
     */
    public function countRecentAttemptsByIp(string $ip, int $seconds): int
    {
        $sql = "SELECT COUNT(*) 
                FROM password_reset_tokens 
                WHERE ip_address = :ip 
                AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':ip' => $ip,
            ':seconds' => $seconds
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
        $sql = "DELETE FROM password_reset_tokens 
                WHERE expires_at < NOW() 
                OR (used = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";

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
