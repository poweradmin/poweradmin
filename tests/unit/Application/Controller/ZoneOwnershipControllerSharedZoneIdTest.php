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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The web "Add group" form on a zone id two zones share in API backend mode: a grant there
 * would reach both zones, so it is refused like an extra owner is.
 */
class ZoneOwnershipControllerSharedZoneIdTest extends TestCase
{
    private mixed $savedConfigInstance = null;
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        // AuditService builds a LegacyLogger, which initializes the ConfigurationManager singleton
        $property = new ReflectionProperty(ConfigurationManager::class, 'instance');
        $this->savedConfigInstance = $property->getValue();
        $property->setValue(null, $this->createMock(ConfigurationManager::class));
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        (new ReflectionProperty(ConfigurationManager::class, 'instance'))->setValue(null, $this->savedConfigInstance);
    }

    private function controller(bool $shared, ZoneGroupRepositoryInterface $zoneGroups): TestableZoneOwnershipController
    {
        $_POST = ['newgroup' => '7'];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $default);

        $zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $zoneRepository->method('isSharedZoneId')->with(42)->willReturn($shared);

        $controller = new TestableZoneOwnershipController();
        $controller->setConfig($config);
        $controller->setDb(new PDO('sqlite::memory:'));
        $controller->permissions = ['user_is_ueberuser' => true];
        $controller->zoneGroupRepository = $zoneGroups;
        foreach (['zoneRepository' => $zoneRepository, 'request' => new Request()] as $name => $value) {
            (new ReflectionProperty(\Poweradmin\Application\Controller\ZoneOwnershipController::class, $name))->setValue($controller, $value);
        }

        return $controller;
    }

    private function submit(TestableZoneOwnershipController $controller): void
    {
        (new ReflectionMethod($controller, 'handleFormSubmission'))->invoke($controller, 42, 'shared.example.com', 1, true);
    }

    public function testRefusesAGroupOnASharedZoneId(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->expects($this->never())->method('add');

        $controller = $this->controller(true, $zoneGroups);
        $this->submit($controller);

        $this->assertSame([[
            'zone-ownership',
            'error',
            'Groups cannot be assigned to this zone: its ID is shared with another zone. Ask an administrator to separate them.',
        ]], $controller->messages);
    }

    public function testAddsAGroupOnAnUnsharedZoneId(): void
    {
        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->expects($this->once())->method('add')->with(42, 7);

        $controller = $this->controller(false, $zoneGroups);
        $this->submit($controller);

        $this->assertSame('success', $controller->messages[0][1] ?? null);
    }
}
