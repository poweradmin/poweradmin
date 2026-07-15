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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Model\ZoneTemplate;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A global template (owner 0) is reserved for ueberusers. A user holding only
 * zone_templ_add / zone_templ_edit must not be able to create or promote one by
 * forging the templ_global flag; the resulting template must stay personal.
 */
class ZoneTemplateGlobalOwnerTest extends SqliteIntegrationTestCase
{
    private const CLIENT_USER_ID = 2;
    private const CLIENT_PERM_TEMPL_ID = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");

        // A non-ueberuser "client" who may add and edit templates but is not an admin.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_templ_add'), (61, 'zone_templ_edit')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 60), (" . self::CLIENT_PERM_TEMPL_ID . ", 61)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CLIENT_USER_ID . ", 'client', " . self::CLIENT_PERM_TEMPL_ID . ")");
    }

    #[RunInSeparateProcess]
    public function testAddGlobalTemplateFallsBackToPersonalForNonUeberuser(): void
    {
        $this->actAsClient();

        $created = $this->zoneTemplate()->addZoneTempl(
            ['templ_name' => 'ClientGlobal', 'templ_descr' => '', 'templ_global' => '1'],
            self::CLIENT_USER_ID
        );

        $this->assertTrue($created);
        $this->assertSame(self::CLIENT_USER_ID, $this->ownerOfTemplateNamed('ClientGlobal'), 'A non-ueberuser must not create a global (owner 0) template.');
    }

    #[RunInSeparateProcess]
    public function testAddGlobalTemplateAllowedForUeberuser(): void
    {
        // Session user is the seeded ueberuser (id 1).
        $created = $this->zoneTemplate()->addZoneTempl(
            ['templ_name' => 'AdminGlobal', 'templ_descr' => '', 'templ_global' => '1'],
            self::ADMIN_USER_ID
        );

        $this->assertTrue($created);
        $this->assertSame(0, $this->ownerOfTemplateNamed('AdminGlobal'), 'A ueberuser may create a global template.');
    }

    #[RunInSeparateProcess]
    public function testSaveAsGlobalFallsBackToPersonalForNonUeberuser(): void
    {
        $this->actAsClient();

        $created = $this->zoneTemplate()->addZoneTemplSaveAs(
            'ClientSaveAs',
            '',
            self::CLIENT_USER_ID,
            [],
            ['global' => true]
        );

        $this->assertTrue($created);
        $this->assertSame(self::CLIENT_USER_ID, $this->ownerOfTemplateNamed('ClientSaveAs'), 'Save-as must not produce a global template for a non-ueberuser.');
    }

    #[RunInSeparateProcess]
    public function testEditMakeGlobalFallsBackToPersonalForNonUeberuser(): void
    {
        $this->db->exec("INSERT INTO zone_templ (id, name, owner, created_by) VALUES (30, 'ClientOwned', " . self::CLIENT_USER_ID . ", " . self::CLIENT_USER_ID . ")");
        $this->actAsClient();

        $updated = $this->zoneTemplate()->editZoneTempl(
            ['templ_name' => 'ClientOwned', 'templ_descr' => '', 'templ_global' => '1'],
            30,
            self::CLIENT_USER_ID
        );

        $this->assertTrue($updated);
        $this->assertSame(self::CLIENT_USER_ID, $this->ownerOfTemplateId(30), 'A non-ueberuser must not promote a template to global.');
    }

    #[RunInSeparateProcess]
    public function testEditMakeGlobalAllowedForUeberuser(): void
    {
        $this->db->exec("INSERT INTO zone_templ (id, name, owner, created_by) VALUES (31, 'AdminOwned', 1, 1)");

        $updated = $this->zoneTemplate()->editZoneTempl(
            ['templ_name' => 'AdminOwned', 'templ_descr' => '', 'templ_global' => '1'],
            31,
            self::ADMIN_USER_ID
        );

        $this->assertTrue($updated);
        $this->assertSame(0, $this->ownerOfTemplateId(31), 'A ueberuser may promote a template to global.');
    }

    private function actAsClient(): void
    {
        $_SESSION['userid'] = self::CLIENT_USER_ID;
    }

    private function zoneTemplate(): ZoneTemplate
    {
        return new ZoneTemplate($this->db, $this->config);
    }

    private function ownerOfTemplateNamed(string $name): int
    {
        $stmt = $this->db->prepare('SELECT owner FROM zone_templ WHERE name = :name');
        $stmt->execute([':name' => $name]);

        return (int)$stmt->fetchColumn();
    }

    private function ownerOfTemplateId(int $id): int
    {
        $stmt = $this->db->prepare('SELECT owner FROM zone_templ WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (int)$stmt->fetchColumn();
    }
}
