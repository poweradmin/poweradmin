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
use Poweradmin\BaseController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;
use ReflectionMethod;

/**
 * The add-owner form only offers the caller themselves unless they may view other users,
 * so a posted id outside that set is refused.
 */
class ZoneOwnershipControllerAssignTest extends TestCase
{
    private const CALLER = 100;
    private const OTHER = 200;

    private mixed $savedPost = null;

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
    }

    public function testAnotherUserIsRefusedWithoutViewOthers(): void
    {
        [$error, $added] = $this->submit(self::OTHER, false);

        $this->assertSame('You do not have permission to modify this zone.', $error);
        $this->assertSame([], $added);
    }

    public function testTheCallerCanStillAddThemselves(): void
    {
        [, $added] = $this->submit(self::CALLER, false);

        $this->assertSame([self::CALLER], $added);
    }

    public function testAnotherUserIsAddedWithViewOthers(): void
    {
        [, $added] = $this->submit(self::OTHER, true);

        $this->assertSame([self::OTHER], $added);
    }

    /** @return array{?string, int[]} */
    private function submit(int $newOwner, bool $canViewOthers): array
    {
        $_POST = ['newowner' => (string)$newOwner];

        $db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $db->exec('INSERT INTO users (id, username) VALUES (' . self::CALLER . ", 'caller'), (" . self::OTHER . ", 'other')");

        $added = [];
        $repository = $this->createMock(ZoneRepositoryInterface::class);
        $repository->method('isUserZoneOwner')->willReturn(false);
        $repository->method('addOwnerToZone')->willReturnCallback(static function (int $zoneId, int $userId) use (&$added): bool {
            $added[] = $userId;
            return true;
        });

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewOthersContent')->willReturn($canViewOthers);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => $default);

        $messages = new MessageService();
        $controller = (new ReflectionClass(ZoneOwnershipController::class))->newInstanceWithoutConstructor();
        $properties = [
            [ZoneOwnershipController::class, 'zoneRepository', $repository],
            [ZoneOwnershipController::class, 'permissionService', $permissions],
            [BaseController::class, 'db', $db],
            [BaseController::class, 'messageService', $messages],
            [BaseController::class, 'config', $config],
        ];
        foreach ($properties as [$class, $name, $value]) {
            (new ReflectionClass($class))->getProperty($name)->setValue($controller, $value);
        }

        (new ReflectionMethod(ZoneOwnershipController::class, 'handleFormSubmission'))
            ->invoke($controller, 1, self::CALLER, true);

        return [$messages->getMessages('zone_ownership')[0]['content'] ?? null, $added];
    }
}
