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

use Poweradmin\Application\Controller\ZoneOwnershipController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The add-owner form only offers the caller themselves unless they may view other users,
 * so a posted id outside that set is refused.
 */
class ZoneOwnershipControllerAssignTest extends SqliteIntegrationTestCase
{
    private const CALLER = 100;
    private const OTHER = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CALLER . ", 'caller', 1), (" . self::OTHER . ", 'other', 1)");
    }

    public function testAnotherUserIsRefusedWithoutViewOthers(): void
    {
        [$controller, $zones] = $this->submit(self::OTHER, false);

        $this->assertSame('You do not have permission to modify this zone.', $this->errorOf($controller));
        $this->assertSame([], $zones->added);
    }

    public function testTheCallerCanStillAddThemselves(): void
    {
        [, $zones] = $this->submit(self::CALLER, false);

        $this->assertSame([self::CALLER], $zones->added);
    }

    public function testAnotherUserIsAddedWithViewOthers(): void
    {
        [, $zones] = $this->submit(self::OTHER, true);

        $this->assertSame([self::OTHER], $zones->added);
    }

    /** @return array{object, object} */
    private function submit(int $newOwner, bool $canViewOthers): array
    {
        $_POST = ['newowner' => (string)$newOwner];

        $zones = new class {
            /** @var int[] */
            public array $added = [];
        };
        $repository = $this->createMock(ZoneRepositoryInterface::class);
        $repository->method('isUserZoneOwner')->willReturn(false);
        $repository->method('addOwnerToZone')->willReturnCallback(static function (int $zoneId, int $userId) use ($zones): bool {
            $zones->added[] = $userId;
            return true;
        });

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewOthersContent')->willReturn($canViewOthers);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => $default);

        $controller = new class ([], $this->db, $config) extends ZoneOwnershipController {
            use ControllerTestStubs;
        };
        (new ReflectionProperty(ZoneOwnershipController::class, 'zoneRepository'))->setValue($controller, $repository);
        (new ReflectionProperty(ZoneOwnershipController::class, 'permissionService'))->setValue($controller, $permissions);

        (new ReflectionMethod(ZoneOwnershipController::class, 'handleFormSubmission'))
            ->invoke($controller, 1, 'example.com', self::CALLER, true);

        return [$controller, $zones];
    }

    private function errorOf(ZoneOwnershipController $controller): ?string
    {
        $messages = $controller->getMessages('zone_ownership');

        return $messages[0]['content'] ?? null;
    }
}
