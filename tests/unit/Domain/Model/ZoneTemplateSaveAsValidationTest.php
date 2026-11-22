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

namespace Poweradmin\Tests\Unit\Domain\Model;

use Poweradmin\Domain\Model\ZoneTemplate;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Saving a zone as a template runs each copied record through the template record
 * validation, leaves the ones it refuses out and says so.
 */
class ZoneTemplateSaveAsValidationTest extends SqliteIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec('CREATE TABLE zone_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, owner integer NOT NULL, created_by integer, is_default boolean NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE zone_templ_records (id integer PRIMARY KEY, zone_templ_id integer NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(6) NOT NULL, content VARCHAR(2048) NOT NULL, ttl integer NOT NULL, prio integer NOT NULL)');
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_templ_add')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (' . self::ADMIN_PERM_TEMPL_ID . ', 60)');
        $_SESSION['messages'] = [];
    }

    public function testRecordsTheValidatorRefusesAreLeftOutAndReported(): void
    {
        $records = [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'bad.example.com', 'type' => 'A', 'content' => 'not-an-address', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'example.com', 'type' => 'TXT', 'content' => "\"ok\x01\"", 'ttl' => 3600, 'prio' => 0],
        ];

        $template = new ZoneTemplate($this->db, $this->config);
        $this->assertTrue($template->addZoneTemplSaveAs('Copy', '', self::ADMIN_USER_ID, $records, [], 'example.com'));

        $stored = $this->db->query("SELECT name, type FROM zone_templ_records WHERE type != 'SOA' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['name' => 'www.[ZONE]', 'type' => 'A']], $stored);

        $notice = json_encode($_SESSION);
        $this->assertStringContainsString('left out of the template because they are not valid', $notice);
        $this->assertStringContainsString('bad.[ZONE] A', $notice);
        $this->assertStringContainsString('[ZONE] TXT', $notice);
        $this->assertStringNotContainsString('www.[ZONE] A', $notice);
    }

    public function testValidRecordsAreStoredWithoutANotice(): void
    {
        $records = [['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0]];

        $template = new ZoneTemplate($this->db, $this->config);
        $this->assertTrue($template->addZoneTemplSaveAs('Copy', '', self::ADMIN_USER_ID, $records, [], 'example.com'));

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zone_templ_records WHERE type = 'A'")->fetchColumn());
        $this->assertStringNotContainsString('not valid', json_encode($_SESSION));
    }
}
