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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;

/**
 * A zone-edit save must require edit permission on the zone. The page-level
 * check only proves view access, and the zone comment and SOA serial bump are
 * not re-checked per row like records are.
 */
#[CoversClass(EditController::class)]
class EditControllerSaveRecordsGateTest extends TestCase
{
    private const ZONE_ID = 42;

    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = ['userid' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        parent::tearDown();
    }

    public static function deniedLevels(): array
    {
        return [
            'no edit permission' => ['none', true],
            'own but not owner' => ['own', false],
            'own_as_client but not owner' => ['own_as_client', false],
        ];
    }

    #[Test]
    #[DataProvider('deniedLevels')]
    public function testSaveIsRefusedWithoutEditPermission(string $level, bool $owner): void
    {
        $zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $zoneRepository->expects($this->never())->method('getDomainType');
        $zoneRepository->expects($this->never())->method('updateZoneComment');

        $messages = $this->save($level, $owner, $zoneRepository);

        $this->assertSame('error', $messages[0]['type']);
        $this->assertStringContainsString('permission to edit', $messages[0]['content']);
    }

    #[Test]
    public function testSaveProceedsWithEditPermission(): void
    {
        // Reaching the read-only check proves the gate passed.
        $zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $zoneRepository->expects($this->once())->method('getDomainType')->willReturn('SLAVE');

        $messages = $this->save('own', true, $zoneRepository);

        $this->assertStringContainsString('read-only', $messages[0]['content']);
    }

    /**
     * @return array<int, array{type: string, content: string}>
     */
    private function save(string $level, bool $owner, ZoneRepositoryInterface $zoneRepository): array
    {
        $reflection = new ReflectionClass(EditController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('getEditPermissionLevelForZone')->willReturn($level);
        $permissionService->method('userOwnsZone')->with(7, self::ZONE_ID)->willReturn($owner);
        $messageService = new MessageService();

        // Private properties live on two classes; pick the declaring one.
        $base = new ReflectionClass(BaseController::class);
        $properties = [
            'permissionService' => $permissionService,
            'zoneRepository' => $zoneRepository,
            'db' => $this->createMock(PDO::class),
            'messageService' => $messageService,
        ];
        foreach ($properties as $name => $value) {
            ($reflection->hasProperty($name) ? $reflection : $base)->getProperty($name)->setValue($controller, $value);
        }
        // getCurrentUserId() reads the base copy of the shadowed property.
        $base->getProperty('userContextService')->setValue($controller, new UserContextService());

        $controller->saveRecords(self::ZONE_ID, 'example.com');

        return $messageService->getMessages('edit') ?? [];
    }
}
