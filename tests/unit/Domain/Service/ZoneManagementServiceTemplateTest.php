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
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * API zone creation must apply the same template-usability rule as the web UI:
 * a template is usable when it is global (owner 0), owned by the caller, or the
 * caller is ueberuser.
 */
#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceTemplateTest extends SqliteIntegrationTestCase
{
    private const OTHER_USER = 2;
    private const PRIVATE_TEMPLATE = 10;
    private const GLOBAL_TEMPLATE = 11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL DEFAULT 0, created_by INTEGER)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (2, 'Client')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::OTHER_USER . ", 'client', 2)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES
            (" . self::PRIVATE_TEMPLATE . ", 'admin-private', " . self::ADMIN_USER_ID . "),
            (" . self::GLOBAL_TEMPLATE . ", 'global', 0),
            (12, 'dup', 0),
            (13, 'dup', 0)");
    }

    private function service(): ZoneManagementService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, $default = null) => $default);

        return new ZoneManagementService($this->createMock(ZoneRepositoryInterface::class), $config, $this->db);
    }

    public function testNoTemplateResolvesToNone(): void
    {
        $this->assertSame(['id' => 'none'], $this->service()->resolveZoneTemplate('none', self::OTHER_USER));
        $this->assertSame(['id' => 'none'], $this->service()->resolveZoneTemplate('', self::OTHER_USER));
    }

    public function testUnknownTemplateIs404(): void
    {
        $this->assertSame(404, $this->service()->resolveZoneTemplate('999', self::ADMIN_USER_ID)['status']);
        $this->assertSame(404, $this->service()->resolveZoneTemplate('missing', self::ADMIN_USER_ID)['status']);
    }

    public function testAmbiguousNameIs409(): void
    {
        $this->assertSame(409, $this->service()->resolveZoneTemplate('dup', self::ADMIN_USER_ID)['status']);
    }

    public function testOtherUsersPrivateTemplateIsRefused(): void
    {
        $result = $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::OTHER_USER);

        $this->assertSame(403, $result['status']);
        $this->assertSame('You do not have permission to use this zone template', $result['message']);

        $byName = $this->service()->resolveZoneTemplate('admin-private', self::OTHER_USER);
        $this->assertSame(403, $byName['status']);
    }

    public function testGlobalTemplateIsUsableByAnyone(): void
    {
        $this->assertSame(['id' => '11'], $this->service()->resolveZoneTemplate('global', self::OTHER_USER));
    }

    public function testOwnerAndUeberuserMayUsePrivateTemplate(): void
    {
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID));

        $this->db->exec("UPDATE zone_templ SET owner = " . self::OTHER_USER . " WHERE id = " . self::PRIVATE_TEMPLATE);
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::OTHER_USER));
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID));
    }

    public function testWithoutActingUserOnlyExistenceIsChecked(): void
    {
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, null));
    }
}
