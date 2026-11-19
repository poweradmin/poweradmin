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
use Poweradmin\Application\Controller\ZoneOwnershipController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The add-owner form only offers the caller themselves unless they may view other users,
 * so a posted id outside that set is refused.
 */
class ZoneOwnershipControllerAssignTest extends TestCase
{
    private const CALLER = 100;
    private const OTHER = 200;

    private mixed $savedConfigInstance = null;
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        $property = new ReflectionProperty(ConfigurationManager::class, 'instance');
        $this->savedConfigInstance = $property->getValue();
        $property->setValue(null, $this->createMock(ConfigurationManager::class));
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        (new ReflectionProperty(ConfigurationManager::class, 'instance'))->setValue(null, $this->savedConfigInstance);
    }

    public function testAnotherUserIsRefusedWithoutViewOthers(): void
    {
        [$controller, $added] = $this->submit(self::OTHER, false);

        $this->assertSame('You do not have permission to modify this zone.', $controller->messages[0][2] ?? null);
        $this->assertSame([], $added->ids);
    }

    public function testTheCallerCanStillAddThemselves(): void
    {
        [, $added] = $this->submit(self::CALLER, false);

        $this->assertSame([self::CALLER], $added->ids);
    }

    public function testAnotherUserIsAddedWithViewOthers(): void
    {
        [, $added] = $this->submit(self::OTHER, true);

        $this->assertSame([self::OTHER], $added->ids);
    }

    /** @return array{TestableZoneOwnershipController, object} */
    private function submit(int $newOwner, bool $canViewOthers): array
    {
        $_POST = ['newowner' => (string)$newOwner];

        $added = new class {
            /** @var int[] */
            public array $ids = [];
        };
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('isSharedZoneId')->willReturn(false);
        $zones->method('isUserZoneOwner')->willReturn(false);
        $zones->method('addOwnerToZone')->willReturnCallback(static function (int $zoneId, int $userId) use ($added): bool {
            $added->ids[] = $userId;
            return true;
        });

        $users = $this->createMock(UserRepository::class);
        $users->method('getUserById')->willReturn(['id' => $newOwner]);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewOthersContent')->willReturn($canViewOthers);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $default);

        $controller = new TestableZoneOwnershipController();
        $controller->setConfig($config);
        $controller->setDb(new PDO('sqlite::memory:'));
        $controller->userRepository = $users;
        foreach (['zoneRepository' => $zones, 'permissionService' => $permissions, 'request' => new Request()] as $name => $value) {
            (new ReflectionProperty(ZoneOwnershipController::class, $name))->setValue($controller, $value);
        }

        (new ReflectionMethod($controller, 'handleFormSubmission'))
            ->invoke($controller, 1, 'example.com', self::CALLER, true);

        return [$controller, $added];
    }
}
