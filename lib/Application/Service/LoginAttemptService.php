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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

class LoginAttemptService
{
    private ConfigurationManager $configManager;
    private PDOLayer $connection;

    public function __construct(PDOLayer $connection, ConfigurationManager $configManager = null)
    {
        $this->connection = $connection;
        $this->configManager = $configManager ?? ConfigurationManager::getInstance();
    }

    public function recordAttempt(string $username, string $ipAddress, bool $successful): void
    {
        if (!$this->configManager->get('security', 'account_lockout.enable_lockout', false)) {
            return;
        }

        $userId = $this->getUserId($username);

        if ($successful && $this->configManager->get('security', 'account_lockout.clear_attempts_on_success', true)) {
            $this->clearFailedAttempts($userId, $ipAddress);
        }

        $stmt = $this->connection->prepare("
        INSERT INTO login_attempts (user_id, ip_address, timestamp, successful)
        VALUES (:user_id, :ip_address, :timestamp, :successful)
    ");

        $stmt->execute([
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'timestamp' => time(),
            'successful' => $successful ? 1 : 0
        ]);

        $this->cleanupOldAttempts();
    }

    public function isAccountLocked(string $username, string $ipAddress): bool
    {
        if (!$this->configManager->get('security', 'account_lockout.enable_lockout', false)) {
            return false;
        }

        $userId = $this->getUserId($username);
        if ($userId === null) {
            return false;
        }

        $lockoutDuration = $this->configManager->get('security', 'account_lockout.lockout_duration', 15) * 60;
        $cutoffTime = time() - $lockoutDuration;
        $maxAttempts = $this->configManager->get('security', 'account_lockout.lockout_attempts', 5);
        $trackIpAddress = $this->configManager->get('security', 'account_lockout.track_ip_address', true);

        $sql = "SELECT COUNT(*) as attempts
            FROM login_attempts
            WHERE user_id = :user_id
            AND successful = 0
            AND timestamp > :cutoff_time";

        $params = [
            'user_id' => $userId,
            'cutoff_time' => $cutoffTime
        ];

        if ($trackIpAddress) {
            $sql .= " AND ip_address = :ip_address";
            $params['ip_address'] = $ipAddress;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['attempts'] >= $maxAttempts;
    }

    private function getUserId(string $username): ?int
    {
        $stmt = $this->connection->prepare("
            SELECT id FROM users 
            WHERE username = :username
        ");

        $stmt->execute(['username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    private function cleanupOldAttempts(): void
    {
        $lockoutDuration = $this->configManager->get('security', 'account_lockout.lockout_duration', 15) * 60;
        $cutoffTime = time() - $lockoutDuration;

        $stmt = $this->connection->prepare("
            DELETE FROM login_attempts
            WHERE timestamp < :cutoff_time
        ");

        $stmt->execute(['cutoff_time' => $cutoffTime]);
    }

    private function clearFailedAttempts(?int $userId, string $ipAddress): void
    {
        if ($userId === null) {
            return;
        }

        $sql = "DELETE FROM login_attempts WHERE user_id = :user_id";

        $params = ['user_id' => $userId];

        if ($this->configManager->get('security', 'account_lockout.track_ip_address', true)) {
            $sql .= " AND ip_address = :ip_address";
            $params['ip_address'] = $ipAddress;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
    }
}