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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;

/**
 * Once the API backend has written zone rows, the SQL backend must refuse the database.
 */
class BackendModeMarkerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
        $this->db->exec("INSERT INTO domains (id, name) VALUES (10, 'a.example'), (11, 'b.example')");
    }

    private function marker(): string|false
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([BackendModeMarker::MARKER]);

        return $stmt->fetchColumn();
    }

    public function testTheAllocatorMarksTheDatabaseAsUsedInApiMode(): void
    {
        new CanonicalZoneIdAllocator($this->db);
        new CanonicalZoneIdAllocator($this->db);

        $this->assertSame(BackendModeMarker::API, $this->marker());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM app_settings")->fetchColumn());
    }

    public function testApiModeOverridesAnEarlierSqlClassification(): void
    {
        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
        $this->assertSame(BackendModeMarker::SQL, $this->marker());

        BackendModeMarker::markApi($this->db);

        $this->assertSame(BackendModeMarker::API, $this->marker());
        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testSqlModeDataIsClassifiedAsSqlOnce(): void
    {
        // An extra owner row and a row whose domain was deleted in PowerDNS are normal in SQL mode
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (10, 1, 'A.example'), (10, 2, NULL), (99, 1, 'gone.example')");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
        $this->assertSame(BackendModeMarker::SQL, $this->marker());
    }

    public function testARowWithoutDomainIdMeansApiMode(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (NULL, 1, 'a.example')");

        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
        $this->assertSame(BackendModeMarker::API, $this->marker());
    }

    public function testANamedRowPointingAtAnotherDomainMeansApiMode(): void
    {
        // API mode gave a.example the canonical id 11, which is b.example's domains.id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (11, 11, 1, 'a.example')");

        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testANamedRowWhoseIdIsNoDomainMeansApiMode(): void
    {
        // API mode gave b.example the canonical id 500; PowerDNS knows it as domains.id 11
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (500, 500, 1, 'b.example')");

        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testANamedRowKeyedByAnotherZonesIdMeansApiMode(): void
    {
        // An API zone deleted from PowerDNS whose canonical id 11 is b.example's domains.id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (11, 11, 1, 'gone.example')");

        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testARowOfAZoneRecreatedOutsidePoweradminIsSqlData(): void
    {
        // a.example was deleted in PowerDNS and created again as domains.id 10; its old row stays
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (5, 50, 1, 'a.example')");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testAStoredSqlMarkerIsNotReclassified(): void
    {
        $this->db->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('" . BackendModeMarker::MARKER . "', 'sql')");
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (NULL, 1, 'a.example')");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testASchemaWithoutAppSettingsIsStillClassified(): void
    {
        $this->db->exec("DROP TABLE app_settings");
        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));

        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_name) VALUES (NULL, 1, 'a.example')");
        $this->assertSame(BackendModeMarker::SQL_MODE_REFUSAL, BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
    }

    public function testADatabaseWithoutZonesIsNotClassified(): void
    {
        $this->db->exec("DROP TABLE zones");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
        $this->assertFalse($this->marker());
    }

    public function testASchemaBeforeZoneNamesIsNotClassified(): void
    {
        $this->db->exec("DROP TABLE zones");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");

        $this->assertNull(BackendModeMarker::sqlModeRefusal($this->db, 'domains'));
        $this->assertFalse($this->marker());
    }
}
