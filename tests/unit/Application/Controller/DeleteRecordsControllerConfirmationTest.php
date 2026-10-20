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

use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteRecordsController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionProperty;

/**
 * The bulk delete confirmation looks the record's zone up only for a user who may view
 * zones at all: for anyone else the lookup flashes a refusal, which would tell an existing
 * record from a missing one.
 */
class DeleteRecordsControllerConfirmationTest extends TestCase
{
    private array $savedSession = [];
    private ?PermissionService $savedPermissionMemo = null;

    /** @var string[] */
    private array $granted = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $_SESSION[SessionKeys::USERID] = 7;

        $memo = new ReflectionProperty(Permission::class, 'permissionService');
        $this->savedPermissionMemo = $memo->getValue();
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(fn(int $userId, string $name): bool => in_array($name, $this->granted, true));
        $memo->setValue(null, $permissions);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        (new ReflectionProperty(Permission::class, 'permissionService'))->setValue(null, $this->savedPermissionMemo);
    }

    public function testAUserWhoMayViewNoZoneDoesNotTriggerTheZoneLookup(): void
    {
        $this->showRecordsExpectingZoneLookups($this->never());
    }

    public function testAUserWhoMayViewZonesStillHasTheZoneLookedUp(): void
    {
        $this->granted = ['zone_content_view_own'];

        $this->showRecordsExpectingZoneLookups($this->once());
    }

    private function showRecordsExpectingZoneLookups(InvocationOrder $times): void
    {
        $controller = new class ([]) extends DeleteRecordsController {
            use ControllerTestStubs;

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }
        };

        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordFromId')->willReturn(['id' => 5, 'domain_id' => 12, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0]);
        $records->method('getZoneIdFromRecordId')->willReturn(12);
        $records->method('recidToDomid')->willReturn(12);
        $controller->records = $records;
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($times)->method('getZoneInfoFromId')->willReturn(['id' => 12, 'type' => 'MASTER']);
        $controller->domains = $domains;

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('none');
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        foreach (['request' => new Request(), 'permissionService' => $permissions, 'userContextService' => $userContext] as $name => $value) {
            (new ReflectionProperty(DeleteRecordsController::class, $name))->setValue($controller, $value);
        }

        $controller->showRecords([5]);
    }
}
