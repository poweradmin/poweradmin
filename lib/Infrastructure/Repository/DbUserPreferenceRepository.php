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

use PDO;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Repository\UserPreferenceRepositoryInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DbUserPreferenceRepository implements UserPreferenceRepositoryInterface
{
    private PDOCommon $db;
    private string $db_type;

    public function __construct(PDOCommon $db, string $db_type)
    {
        $this->db = $db;
        $this->db_type = $db_type;
    }

    public function findByUserIdAndKey(int $userId, string $key): ?UserPreference
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_preferences WHERE user_id = :user_id AND preference_key = :key"
        );
        $stmt->execute(['user_id' => $userId, 'key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydratePreference($row);
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_preferences WHERE user_id = :user_id ORDER BY preference_key"
        );
        $stmt->execute(['user_id' => $userId]);

        $preferences = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $preferences[] = $this->hydratePreference($row);
        }

        return $preferences;
    }

    public function save(UserPreference $preference): void
    {
        $stmt = $this->db->prepare(
            "UPDATE user_preferences SET preference_value = :value 
             WHERE id = :id"
        );
        $stmt->execute([
            'value' => $preference->getPreferenceValue(),
            'id' => $preference->getId()
        ]);
    }

    public function createOrUpdate(int $userId, string $key, ?string $value): void
    {
        $existing = $this->findByUserIdAndKey($userId, $key);

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE user_preferences SET preference_value = :value 
                 WHERE user_id = :user_id AND preference_key = :key"
            );
            $stmt->execute([
                'value' => $value,
                'user_id' => $userId,
                'key' => $key
            ]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO user_preferences (user_id, preference_key, preference_value) 
                 VALUES (:user_id, :key, :value)"
            );
            $stmt->execute([
                'user_id' => $userId,
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    public function deleteByUserIdAndKey(int $userId, string $key): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_preferences WHERE user_id = :user_id AND preference_key = :key"
        );
        $stmt->execute(['user_id' => $userId, 'key' => $key]);
    }

    public function deleteAllByUserId(int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM user_preferences WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    }

    private function hydratePreference(array $row): UserPreference
    {
        return new UserPreference(
            (int)$row['id'],
            (int)$row['user_id'],
            $row['preference_key'],
            $row['preference_value']
        );
    }
}
