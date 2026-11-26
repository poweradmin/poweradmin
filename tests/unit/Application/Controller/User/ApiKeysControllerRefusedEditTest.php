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
use Poweradmin\Domain\Model\ZoneSummary;
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

        $this->assignableZones([42]);

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

    public function testASuccessfulEditAuditsTheScopeItChanged(): void
    {
        $stored = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $stored->setZoneIds([42]);
        $stored->setAllowedOperations(null);

        $saved = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $saved->setIsReadonly(true);
        $saved->setAllowedOperations(['zones.read']);
        $saved->setZoneIds([43, 42]);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->method('updateApiKey')->willReturn(ApiKeyWriteResult::ok($saved));
        $this->factory->method('apiKeyService')->willReturn($service);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => $permission === Permission::PERM_API_MANAGE_KEYS
        );
        $this->factory->method('permissionService')->willReturn($permissions);

        $this->assignableZones([42, 43]);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logApiKeyEdit')->with(7, 'key', [
            'read_only' => 'yes',
            'operations_before' => 'all',
            'operations_after' => 'zones.read',
            'zones_before' => '42',
            'zones_after' => '42,43',
        ]);
        $this->factory->method('auditService')->willReturn($audit);

        $this->post(['id' => '7', 'name' => 'key', 'is_readonly' => 'on', 'operations' => ['zones.read'], 'zones' => ['43', '42']]);
        $config = $this->configure(['api' => ['enabled' => true]]);
        $controller = new ApiKeysController(['page' => 'api_keys_edit'] + $this->requestData(), $this->environment($config));

        try {
            $controller->run();
        } catch (\Poweradmin\Application\Controller\RequestHalted) {
        }
    }

    /**
     * @param list<int>|null $savedZones Zone ids the saved key carries; null when it ends up unrestricted
     * @param array<string, string> $expectedScope
     * @param array<string, mixed> $post
     */
    private function editWithZones(?array $savedZones, array $post, array $expectedScope): void
    {
        $stored = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $stored->setZoneIds([42]);

        $saved = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $saved->setZoneIds($savedZones);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->method('updateApiKey')->willReturn(ApiKeyWriteResult::ok($saved));
        $this->factory->method('apiKeyService')->willReturn($service);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => $permission === Permission::PERM_API_MANAGE_KEYS
        );
        $this->factory->method('permissionService')->willReturn($permissions);

        $this->assignableZones([42, 43]);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logApiKeyEdit')->with(7, 'key', $expectedScope);
        $this->factory->method('auditService')->willReturn($audit);

        $this->post(['id' => '7', 'name' => 'key'] + $post);
        $config = $this->configure(['api' => ['enabled' => true]]);
        $controller = new ApiKeysController(['page' => 'api_keys_edit'] + $this->requestData(), $this->environment($config));

        try {
            $controller->run();
        } catch (\Poweradmin\Application\Controller\RequestHalted) {
        }
    }

    public function testAZoneListTheServiceDidNotStoreIsNotAuditedAsAChange(): void
    {
        // A skipped write leaves the stored list on the saved key
        $this->editWithZones([42], ['zones' => ['43']], []);
    }

    public function testClearingTheZoneRestrictionIsAudited(): void
    {
        $this->editWithZones(null, [], ['zones_before' => '42', 'zones_after' => 'all']);
    }

    public function testAnEditThatChangesNoScopeAddsNoScopeFields(): void
    {
        $same = ['read_only' => false, 'operations' => null, 'zones' => []];

        $this->assertSame([], ApiKeysController::scopeChanges($same, ['read_only' => false, 'operations' => null, 'zones' => null]));
    }

    public function testAnEditThatDoesNotRenderAStoredZoneKeepsItInTheScope(): void
    {
        $stored = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);
        $stored->setZoneIds([42, 99]);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->once())->method('updateApiKey')
            ->with(7, 'renamed', null, false, false, null, [99])
            ->willReturn(ApiKeyWriteResult::ok($stored));
        $this->factory->method('apiKeyService')->willReturn($service);
        $this->grantKeyManagement();
        $this->assignableZones([42]);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));

        $this->post(['id' => '7', 'name' => 'renamed']);
        $controller = new ApiKeysController(['page' => 'api_keys_edit'] + $this->requestData(), $this->environment($this->configure(['api' => ['enabled' => true]])));

        try {
            $controller->run();
        } catch (\Poweradmin\Application\Controller\RequestHalted) {
        }
    }

    public function testASubmittedZoneTheEditorCannotAssignIsRefusedBeforeAnyWrite(): void
    {
        $stored = new ApiKey('key', 'pwa_secret', self::USER_ID, null, null, false, null, 7);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->never())->method('updateApiKey');
        $this->factory->method('apiKeyService')->willReturn($service);
        $this->grantKeyManagement();
        $this->assignableZones([42]);

        $this->post(['id' => '7', 'name' => 'key', 'zones' => ['42', '777']]);
        $controller = new ApiKeysController(['page' => 'api_keys_edit'] + $this->requestData(), $this->environment($this->configure(['api' => ['enabled' => true]])));

        try {
            $controller->run();
        } catch (\Poweradmin\Application\Controller\RequestHalted) {
        }

        $this->assertSame([['error', 'A selected zone is not available for this API key.']], $this->messagesFor('api_key_edit'));
        $this->assertSame('api_key_edit.html', $this->renderedTemplate());
    }

    public function testMergingKeepsHiddenStoredIdsAndRefusesForeignOnes(): void
    {
        $this->assertSame([42, 7], ApiKeysController::mergeZoneScope(['42'], [42, 7], [42]));
        $this->assertSame([7], ApiKeysController::mergeZoneScope([], [42, 7], [42]));
        $this->assertSame([], ApiKeysController::mergeZoneScope([], [42], [42]));
        $this->assertNull(ApiKeysController::mergeZoneScope(['5'], null, [42]));
        $this->assertNull(ApiKeysController::mergeZoneScope(['abc'], null, [42]));
    }

    public function testMergingDoesNotWriteTheUnmappedScopeMarkerBack(): void
    {
        $this->assertSame([42, 7], ApiKeysController::mergeZoneScope(['42'], [0, 42, 7], [42]));
        $this->assertSame([], ApiKeysController::mergeZoneScope([], [0], [42]));
    }

    private function grantKeyManagement(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => $permission === Permission::PERM_API_MANAGE_KEYS
        );
        $this->factory->method('permissionService')->willReturn($permissions);
    }

    /**
     * @param list<int> $ids
     */
    private function assignableZones(array $ids): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('listZones')->willReturn(array_map(
            static fn(int $id): ZoneSummary => new ZoneSummary($id, "z{$id}.example", "z{$id}.example", 'MASTER', 0, '', false, [], []),
            $ids
        ));
        $this->factory->method('zoneRepository')->willReturn($zones);
    }
}
