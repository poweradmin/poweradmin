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
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;

class DbRecordTypeDefaultRepository implements RecordTypeDefaultRepositoryInterface
{
    public function __construct(private PDO $db)
    {
    }

    public function find(string $recordType): ?int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT ttl FROM record_type_defaults WHERE UPPER(record_type) = :record_type"
            );
            $stmt->execute(['record_type' => strtoupper($recordType)]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return null;
            }
            return (int)$value;
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
                "SELECT record_type, ttl FROM record_type_defaults ORDER BY record_type"
            );
            $defaults = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $defaults[strtoupper($row['record_type'])] = (int)$row['ttl'];
            }
            return $defaults;
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    public function save(string $recordType, int $ttl): void
    {
        $type = strtoupper($recordType);
        try {
            if ($this->find($type) === null) {
                $stmt = $this->db->prepare(
                    "INSERT INTO record_type_defaults (record_type, ttl) VALUES (:record_type, :ttl)"
                );
                $stmt->bindValue(':record_type', $type);
                $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
                $stmt->execute();
                return;
            }
            $stmt = $this->db->prepare(
                "UPDATE record_type_defaults SET ttl = :ttl WHERE UPPER(record_type) = :record_type"
            );
            $stmt->bindValue(':record_type', $type);
            $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function delete(string $recordType): void
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM record_type_defaults WHERE UPPER(record_type) = :record_type"
            );
            $stmt->execute(['record_type' => strtoupper($recordType)]);
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
            $this->db->query("SELECT 1 FROM record_type_defaults LIMIT 1");
            return true;
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Lets read paths degrade gracefully on upgraded installations where the
     * PHP code has been deployed but `sql/*update-to-4.5.0.sql` hasn't been
     * applied yet. Covers MySQL 1146, PostgreSQL 42P01, SQLite "no such table".
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
