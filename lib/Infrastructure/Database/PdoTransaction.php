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

namespace Poweradmin\Infrastructure\Database;

use PDO;
use WeakMap;
use Poweradmin\Domain\Port\TransactionInterface;

/**
 * Transaction control over the request's PDO handle, the one the repositories write through.
 */
final class PdoTransaction implements TransactionInterface
{
    /**
     * Handles on which this class opened a transaction PDO cannot see.
     *
     * Keyed by connection rather than held per instance: several services wrap
     * the same handle in their own PdoTransaction, and they all have to agree
     * about whether a transaction is already open.
     */
    private static ?WeakMap $rawTransactions = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * SQLite gets BEGIN IMMEDIATE. PDO issues a deferred BEGIN, which takes no
     * write lock, so the first write has to upgrade from a read; SQLite refuses
     * an upgrade that could deadlock and returns "database is locked" at once,
     * without waiting for busy_timeout. Taking the write lock at BEGIN is the
     * one form SQLite will wait for, so a competing writer queues than fails.
     */
    public function begin(): void
    {
        if ($this->isSqlite() && !$this->inTransaction()) {
            $this->db->exec('BEGIN IMMEDIATE');
            self::tracker()[$this->db] = true;

            return;
        }

        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->ownsRawTransaction()) {
            $this->db->exec('COMMIT');
            unset(self::tracker()[$this->db]);

            return;
        }

        $this->db->commit();
    }

    public function rollBack(): void
    {
        if ($this->ownsRawTransaction()) {
            $this->db->exec('ROLLBACK');
            unset(self::tracker()[$this->db]);

            return;
        }

        $this->db->rollBack();
    }

    /**
     * PDO cannot see a transaction opened with a raw statement, so both are
     * reported. Everything that decides whether to open its own transaction
     * must ask this rather than PDO directly.
     */
    public function inTransaction(): bool
    {
        return $this->ownsRawTransaction() || $this->db->inTransaction();
    }

    private function ownsRawTransaction(): bool
    {
        return self::tracker()[$this->db] ?? false;
    }

    private function isSqlite(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /** @return WeakMap<PDO, bool> */
    private static function tracker(): WeakMap
    {
        return self::$rawTransactions ??= new WeakMap();
    }
}
