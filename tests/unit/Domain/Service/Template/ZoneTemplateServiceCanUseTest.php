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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Psr\Log\NullLogger;
use TestHelpers\ZoneTemplateServiceBuilder;

/**
 * Zone templates are private to their owner unless global. Applying one by a posted
 * id (zone creation, bulk registration, template change) must follow the listing
 * scope, or an enumerated id copies another user's records into the caller's zone.
 */
class ZoneTemplateServiceCanUseTest extends TestCase
{
    private const USER_ID = 100;
    private const OWN_TEMPLATE_ID = 10;
    private const FOREIGN_TEMPLATE_ID = 11;
    private const GLOBAL_TEMPLATE_ID = 12;

    private ZoneTemplateService $zoneTemplate;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER, created_by INTEGER, is_default INTEGER DEFAULT 0)");
        $db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::OWN_TEMPLATE_ID . ", 'own', '', " . self::USER_ID . ")");
        $db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::FOREIGN_TEMPLATE_ID . ", 'foreign', '', 1)");
        $db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::GLOBAL_TEMPLATE_ID . ", 'global', '', 0)");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn(null);

        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->zoneTemplate = ZoneTemplateServiceBuilder::build(new DbZoneTemplateRepository($db, $config, $backend), $config, $backend, $this->createMock(PermissionService::class), new SessionActor(), new NullLogger());
    }

    public function testForeignTemplateIsRefused(): void
    {
        $this->assertFalse($this->zoneTemplate->canUseTemplate(self::FOREIGN_TEMPLATE_ID, self::USER_ID, false));
        $this->assertFalse($this->zoneTemplate->canUseTemplate((string)self::FOREIGN_TEMPLATE_ID, self::USER_ID, false));
    }

    public function testOwnAndGlobalTemplatesAreAllowed(): void
    {
        $this->assertTrue($this->zoneTemplate->canUseTemplate(self::OWN_TEMPLATE_ID, self::USER_ID, false));
        $this->assertTrue($this->zoneTemplate->canUseTemplate(self::GLOBAL_TEMPLATE_ID, self::USER_ID, false));
    }

    public function testNoTemplateIsAllowed(): void
    {
        $this->assertTrue($this->zoneTemplate->canUseTemplate('none', self::USER_ID, false));
        $this->assertTrue($this->zoneTemplate->canUseTemplate(0, self::USER_ID, false));
        $this->assertTrue($this->zoneTemplate->canUseTemplate('', self::USER_ID, false));
        $this->assertTrue($this->zoneTemplate->canUseTemplate(null, self::USER_ID, false));
    }

    public function testUnknownOrMalformedIdIsRefused(): void
    {
        $this->assertFalse($this->zoneTemplate->canUseTemplate(999, self::USER_ID, false));
        $this->assertFalse($this->zoneTemplate->canUseTemplate('abc', self::USER_ID, false));
        $this->assertFalse($this->zoneTemplate->canUseTemplate(['11'], self::USER_ID, false));
    }

    public function testAdministratorMayUseAnyTemplate(): void
    {
        $this->assertTrue($this->zoneTemplate->canUseTemplate(self::FOREIGN_TEMPLATE_ID, self::USER_ID, true));
    }
}
