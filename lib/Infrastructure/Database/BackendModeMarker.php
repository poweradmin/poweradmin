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
use PDOException;
use Poweradmin\Domain\Database\DbCompat;

/**
 * Records that a database has been used with the API backend, so the SQL backend can refuse it.
 *
 * API mode keys zones rows by canonical zone ids, which the SQL backend would read as PowerDNS
 * domains.id: owners, group grants, API key scopes and logs would land on other zones. API mode
 * sets the marker where it writes zones rows; a database without a marker, used before 4.6.0,
 * is classified once from its zones rows.
 */
final class BackendModeMarker
{
    public const MARKER = 'backend.zone_ids';
    public const API = 'api';
    public const SQL = 'sql';

    public const SQL_MODE_REFUSAL = 'This database has been used with dns.backend set to "api". Switching back to "sql" is not supported, '
        . 'because zone ids written in API mode would point at other zones in SQL mode. Set dns.backend to "api" again.';

    /** For the log only: the override when the database was in fact never used with the API backend. */
    public const OVERRIDE_HINT = 'If this database was never used with the API backend, set the app_settings row backend.zone_ids to "sql".';

    /**
     * Run first in the zone id allocator's transaction. The marker row stays locked until it ends,
     * so concurrent writers queue on this one row instead of on the gaps between zones rows.
     */
    public static function markApi(PDO $db): void
    {
        $current = self::lockMarker($db);

        if ($current === false) {
            // A losing insert must not raise: on PostgreSQL a failed statement aborts the transaction
            $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $verb = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT';
            $onConflict = match ($driver) {
                'pgsql' => ' ON CONFLICT (setting_key) DO NOTHING',
                'mysql' => ' ON DUPLICATE KEY UPDATE setting_key = setting_key',
                default => '',
            };
            $insert = $db->prepare(
                "$verb INTO app_settings (setting_key, setting_value, value_type)
                 SELECT :key, :value, 'string'
                 WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = :existing)" . $onConflict
            );
            $insert->execute([':key' => self::MARKER, ':value' => self::API, ':existing' => self::MARKER]);
            if ($insert->rowCount() > 0) {
                return;
            }

            $current = self::lockMarker($db);
            if ($current === false) {
                throw new \RuntimeException('The backend marker row disappeared while it was being created');
            }
        }

        if ($current !== self::API) {
            $update = $db->prepare("UPDATE app_settings SET setting_value = :value WHERE setting_key = :key");
            $update->execute([':value' => self::API, ':key' => self::MARKER]);
        }
    }

    private static function lockMarker(PDO $db): mixed
    {
        $lock = $db->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = :key" . DbCompat::rowLock((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME))
        );
        $lock->execute([':key' => self::MARKER]);

        return $lock->fetchColumn();
    }

    /**
     * For the SQL backend: the refusal message when the database has been used in API mode.
     *
     * @param string $domainsTable The PowerDNS domains table, prefixed when pdns_db_name is set
     */
    public static function sqlModeRefusal(PDO $db, string $domainsTable): ?string
    {
        try {
            $value = self::stored($db);
            $storable = true;
        } catch (PDOException $e) {
            if (!DbCompat::isMissingTable($e)) {
                throw $e;
            }
            // Before the 4.5.0 schema update there is nowhere to keep the result
            $value = false;
            $storable = false;
        }

        if ($value === false) {
            $usedInApiMode = self::usedInApiMode($db, $domainsTable);
            if ($usedInApiMode === null) {
                return null;
            }
            $value = $usedInApiMode ? self::API : self::SQL;
            if ($storable && !self::store($db, $value)) {
                // An API write marked the database meanwhile; its marker wins over this classification
                $value = self::stored($db) ?: $value;
            }
        }

        return $value === self::API ? self::SQL_MODE_REFUSAL : null;
    }

    /**
     * API mode leaves rows without a domain_id (NULL or 0 before 4.6.0) and rows keyed by their
     * own id whose name disagrees with domains: the zone has another id there, or the id is
     * another zone's. SQL-mode rows of deleted zones are not keyed by their own id. Null when the
     * tables do not exist yet, so there is nothing to classify.
     */
    private static function usedInApiMode(PDO $db, string $domainsTable): ?bool
    {
        try {
            $stmt = $db->prepare(
                // PowerDNS stores names lowercase, so d.name stays bare for its index
                "SELECT 1 FROM zones z
                 WHERE z.domain_id IS NULL OR z.domain_id = 0
                    OR (z.zone_name IS NOT NULL AND z.domain_id = z.id AND (
                        EXISTS (SELECT 1 FROM $domainsTable d WHERE d.name = LOWER(z.zone_name) AND d.id <> z.domain_id)
                        OR EXISTS (SELECT 1 FROM $domainsTable d WHERE d.id = z.domain_id AND LOWER(d.name) <> LOWER(z.zone_name))
                    ))
                 LIMIT 1"
            );
            $stmt->execute();
        } catch (PDOException $e) {
            // Before the 4.3.0 schema update zones has no zone_name and nothing to classify
            if (DbCompat::isMissingTable($e) || DbCompat::isMissingColumn($e)) {
                return null;
            }
            throw $e;
        }

        return $stmt->fetchColumn() !== false;
    }

    private static function stored(PDO $db): string|false
    {
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :key");
        $stmt->execute([':key' => self::MARKER]);

        return $stmt->fetchColumn();
    }

    /** Whether this request stored the marker; false when another request got there first. */
    private static function store(PDO $db, string $value): bool
    {
        try {
            $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value, value_type) VALUES (:key, :value, 'string')");
            $stmt->execute([':key' => self::MARKER, ':value' => $value]);

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
