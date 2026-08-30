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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the owner-to-account lookup picks the same row on every engine.
 *
 * The zones table carries two overlapping id spaces (see CanonicalZoneSql): a zone
 * migrated from SQL mode is addressed by domain_id, one this application created is
 * addressed by its own id. In a mixed install a native zone's primary key can equal a
 * migrated zone's domain_id, so selecting the owner group by canonical id alone lets an
 * unrelated zone's owner win ORDER BY and be pushed into PowerDNS's account field.
 *
 * Feeds `dns.sync_zone_owner_to_account` (issue #1358); same id-space class as #1418.
 *
 * Run locally via `composer tests:integration` against the devcontainer (MariaDB on
 * 3306, PostgreSQL on 5432). SQLite is in-memory and always exercised. Any engine that
 * isn't reachable is skipped, not failed. Not run in CI.
 */
class ZoneAccountSyncOwnerIntegrationTest extends TestCase
{
    /** The query ApiZoneRepository::getOldestOwnerUsername() runs. */
    private const ANCHORED = "SELECT u.username
             FROM zones_acct_test z
             INNER JOIN users_acct_test u ON z.owner = u.id
             WHERE z.id = :row_id
                OR (z.zone_name IS NULL AND z.domain_id = :canonical_id)
             ORDER BY z.id
             LIMIT 1";

    /** The shape it replaced, kept so the regression stays visible on every engine. */
    private const CANONICAL_ONLY = "SELECT u.username
             FROM zones_acct_test z
             INNER JOIN users_acct_test u ON z.owner = u.id
             WHERE COALESCE(z.domain_id, z.id) = :canonical_id
             ORDER BY z.id
             LIMIT 1";

    private ?PDO $mysql = null;
    private ?PDO $pgsql = null;
    private PDO $sqlite;

    protected function setUp(): void
    {
        try {
            $this->mysql = new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->mysql = null;
        }

        try {
            $this->pgsql = new PDO(
                'pgsql:host=127.0.0.1;port=5432;dbname=pdns',
                'pdns',
                'poweradmin',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->pgsql = null;
        }

        $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach ($this->connections() as $db) {
            $this->setupFixture($db);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections() as $db) {
            $db->exec("DROP TABLE IF EXISTS zones_acct_test");
            $db->exec("DROP TABLE IF EXISTS users_acct_test");
        }
    }

    /**
     * @return array<string, PDO>
     */
    private function connections(): array
    {
        $conns = ['sqlite' => $this->sqlite];
        if ($this->mysql) {
            $conns['mysql'] = $this->mysql;
        }
        if ($this->pgsql) {
            $conns['pgsql'] = $this->pgsql;
        }
        return $conns;
    }

    private function setupFixture(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS zones_acct_test");
        $db->exec("DROP TABLE IF EXISTS users_acct_test");

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec("CREATE TABLE users_acct_test (id INTEGER PRIMARY KEY, username TEXT)");
            $db->exec("CREATE TABLE zones_acct_test (id INTEGER PRIMARY KEY, domain_id INTEGER,
                zone_name TEXT, owner INTEGER)");
        } else {
            $db->exec("CREATE TABLE users_acct_test (id INT PRIMARY KEY, username VARCHAR(64))");
            $db->exec("CREATE TABLE zones_acct_test (id INT PRIMARY KEY, domain_id INT NULL,
                zone_name VARCHAR(255) NULL, owner INT)");
        }

        $db->exec("INSERT INTO users_acct_test (id, username) VALUES
            (1, 'migrated_owner'), (2, 'native_owner'), (3, 'co_owner')");

        // Mixed install: the migrated zone is addressed by domain_id 42 while its own
        // primary key is 50; the native zone's primary key is that same 42.
        $db->exec("INSERT INTO zones_acct_test (id, domain_id, zone_name, owner) VALUES
            (50, 42, 'migrated.example.com', 1),
            (42, NULL, 'native.example.com', 2)");
    }

    private function lookup(PDO $db, string $sql, int $rowId, int $canonicalId): ?string
    {
        $stmt = $db->prepare($sql);
        if (str_contains($sql, ':row_id')) {
            $stmt->bindValue(':row_id', $rowId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':canonical_id', $canonicalId, PDO::PARAM_INT);
        $stmt->execute();
        $username = $stmt->fetchColumn();

        return $username === false ? null : (string)$username;
    }

    public function testTheMigratedZoneReportsItsOwnOwnerOnEveryEngine(): void
    {
        foreach ($this->connections() as $engine => $db) {
            $this->assertSame(
                'migrated_owner',
                $this->lookup($db, self::ANCHORED, 50, 42),
                "$engine returned the wrong account for the migrated zone"
            );
        }
    }

    public function testTheNativeZoneReportsItsOwnOwnerOnEveryEngine(): void
    {
        foreach ($this->connections() as $engine => $db) {
            $this->assertSame(
                'native_owner',
                $this->lookup($db, self::ANCHORED, 42, 42),
                "$engine returned the wrong account for the native zone"
            );
        }
    }

    public function testAnExtraOwnerOlderThanTheCanonicalRowStillWinsOnEveryEngine(): void
    {
        foreach ($this->connections() as $engine => $db) {
            // Extra owners are stored with a NULL zone_name keyed by the canonical id.
            $db->exec("INSERT INTO zones_acct_test (id, domain_id, zone_name, owner)
                VALUES (7, 42, NULL, 3)");

            $this->assertSame(
                'co_owner',
                $this->lookup($db, self::ANCHORED, 50, 42),
                "$engine did not pick the oldest owner of the group"
            );
        }
    }

    public function testTheCanonicalIdOnlyShapeMisreportsTheOwnerOnEveryEngine(): void
    {
        // Pins the regression itself: every engine agrees the old shape is wrong, so
        // this is a query-semantics bug and not an engine quirk.
        foreach ($this->connections() as $engine => $db) {
            $this->assertSame(
                'native_owner',
                $this->lookup($db, self::CANONICAL_ONLY, 50, 42),
                "$engine did not reproduce the collision the anchor fixes"
            );
        }
    }
}
