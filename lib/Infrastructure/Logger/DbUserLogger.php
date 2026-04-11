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

namespace Poweradmin\Infrastructure\Logger;

use PDO;
use Poweradmin\Domain\Model\UserEntity;

class DbUserLogger
{
    private PDO $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function doLog($msg, $priority): void
    {
        $stmt = $this->db->prepare('INSERT INTO log_users (event, priority) VALUES (:msg, :priority)');
        $stmt->execute([
            ':msg' => $msg,
            ':priority' => $priority,
        ]);
    }

    public function countAllLogs()
    {
        $stmt = $this->db->query("SELECT count(*) AS number_of_logs FROM log_users");
        return $stmt->fetch()['number_of_logs'];
    }

    public function countLogsByUser($user)
    {
        $stmt = $this->db->prepare("
                    SELECT count(log_users.id) as number_of_logs
                    FROM log_users
                    WHERE log_users.event LIKE :search_by
        ");
        $name = "%'$user'%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public function getAllLogs($limit, $offset): array
    {
        $stmt = $this->db->prepare("
                    SELECT * FROM log_users
                    ORDER BY created_at DESC
                    LIMIT :limit
                    OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getLogsForUser($user, $limit, $offset): array
    {
        if (!(UserEntity::exists($this->db, $user))) {
            return array();
        }

        $stmt = $this->db->prepare("
            SELECT * FROM log_users
            WHERE log_users.event LIKE :search_by
            ORDER BY created_at DESC
            LIMIT :limit
            OFFSET :offset");

        $user = "%'$user'%";
        $stmt->bindValue(':search_by', $user, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDistinctEventTypes(): array
    {
        return [
            'access_denied',
            'add_user',
            'change_password',
            'delete_user',
            'edit_user',
            'login_failed',
            'login_success',
            'logout',
            'mfa_disable',
            'mfa_enable',
            'mfa_regenerate_codes',
            'mfa_verify',
            'oidc_login_failed',
            'oidc_login_success',
            'password_reset',
            'password_reset_request',
            'perm_template_change',
            'saml_login_failed',
            'saml_login_success',
            'saml_logout',
            'session_expired',
            'username_recovery',
        ];
    }

    public function getDistinctUsers(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT username FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countFilteredLogs(array $filters): int
    {
        $query = "SELECT COUNT(*) AS number_of_logs FROM log_users";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $conditions, $params);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->execute();
        return (int) $stmt->fetch()['number_of_logs'];
    }

    public function getFilteredLogs(array $filters, int $limit, int $offset): array
    {
        $query = "SELECT * FROM log_users";
        $conditions = [];
        $params = [];

        $this->buildFilterConditions($filters, $conditions, $params);

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function buildFilterConditions(array $filters, array &$conditions, array &$params): void
    {
        if (!empty($filters['name'])) {
            $conditions[] = "log_users.event LIKE :search_by";
            $params[':search_by'] = ["%'" . $filters['name'] . "'%", PDO::PARAM_STR];
        }

        if (!empty($filters['event_type'])) {
            $typePatterns = [
                'access_denied' => '%operation:access_denied%',
                'login_success' => '%operation:login_success%',
                'login_failed' => '%operation:login_failed%',
                'add_user' => '%operation:add_user%',
                'edit_user' => '%operation:edit_user%',
                'delete_user' => '%operation:delete_user%',
                'change_password' => '%operation:change_password%',
                'logout' => '%operation:logout%',
                'mfa_enable' => '%operation:mfa_enable%',
                'mfa_disable' => '%operation:mfa_disable%',
                'mfa_verify' => '%operation:mfa_verify%',
                'mfa_regenerate_codes' => '%operation:mfa_regenerate_codes%',
                'password_reset_request' => '%operation:password_reset_request%',
                'password_reset' => '%operation:password_reset%',
                'perm_template_change' => '%operation:perm_template_change%',
                'username_recovery' => '%operation:username_recovery%',
                'oidc_login_success' => '%operation:oidc_login_success%',
                'oidc_login_failed' => '%operation:oidc_login_failed%',
                'saml_login_success' => '%operation:saml_login_success%',
                'saml_login_failed' => '%operation:saml_login_failed%',
                'saml_logout' => '%operation:saml_logout%',
                'session_expired' => '%operation:session_expired%',
            ];
            if (isset($typePatterns[$filters['event_type']])) {
                $conditions[] = "log_users.event LIKE :event_type";
                $params[':event_type'] = [$typePatterns[$filters['event_type']], PDO::PARAM_STR];
            }
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "log_users.created_at >= :date_from";
            $params[':date_from'] = [$filters['date_from'] . " 00:00:00", PDO::PARAM_STR];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "log_users.created_at <= :date_to";
            $params[':date_to'] = [$filters['date_to'] . " 23:59:59", PDO::PARAM_STR];
        }
    }
}
