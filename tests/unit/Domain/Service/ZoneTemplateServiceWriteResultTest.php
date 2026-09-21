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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplateService;
use Poweradmin\Domain\Service\ZoneTemplateWriteResult;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Psr\Log\NullLogger;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The template writes used to flash their reason into the session; now they
 * report it, and the controllers decide where it shows. Pins the reasons and
 * statuses the controllers rely on.
 */
#[CoversClass(ZoneTemplateService::class)]
#[CoversClass(ZoneTemplateWriteResult::class)]
class ZoneTemplateServiceWriteResultTest extends SqliteIntegrationTestCase
{
    private const CLIENT_USER_ID = 2;
    private const CLIENT_PERM_TEMPL_ID = 2;
    private const GLOBAL_TEMPLATE = 10;
    private const PRIVATE_TEMPLATE = 11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (" . self::GLOBAL_TEMPLATE . ", 'global', 0), (" . self::PRIVATE_TEMPLATE . ", 'private', " . self::ADMIN_USER_ID . ")");

        // A client who may edit zone content as own_as_client but holds no template grants.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (48, '" . Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT . "')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 48)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CLIENT_USER_ID . ", 'client', " . self::CLIENT_PERM_TEMPL_ID . ")");
    }

    public function testMissingTemplateGrantIsForbidden(): void
    {
        $_SESSION['userid'] = self::CLIENT_USER_ID;
        $service = $this->service();

        $added = $service->addZoneTempl(['templ_name' => 'new', 'templ_descr' => ''], self::CLIENT_USER_ID);
        $this->assertFalse($added->success);
        $this->assertSame(403, $added->status);
        $this->assertSame('You do not have the permission to add a zone template.', $added->message);

        $deleted = $service->deleteZoneTempl(self::GLOBAL_TEMPLATE);
        $this->assertSame(403, $deleted->status);
        $this->assertSame('You do not have the permission to delete zone templates.', $deleted->message);

        $record = $service->addZoneTemplRecord(self::GLOBAL_TEMPLATE, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0);
        $this->assertSame(403, $record->status);
        $this->assertSame('You do not have the permission to add a record to this zone template.', $record->message);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zone_templ WHERE name = 'global'")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zone_templ_records")->fetchColumn());
    }

    public function testDuplicateNameIsReportedAsAConflict(): void
    {
        $added = $this->service()->addZoneTempl(['templ_name' => 'global', 'templ_descr' => ''], self::ADMIN_USER_ID);

        $this->assertFalse($added->success);
        $this->assertSame(409, $added->status);
        $this->assertSame('Zone template with this name already exists, please choose another one.', $added->message);

        $renamed = $this->service()->editZoneTempl(['templ_name' => 'global', 'templ_descr' => ''], self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID);
        $this->assertSame(409, $renamed->status);
        $this->assertSame([['name' => 'private']], $this->db->query("SELECT name FROM zone_templ WHERE id = " . self::PRIVATE_TEMPLATE)->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testDefaultFlagOnlyAcceptsGlobalTemplates(): void
    {
        $service = $this->service();

        $missing = $service->setDefaultTemplate(999);
        $this->assertSame(404, $missing->status);
        $this->assertSame('Zone template not found.', $missing->message);

        $private = $service->setDefaultTemplate(self::PRIVATE_TEMPLATE);
        $this->assertSame(400, $private->status);
        $this->assertSame('Only global zone templates can be set as the default.', $private->message);

        $this->assertTrue($service->setDefaultTemplate(self::GLOBAL_TEMPLATE)->success);
        $this->assertSame(self::GLOBAL_TEMPLATE, (int)$this->db->query("SELECT id FROM zone_templ WHERE is_default = 1")->fetchColumn());

        $this->assertTrue($service->unsetDefaultTemplate()->success);
        $this->assertFalse($this->db->query("SELECT id FROM zone_templ WHERE is_default = 1")->fetchColumn());
    }

    public function testRecordFromAnotherTemplateIsRefusedOnEditAndDelete(): void
    {
        $this->db->exec("INSERT INTO zone_templ_records (id, zone_templ_id, name, type, content, ttl, prio) VALUES (5, " . self::PRIVATE_TEMPLATE . ", 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0)");
        $service = $this->service();

        $edited = $service->editZoneTemplRecord(['rid' => 5, 'name' => 'www.[ZONE]', 'type' => 'A', 'content' => '192.0.2.2', 'ttl' => 3600, 'prio' => 0], self::GLOBAL_TEMPLATE);
        $this->assertSame(404, $edited->status);
        $this->assertSame('The record does not belong to this zone template.', $edited->message);

        $deleted = $service->deleteZoneTemplRecord(5, self::GLOBAL_TEMPLATE);
        $this->assertSame(404, $deleted->status);

        $this->assertSame('192.0.2.1', $this->db->query("SELECT content FROM zone_templ_records WHERE id = 5")->fetchColumn());
    }

    public function testSaveAsReportsTheRecordTypesTheCallerMayNotStore(): void
    {
        // own_as_client may not author SOA or NS records, so those are left out with a warning.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, '" . Permission::PERM_ZONE_TEMPL_ADD . "')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 60)");
        $_SESSION['userid'] = self::CLIENT_USER_ID;

        $saved = $this->service()->addZoneTemplSaveAs('copy', '', self::CLIENT_USER_ID, [
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 2024010100 1 2 3 4', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
        ], [], 'example.com');

        $this->assertTrue($saved->success);
        $this->assertSame('These record types were left out of the template because you may not add them: SOA, NS', $saved->message);
        $this->assertSame(
            [['name' => 'www.[ZONE]', 'type' => 'A']],
            $this->db->query("SELECT name, type FROM zone_templ_records WHERE type <> 'SOA' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC)
        );
        $this->assertSame(self::CLIENT_USER_ID, (int)$this->db->query("SELECT owner FROM zone_templ WHERE name = 'copy'")->fetchColumn());
    }

    private function service(): ZoneTemplateService
    {
        $backend = $this->dnsBackendStub(false);

        return new ZoneTemplateService(
            new DbZoneTemplateRepository($this->db, $this->config, $backend),
            $this->config,
            $backend,
            $this->permissionService(),
            new UserContextService(),
            new NullLogger()
        );
    }
}
