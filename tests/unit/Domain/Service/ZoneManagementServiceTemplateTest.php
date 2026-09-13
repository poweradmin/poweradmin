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
 * API zone creation may only apply a template that is global (owner 0), owned
 * by the caller, or when the caller is ueberuser.
 */
#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceTemplateTest extends SqliteIntegrationTestCase
{
    private const OTHER_USER = 2;
    private const PRIVATE_TEMPLATE = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (2, 'Client')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::OTHER_USER . ", 'client', 2)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES
            (" . self::PRIVATE_TEMPLATE . ", 'admin-private', " . self::ADMIN_USER_ID . "),
            (11, 'global', 0)");
    }

    private function create(string $template, int $actingUserId): array
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $service = new ZoneManagementService($this->createMock(ZoneRepositoryInterface::class), $config, $this->db);

        return $service->createZone('new.example', 'MASTER', $actingUserId, '', $template, false, [], $actingUserId);
    }

    public function testOtherUsersPrivateTemplateIsRefused(): void
    {
        foreach ([(string)self::PRIVATE_TEMPLATE, 'admin-private'] as $template) {
            $result = $this->create($template, self::OTHER_USER);

            $this->assertSame(403, $result['status'], $template);
            $this->assertSame('You do not have permission to use this zone template', $result['message']);
        }
    }

    public function testGlobalOwnAndUeberuserTemplatesPassTheCheck(): void
    {
        // Past the template check the create runs into the fixture's missing
        // tables, so anything other than the 403 proves the check passed.
        $this->assertNotSame(403, $this->create('global', self::OTHER_USER)['status'] ?? null);
        $this->assertNotSame(403, $this->create((string)self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID)['status'] ?? null);

        $this->db->exec("UPDATE zone_templ SET owner = " . self::OTHER_USER . " WHERE id = " . self::PRIVATE_TEMPLATE);
        $this->assertNotSame(403, $this->create((string)self::PRIVATE_TEMPLATE, self::OTHER_USER)['status'] ?? null);
    }
}
