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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Backend\PowerdnsStatusService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Symfony\Component\HttpFoundation\JsonResponse;

class ServerStatusControllerTest extends TestCase
{
    private MockObject $permissionService;
    private MockObject $statusService;
    private MockObject $supermasterManager;

    protected function setUp(): void
    {
        $this->permissionService = $this->createMock(ApiPermissionService::class);
        $this->statusService = $this->createMock(PowerdnsStatusService::class);
        $this->supermasterManager = $this->createMock(SupermasterManager::class);
    }

    private function createController(array $query = []): TestableServerStatusController
    {
        $controller = new TestableServerStatusController();
        $controller->setApiPermissionService($this->permissionService);
        $controller->setStatusService($this->statusService);
        $controller->setSupermasterManager($this->supermasterManager);
        $controller->setQuery($query);

        return $controller;
    }

    private function allowAndReturn(array $status, bool $canViewAutoprimaries = true): void
    {
        $this->permissionService->method('userHasPermission')->willReturnMap([
            [7, Permission::PERM_SERVER_STATUS_VIEW, true],
            [7, Permission::PERM_SUPERMASTER_VIEW, $canViewAutoprimaries],
        ]);
        $this->statusService->method('isApiEnabled')->willReturn(true);
        $this->statusService->method('getServerStatus')->willReturn($status);
    }

    private static function runningStatus(): array
    {
        return [
            'running' => true,
            'id' => 'localhost',
            'daemon_type' => 'authoritative',
            'version' => '4.9.4',
            'uptime_seconds' => '86400',
            'metrics' => ['uptime' => '86400', 'udp-queries' => 12, 'corrupt-packets' => '0'],
        ];
    }

    private static function decode(JsonResponse $response): array
    {
        return json_decode((string)$response->getContent(), true);
    }

    public function testUserWithoutPermissionIsRefused(): void
    {
        $this->permissionService->method('userHasPermission')->willReturn(false);
        $this->statusService->expects($this->never())->method('getServerStatus');

        $response = $this->createController()->callGetStatus();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testZoneRestrictedKeyIsRefusedEvenForPermittedUser(): void
    {
        $this->permissionService->method('userHasPermission')->willReturn(true);
        $this->statusService->expects($this->never())->method('getServerStatus');

        $controller = $this->createController();
        $controller->setApiKeyScope(new ApiKeyScope([5], null, false));
        $response = $controller->callGetStatus();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingPowerdnsApiReturns501(): void
    {
        $this->permissionService->method('userHasPermission')->willReturn(true);
        $this->statusService->method('isApiEnabled')->willReturn(false);
        $this->statusService->expects($this->never())->method('getServerStatus');

        $response = $this->createController()->callGetStatus();

        $this->assertSame(501, $response->getStatusCode());
    }

    public function testUnreachableServerReturns503(): void
    {
        $this->allowAndReturn(['running' => false, 'error' => 'Connection refused']);

        $response = $this->createController()->callGetStatus();
        $content = self::decode($response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($content['success']);
        $this->assertSame(['running' => false], $content['data']);
    }

    public function testRunningServerReturnsStatusWithStringMetricsAndNoSlaves(): void
    {
        $this->allowAndReturn(self::runningStatus());
        $this->supermasterManager->expects($this->never())->method('getSlaveServerIPs');

        $response = $this->createController()->callGetStatus();
        $data = self::decode($response)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['running']);
        $this->assertSame('localhost', $data['server_id']);
        $this->assertSame('4.9.4', $data['version']);
        $this->assertSame(86400, $data['uptime_seconds']);
        $this->assertSame(['corrupt-packets' => '0', 'udp-queries' => '12', 'uptime' => '86400'], $data['metrics']);
        $this->assertArrayNotHasKey('slaves', $data);
    }

    public function testMetricsQueryLimitsTheReturnedMetrics(): void
    {
        $this->allowAndReturn(self::runningStatus());

        $response = $this->createController(['metrics' => ' uptime, ,missing '])->callGetStatus();

        $this->assertSame(['uptime' => '86400'], self::decode($response)['data']['metrics']);
    }

    public function testIncludeSlavesWithoutSupermasterViewIsRefused(): void
    {
        $this->allowAndReturn(self::runningStatus(), false);
        $this->supermasterManager->expects($this->never())->method('getSlaveServerIPs');
        $this->statusService->expects($this->never())->method('getServerStatus');

        $response = $this->createController(['include' => 'slaves'])->callGetStatus();

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testIncludeSlavesProbesAutoprimaries(): void
    {
        $this->allowAndReturn(self::runningStatus());
        $this->supermasterManager->method('getSlaveServerIPs')->willReturn(['192.0.2.10']);
        $this->statusService->method('checkSlaveServerStatus')->with(['192.0.2.10'])->willReturn([
            ['ip' => '192.0.2.10', 'status' => 'unreachable', 'lastChecked' => '', 'error' => 'timeout'],
        ]);

        $response = $this->createController(['include' => 'slaves'])->callGetStatus();

        $this->assertSame([
            ['ip' => '192.0.2.10', 'status' => 'unreachable', 'last_checked' => null, 'error' => 'timeout'],
        ], self::decode($response)['data']['slaves']);
    }
}
