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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\User\ApiKeysController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Poweradmin\Domain\Service\Auth\ApiKeyWriteResult;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * A refused edit leaves the stored key as it was, so the form is drawn again from it
 * rather than from the refused submission or the service's empty answer.
 */
#[CoversClass(ApiKeysController::class)]
class ApiKeysControllerRefusedEditTest extends SeamControllerTestCase
{
    public function testARefusedEditShowsTheFormWithTheStoredKey(): void
    {
        $stored = new ApiKey('stored name', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $stored->setZoneIds([0, 42]);
        $stored->setAllowedOperations(['zones.read']);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->once())->method('updateApiKey')
            ->willReturn(ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, 'Refused', Refusal::FORBIDDEN));
        $service->method('zoneScopesReady')->willReturn(true);
        $this->factory->method('apiKeyService')->willReturn($service);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => $permission === Permission::PERM_API_MANAGE_KEYS
        );
        $this->factory->method('permissionService')->willReturn($permissions);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('listZones')->willReturn([]);
        $this->factory->method('zoneRepository')->willReturn($zones);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logApiKeyEdit');
        $this->factory->method('auditService')->willReturn($audit);

        $this->post(['id' => '7', 'name' => 'new name', 'zones' => ['42']]);
        $config = $this->configure(['api' => ['enabled' => true]]);
        $controller = new ApiKeysController(['page' => 'api_keys_edit'] + $this->requestData(), $this->environment($config));

        $controller->run();

        $this->assertSame('api_key_edit.html', $this->renderedTemplate());
        $params = $this->renderedParams();
        $this->assertSame($stored, $params['api_key']);
        $this->assertSame([0, 42], $params['selected_zones']);
        $this->assertSame(['zones.read'], $params['selected_operations']);
        $this->assertTrue($params['zone_scopes_ready']);
        $this->assertTrue($params['zone_scope_unmapped']);
        $this->assertSame([['error', 'Refused']], $this->messagesFor('api_key_edit'));
    }
}
