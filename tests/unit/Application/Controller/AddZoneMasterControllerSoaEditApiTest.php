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
use ReflectionClass;

class AddZoneMasterControllerSoaEditApiTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(AddZoneMasterController::class);
    }

    private function createController(mixed $configuredOptions): AddZoneMasterController
    {
        $controller = $this->reflection->newInstanceWithoutConstructor();

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnMap([
            ['dns', 'soa_edit_api_options', null, $configuredOptions],
        ]);

        $baseReflection = $this->reflection->getParentClass();
        $property = $baseReflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue($controller, $config);

        return $controller;
    }

    private function invoke(AddZoneMasterController $controller, string $method, array $args = []): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($controller, $args);
    }

    public function testFullChoiceSetWhenNotConfigured(): void
    {
        $controller = $this->createController(null);

        $this->assertSame(
            ['DEFAULT', 'INCREASE', 'EPOCH', 'SOA-EDIT', 'SOA-EDIT-INCREASE', 'OFF'],
            $this->invoke($controller, 'getSoaEditApiChoices')
        );
    }

    public function testConfiguredListNarrowsChoicesAndDropsUnknownValues(): void
    {
        $controller = $this->createController(['EPOCH', 'OFF', 'BOGUS']);

        $this->assertSame(['EPOCH', 'OFF'], $this->invoke($controller, 'getSoaEditApiChoices'));
    }

    public function testEmptyListHidesAllChoices(): void
    {
        $controller = $this->createController([]);

        $this->assertSame([], $this->invoke($controller, 'getSoaEditApiChoices'));
    }

    public function testSanitizeRejectsValueOutsideConfiguredChoices(): void
    {
        $controller = $this->createController(['DEFAULT']);

        $this->assertNull($this->invoke($controller, 'sanitizeSoaEditApiInput', ['EPOCH']));
        $this->assertSame('DEFAULT', $this->invoke($controller, 'sanitizeSoaEditApiInput', ['DEFAULT']));
        $this->assertNull($this->invoke($controller, 'sanitizeSoaEditApiInput', [null]));
    }
}
