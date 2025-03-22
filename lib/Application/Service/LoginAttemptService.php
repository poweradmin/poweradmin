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
use Poweradmin\Infrastructure\Configuration\SecurityPolicyConfig;
use Poweradmin\Infrastructure\Database\PDOLayer;

class LoginAttemptService
{
    private SecurityPolicyConfig $securityPolicy;
    private PDOLayer $db;

    public function __construct(PDOLayer $db, SecurityPolicyConfig $securityPolicy)
    {
        $this->db = $db;
        $this->securityPolicy = $securityPolicy;
    }

    public function recordAttempt(string $username, string $ipAddress, bool $successful): void
    {
        if (!$this->securityPolicy->get('enable_lockout')) {
            return;
        }

        $userId = $this->getUserId($username);

        $stmt = $this->db->prepare("
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
        if (!$this->securityPolicy->get('enable_lockout')) {
            return false;
        }

        $userId = $this->getUserId($username);
        if ($userId === null) {
            return false;
        }

        $lockoutDuration = $this->securityPolicy->get('lockout_duration') * 60;
        $cutoffTime = time() - $lockoutDuration;
        $maxAttempts = $this->securityPolicy->get('lockout_attempts');

        $sql = "SELECT COUNT(*) as attempts 
                FROM login_attempts
                WHERE user_id = :user_id 
                AND successful = 0
                AND timestamp > :cutoff_time";

        if ($this->securityPolicy->get('track_ip_address')) {
            $sql .= " AND ip_address = :ip_address";
        }

        $stmt = $this->db->prepare($sql);
        $params = [
            'user_id' => $userId,
            'cutoff_time' => $cutoffTime
        ];

        if ($this->securityPolicy->get('track_ip_address')) {
            $params['ip_address'] = $ipAddress;
        }

        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['attempts'] >= $maxAttempts;
    }

    private function getUserId(string $username): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE username = :username
        ");

        $stmt->execute(['username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    private function cleanupOldAttempts(): void
    {
        $lockoutDuration = $this->securityPolicy->get('lockout_duration') * 60;
        $cutoffTime = time() - $lockoutDuration;

        $stmt = $this->db->prepare("
            DELETE FROM login_attempts
            WHERE timestamp < :cutoff_time
        ");

        $stmt->execute(['cutoff_time' => $cutoffTime]);
    }
}