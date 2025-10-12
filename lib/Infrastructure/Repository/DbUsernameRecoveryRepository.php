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

class DbUsernameRecoveryRepository
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Create a new username recovery request record
     *
     * @param array $data Request data containing email and ip_address
     * @return bool True if the record was created successfully
     */
    public function create(array $data): bool
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "INSERT INTO username_recovery_requests (email, ip_address, created_at)
                VALUES (:email, :ip_address, " . DbCompat::now($db_type) . ")";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':email' => $data['email'],
            ':ip_address' => $data['ip_address']
        ]);
    }

    /**
     * Count recent recovery attempts for a specific email address
     *
     * @param string $email Email address to check
     * @param int $seconds Time window in seconds to check within
     * @return int Number of attempts in the time window
     */
    public function countRecentAttempts(string $email, int $seconds): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT COUNT(*)
                FROM username_recovery_requests
                WHERE email = :email
                AND created_at > " . DbCompat::dateSubtract($db_type, $seconds);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count recent recovery attempts by IP address
     *
     * @param string $ip IP address to check
     * @param int $seconds Time window in seconds to check within
     * @return int Number of attempts from this IP in the time window
     */
    public function countRecentAttemptsByIp(string $ip, int $seconds): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT COUNT(*)
                FROM username_recovery_requests
                WHERE ip_address = :ip
                AND created_at > " . DbCompat::dateSubtract($db_type, $seconds);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':ip' => $ip]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get the timestamp of the last recovery attempt for an email
     *
     * @param string $email Email address to check
     * @return string|null Timestamp of last attempt, or null if no attempts found
     */
    public function getLastAttemptTime(string $email): ?string
    {
        $sql = "SELECT created_at
                FROM username_recovery_requests
                WHERE email = :email
                ORDER BY created_at DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Delete old recovery request records
     *
     * Cleanup strategy:
     * - Delete records older than the specified number of days
     * - Default: 30 days for audit trail purposes
     *
     * This method can be called periodically for maintenance.
     *
     * @param int $days Number of days to keep records (default: 30)
     * @return int Number of deleted records
     */
    public function deleteOlderThan(int $days = 30): int
    {
        $db_type = $this->config->get('database', 'type');
        $seconds = $days * 86400; // Convert days to seconds

        $sql = "DELETE FROM username_recovery_requests
                WHERE created_at < " . DbCompat::dateSubtract($db_type, $seconds);

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Delete all recovery requests for a specific email
     *
     * @param string $email Email address
     * @return int Number of deleted records
     */
    public function deleteByEmail(string $email): int
    {
        $sql = "DELETE FROM username_recovery_requests WHERE email = :email";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        return $stmt->rowCount();
    }

    /**
     * Get total count of recovery requests in the system
     *
     * @return int Total number of records
     */
    public function getTotalCount(): int
    {
        $sql = "SELECT COUNT(*) FROM username_recovery_requests";
        $stmt = $this->db->query($sql);
        return (int) $stmt->fetchColumn();
    }
}
