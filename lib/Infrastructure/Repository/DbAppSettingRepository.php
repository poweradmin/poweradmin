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
use PDOException;
use Poweradmin\Domain\Repository\AppSettingRepositoryInterface;

class DbAppSettingRepository implements AppSettingRepositoryInterface
{
    public function __construct(private PDO $db)
    {
    }

    public function find(string $key): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value, value_type FROM app_settings WHERE setting_key = :setting_key"
            );
            $stmt->execute(['setting_key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            return ['value' => (string)$row['setting_value'], 'type' => (string)$row['value_type']];
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return null;
            }
            throw $e;
        }
    }

    public function findAll(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT setting_key, setting_value, value_type FROM app_settings ORDER BY setting_key"
            );
            $result = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[(string)$row['setting_key']] = [
                    'value' => (string)$row['setting_value'],
                    'type' => (string)$row['value_type'],
                ];
            }
            return $result;
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    public function findByPrefix(string $prefix): array
    {
        try {
            // SUBSTR-based match keeps lookups case-sensitive and wildcard-safe on
            // every supported backend; SQLite's LIKE is ASCII case-insensitive by
            // default and PostgreSQL still expects `_` to be a wildcard.
            $stmt = $this->db->prepare(
                "SELECT setting_key, setting_value, value_type FROM app_settings "
                . "WHERE SUBSTR(setting_key, 1, :prefix_len) = :prefix ORDER BY setting_key"
            );
            $stmt->bindValue(':prefix', $prefix);
            $stmt->bindValue(':prefix_len', strlen($prefix), PDO::PARAM_INT);
            $stmt->execute();
            $result = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[(string)$row['setting_key']] = [
                    'value' => (string)$row['setting_value'],
                    'type' => (string)$row['value_type'],
                ];
            }
            return $result;
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    public function save(string $key, string $value, string $type = 'string'): void
    {
        try {
            if ($this->find($key) === null) {
                $stmt = $this->db->prepare(
                    "INSERT INTO app_settings (setting_key, setting_value, value_type) "
                    . "VALUES (:setting_key, :setting_value, :value_type)"
                );
                $stmt->execute([
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'value_type' => $type,
                ]);
                return;
            }
            // Explicit updated_at bump because PostgreSQL/SQLite don't have an
            // ON UPDATE clause on the schema; MySQL's auto-update is harmless here.
            $stmt = $this->db->prepare(
                "UPDATE app_settings SET setting_value = :setting_value, "
                . "value_type = :value_type, updated_at = CURRENT_TIMESTAMP "
                . "WHERE setting_key = :setting_key"
            );
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'value_type' => $type,
            ]);
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function delete(string $key): void
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM app_settings WHERE setting_key = :setting_key"
            );
            $stmt->execute(['setting_key' => $key]);
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function isReady(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM app_settings LIMIT 1");
            return true;
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Lets read paths degrade gracefully on installations where the schema
     * update hasn't been applied yet.
     */
    private function isMissingTable(PDOException $e): bool
    {
        $code = (string)$e->getCode();
        if ($code === '42S02' || $code === '42P01') {
            return true;
        }
        $message = strtolower($e->getMessage());
        return str_contains($message, 'no such table')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'does not exist')
            || str_contains($message, 'undefined table');
    }

}
