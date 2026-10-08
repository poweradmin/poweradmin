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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Unlinking a zone from its template also drops its template sync rows, so the
 * template stops reporting it as out of sync (issue #1660).
 */
class ZoneTemplateUnlinkTest extends TestCase
{
    public function testUnlinkingAZoneDropsItsTemplateSyncRows(): void
    {
        $db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER DEFAULT 0)");
        $db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0)");
        // Zone 11 has two owners, so two zones rows; zone 12 stays linked
        $db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 3, 5), (2, 11, 4, 5), (3, 12, 3, 5)");
        $db->exec("INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync) VALUES (1, 5, 1), (2, 5, 1), (3, 5, 1)");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default);

        $this->assertTrue((new ZoneTemplate($db, $config))->unlinkZoneFromTemplate(11));

        $this->assertSame([0, 0, 5], array_map('intval', $db->query("SELECT zone_templ_id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([3], array_map('intval', $db->query("SELECT zone_id FROM zone_template_sync ORDER BY zone_id")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
