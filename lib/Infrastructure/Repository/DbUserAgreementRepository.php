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

use Poweradmin\Domain\Repository\UserAgreementRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DbUserAgreementRepository implements UserAgreementRepositoryInterface
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function hasUserAcceptedAgreement(int $userId, string $version): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM user_agreements 
             WHERE user_id = :user_id 
             AND agreement_version = :version"
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':version' => $version
        ]);

        return $stmt->fetchColumn() > 0;
    }

    public function recordAcceptance(
        int $userId,
        string $version,
        string $ipAddress,
        string $userAgent
    ): bool {
        $db_type = $this->config->get('database', 'type');

        // Try to update existing record first
        $updateStmt = $this->db->prepare(
            "UPDATE user_agreements 
             SET accepted_at = " . DbCompat::now($db_type) . ",
                 ip_address = :ip_address,
                 user_agent = :user_agent
             WHERE user_id = :user_id AND agreement_version = :version"
        );

        $updateStmt->execute([
            ':user_id' => $userId,
            ':version' => $version,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);

        // If no rows were affected, insert new record
        if ($updateStmt->rowCount() === 0) {
            $insertStmt = $this->db->prepare(
                "INSERT INTO user_agreements 
                 (user_id, agreement_version, ip_address, user_agent) 
                 VALUES (:user_id, :version, :ip_address, :user_agent)"
            );

            return $insertStmt->execute([
                ':user_id' => $userId,
                ':version' => $version,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent
            ]);
        }

        return true;
    }

    public function getUserAgreements(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_agreements 
             WHERE user_id = :user_id 
             ORDER BY accepted_at DESC"
        );

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getAllAgreements(): array
    {
        $stmt = $this->db->prepare(
            "SELECT ua.*, u.username, u.fullname 
             FROM user_agreements ua 
             JOIN users u ON ua.user_id = u.id 
             ORDER BY ua.accepted_at DESC"
        );

        $stmt->execute();
        return $stmt->fetchAll();
    }
}
