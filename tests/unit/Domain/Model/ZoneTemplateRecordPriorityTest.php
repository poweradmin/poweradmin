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

namespace unit\Domain\Model;

use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Service\MessageService;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Pins the priority range check on zone template records: only the types that
 * carry a preference in the prio column are refused for an out-of-range value.
 */
class ZoneTemplateRecordPriorityTest extends SqliteIntegrationTestCase
{
    private const TEMPLATE = 10;

    protected function setUp(): void
    {
        parent::setUp();

        // Other tests leave system messages in the session; read them off first
        (new MessageService())->getMessages('system');

        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (" . self::TEMPLATE . ", 'shared', 0)");
    }

    protected function tearDown(): void
    {
        (new MessageService())->getMessages('system');
        parent::tearDown();
    }

    public function testAnOutOfRangePriorityIsRefusedForAPriorityBearingType(): void
    {
        $model = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false), $this->permissionService());

        $this->assertFalse($model->addZoneTemplRecord(self::TEMPLATE, 'mail.example.com', 'MX', 'mx.example.com', 3600, 70000));

        $messages = (new MessageService())->getMessages('system');
        $this->assertStringContainsString('between 0 and 65535', $messages[0]['content']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zone_templ_records")->fetchColumn());
    }

    public function testTheRangeCheckLeavesOtherTypesToTheirOwnValidator(): void
    {
        $model = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false), $this->permissionService());

        $this->assertFalse($model->addZoneTemplRecord(self::TEMPLATE, 'www.example.com', 'A', '192.0.2.1', 3600, 70000));

        $messages = (new MessageService())->getMessages('system');
        $this->assertStringContainsString('A records must have priority value of 0', $messages[0]['content']);
    }

    public function testAnInRangePriorityIsStoredForAPriorityBearingType(): void
    {
        $model = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false), $this->permissionService());

        $this->assertTrue($model->addZoneTemplRecord(self::TEMPLATE, 'mail.example.com', 'MX', 'mx.example.com', 3600, 20));
        $this->assertSame(20, (int)$this->db->query("SELECT prio FROM zone_templ_records")->fetchColumn());
    }
}
