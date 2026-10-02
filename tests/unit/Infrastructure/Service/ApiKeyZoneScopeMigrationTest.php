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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Infrastructure\Service\ApiKeyZoneScopeMigration;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * API key scopes saved in API backend mode before 4.6.0 hold zones row ids; requests compare
 * canonical ids. The migration maps each to the canonical id once and never widens a key.
 */
class ApiKeyZoneScopeMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER NOT NULL, zone_id INTEGER NOT NULL, UNIQUE (api_key_id, zone_id))");
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
        // Row 14 is migrated (canonical 12); row 27 is migrated with domain_id 14, so 14 is
        // also a canonical id; row 30 was created here (canonical 30); row 7 is migrated (4011).
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES
            (14, 12, 'reverse.example'), (27, 14, 'group.example'), (30, 30, 'created.example'), (7, 4011, 'migrated.example'), (40, 4011, NULL)");
    }

    private function scope(int $keyId, int ...$zoneIds): void
    {
        foreach ($zoneIds as $zoneId) {
            $this->db->exec("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES ($keyId, $zoneId)");
        }
    }

    /** @return int[] */
    private function scopes(int $keyId): array
    {
        $ids = array_map('intval', $this->db->query("SELECT zone_id FROM api_key_zones WHERE api_key_id = $keyId")->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);

        return $ids;
    }

    private function migrate(bool $apiBackend = true): void
    {
        (new ApiKeyZoneScopeMigration($this->db, $apiBackend, new NullLogger()))->runOnce();
    }

    public function testRowIdsBecomeCanonicalIdsAndOthersStay(): void
    {
        $this->scope(1, 7, 30, 999);

        $this->migrate();

        $this->assertSame([30, 999, 4011], $this->scopes(1));
    }

    public function testARowIdThatIsAlsoAnotherZonesCanonicalIdMatchesNoZoneAfterwards(): void
    {
        // 14 is reverse.example's row id and group.example's canonical id
        $this->scope(2, 14);

        $this->migrate();

        $this->assertSame([0], $this->scopes(2), 'The key stays restricted instead of opening either zone');
    }

    public function testAValueThatIsOnlySomeZonesCanonicalIdMatchesNothingAfterwards(): void
    {
        // 4011 is no row id but row 7's canonical id: a scope saved in SQL mode or a deleted zone's row id
        $this->scope(3, 4011);

        $this->migrate();

        $this->assertSame([0], $this->scopes(3));
    }

    public function testTwoStoredIdsForOneZoneCollapseWithoutEmptyingTheKey(): void
    {
        // Rows 7 and 41 share canonical id 4011, so both stored row ids land on it
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (41, 4011, 'twin.example')");
        $this->scope(9, 7, 41);

        $this->migrate();

        $this->assertSame([4011], $this->scopes(9));
    }

    public function testItRunsOnceSoCanonicalIdsAreNeverMappedAgain(): void
    {
        $this->scope(4, 7);
        $this->migrate();
        // A row whose id equals the new canonical id must not pull the scope along a second time
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (4011, 5000, 'other.example')");

        $this->migrate();

        $this->assertSame([4011], $this->scopes(4));
    }

    public function testSqlModeOnlySetsTheMarker(): void
    {
        $this->scope(5, 7);

        $this->migrate(false);
        $this->migrate(true);

        $this->assertSame([7], $this->scopes(5), 'Scopes saved in SQL mode hold domains.id and stay as they are');
    }

    public function testARequestThatLosesTheMarkerChangesNothing(): void
    {
        $this->scope(6, 7);
        $this->db->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('" . ApiKeyZoneScopeMigration::MARKER . "', 'rewritten')");

        $this->migrate();

        $this->assertSame([7], $this->scopes(6));
    }

    public function testTheResultDoesNotDependOnTheOrderScopesWereSavedIn(): void
    {
        // Row 4011 is another migrated zone, so 4011 is both its row id and row 7's canonical id
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (4011, 5000, 'other.example')");
        $this->scope(7, 7, 4011);
        $this->scope(8, 4011, 7);

        $this->migrate();

        $this->assertSame([0, 4011], $this->scopes(7));
        $this->assertSame([0, 4011], $this->scopes(8));
    }

    public function testAFailedRunLeavesApiModeScopesUntrusted(): void
    {
        $this->db->exec("DROP TABLE app_settings");
        $this->scope(10, 7);

        $this->assertFalse((new ApiKeyZoneScopeMigration($this->db, true, new NullLogger()))->runOnce());
        $this->assertTrue((new ApiKeyZoneScopeMigration($this->db, false, new NullLogger()))->runOnce(), 'SQL mode scopes are canonical anyway');
        $this->assertSame([7], $this->scopes(10));
    }

    public function testAnUntrustedRepositoryRestrictsAScopedKeyToNothing(): void
    {
        $this->scope(11, 7);
        $repository = new DbApiKeyRepository($this->db, new FakeConfiguration(), null, false);

        $this->assertSame([0], $repository->getZoneIds(11));
        $this->assertSame([], $repository->getZoneIds(12), 'An unrestricted key stays unrestricted');
        $this->assertFalse($repository->zoneScopesReady());
    }
}
