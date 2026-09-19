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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\AddZoneMasterController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionClass;

class AddZoneMasterControllerZoneTypesTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(AddZoneMasterController::class);
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
        parent::tearDown();
    }

    private function createController(string $backend): AddZoneMasterController
    {
        $controller = $this->reflection->newInstanceWithoutConstructor();

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnMap([
            ['dns', 'backend', null, $backend],
        ]);

        $property = $this->reflection->getParentClass()->getProperty('config');
        $property->setAccessible(true);
        $property->setValue($controller, $config);

        // An expired or missing version cache triggers a refresh, which needs the logger.
        $logger = $this->reflection->getParentClass()->getProperty('logger');
        $logger->setAccessible(true);
        $logger->setValue($controller, new NullLogger());

        return $controller;
    }

    private function invoke(object $controller, string $method, array $args = []): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($controller, $args);
    }

    private function seedServerVersion(string $version): void
    {
        $_SESSION['pdns_server_info'] = [
            'fetched_at' => time(),
            'info' => ['version' => $version],
        ];
    }

    // ---- supportsCatalogKinds: the capability gate ----

    /**
     * An unknown version must count as unsupported: the 4.7 schema widened
     * domains.type from VARCHAR(6) to VARCHAR(8), so writing an 8-character
     * catalog kind to an older schema truncates it.
     */
    public function testUnknownVersionHidesCatalogKinds(): void
    {
        $this->assertFalse($this->invoke($this->createController('sql'), 'supportsCatalogKinds'));
        $this->assertFalse($this->invoke($this->createController('api'), 'supportsCatalogKinds'));
    }

    public function testCatalogKindsHiddenBefore47(): void
    {
        $this->seedServerVersion('4.6.0');

        $this->assertFalse($this->invoke($this->createController('api'), 'supportsCatalogKinds'));
    }

    public function testCatalogKindsOfferedFrom47(): void
    {
        $this->seedServerVersion('4.7.0');

        $this->assertTrue($this->invoke($this->createController('api'), 'supportsCatalogKinds'));
        $this->assertTrue($this->invoke($this->createController('sql'), 'supportsCatalogKinds'));
    }
}
