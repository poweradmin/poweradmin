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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;

/**
 * Converting a zone is equivalent to creating one of the target type, so
 * zone-meta edit alone must not be enough.
 */
#[CoversClass(EditController::class)]
class EditControllerChangeZoneTypeTest extends TestCase
{
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

    public static function cases(): array
    {
        return [
            'SLAVE needs zone_slave_add' => ['SLAVE', ['zone_master_add'], false],
            'MASTER needs zone_master_add' => ['MASTER', ['zone_slave_add'], false],
            'NATIVE needs zone_master_add' => ['NATIVE', ['zone_slave_add'], false],
            'SLAVE allowed' => ['SLAVE', ['zone_slave_add'], true],
            'MASTER allowed' => ['MASTER', ['zone_master_add'], true],
            'NATIVE allowed' => ['NATIVE', ['zone_master_add'], true],
        ];
    }

    #[DataProvider('cases')]
    public function testConversionRequiresAddPermissionOfTargetType(string $type, array $granted, bool $allowed): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($allowed ? $this->once() : $this->never())
            ->method('changeZoneType')->willReturn(true);

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')
            ->willReturnCallback(fn(int $u, string $p) => in_array($p, $granted, true));

        $reflection = new ReflectionClass(EditController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $messageService = new MessageService();
        $base = new ReflectionClass(BaseController::class);
        $properties = [
            'userContextService' => new UserContextService(),
            'permissionService' => $permissionService,
            'dnsRecord' => $dnsRecord,
            'messageService' => $messageService,
        ];
        foreach ($properties as $name => $value) {
            ($reflection->hasProperty($name) ? $reflection : $base)->getProperty($name)->setValue($controller, $value);
        }

        $controller->changeZoneType($type, 42);

        $messages = $messageService->getMessages('edit') ?? [];
        $this->assertSame($allowed ? 'success' : 'error', $messages[0]['type'] ?? 'success');
    }
}
