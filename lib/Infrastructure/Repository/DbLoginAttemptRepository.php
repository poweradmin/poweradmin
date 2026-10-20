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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Domain\Repository\LoginAttemptRepositoryInterface;

/**
 * SQL persistence for the authentication throttle counters in login_attempts.
 */
final class DbLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    private PDO $connection;
    private ConfigurationInterface $config;

    // Cached per-connection so the introspection cost is paid once per request.
    // Lets login keep working in the upgrade window between code deploy and the
    // 4.5.0 SQL update (when `attempt_type` does not exist yet).
    private ?bool $attemptTypeColumnExists = null;

    public function __construct(PDO $connection, ConfigurationInterface $config)
    {
        $this->connection = $connection;
        $this->config = $config;
    }

    public function hasAttemptTypeColumn(): bool
    {
        if ($this->attemptTypeColumnExists !== null) {
            return $this->attemptTypeColumnExists;
        }

        try {
            $this->connection->query("SELECT attempt_type FROM login_attempts WHERE 1 = 0");
            $this->attemptTypeColumnExists = true;
        } catch (\PDOException) {
            $this->attemptTypeColumnExists = false;
        }

        return $this->attemptTypeColumnExists;
    }

    public function findUserIdByUsername(string $username): ?int
    {
        $stmt = $this->connection->prepare("
            SELECT id FROM users
            WHERE username = :username
        ");

        $stmt->execute(['username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    public function record(?int $userId, string $ipAddress, int $timestamp, bool $successful, ?string $attemptType): void
    {
        $columns = 'user_id, ip_address, timestamp, successful';
        $placeholders = ':user_id, :ip_address, :timestamp, :successful';
        $params = [
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'timestamp' => $timestamp,
            'successful' => DbCompat::boolValue($successful),
        ];

        if ($attemptType !== null) {
            $columns .= ', attempt_type';
            $placeholders .= ', :attempt_type';
            $params['attempt_type'] = $attemptType;
        }

        $stmt = $this->connection->prepare(
            "INSERT INTO login_attempts ($columns) VALUES ($placeholders)"
        );
        $stmt->execute($params);
    }

    public function countFailedAttempts(int $userId, int $cutoffTime, ?string $attemptType, ?string $ipAddress): int
    {
        $db_type = $this->config->get('database', 'type');
        $sql = "SELECT COUNT(*) as attempts
            FROM login_attempts
            WHERE user_id = :user_id
            AND successful = " . DbCompat::boolFalse($db_type) . "
            AND timestamp > :cutoff_time";

        $params = [
            'user_id' => $userId,
            'cutoff_time' => $cutoffTime
        ];

        if ($attemptType !== null) {
            $sql .= " AND attempt_type = :attempt_type";
            $params['attempt_type'] = $attemptType;
        }

        if ($ipAddress !== null) {
            $sql .= " AND ip_address = :ip_address";
            $params['ip_address'] = $ipAddress;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['attempts'];
    }

    public function clearFailedAttempts(int $userId, ?string $attemptType, ?string $ipAddress): void
    {
        $sql = "DELETE FROM login_attempts WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($attemptType !== null) {
            $sql .= " AND attempt_type = :attempt_type";
            $params['attempt_type'] = $attemptType;
        }

        if ($ipAddress !== null) {
            $sql .= " AND ip_address = :ip_address";
            $params['ip_address'] = $ipAddress;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
    }

    public function deleteOlderThan(int $cutoffTime): void
    {
        $stmt = $this->connection->prepare("
            DELETE FROM login_attempts
            WHERE timestamp < :cutoff_time
        ");

        $stmt->execute(['cutoff_time' => $cutoffTime]);
    }
}
