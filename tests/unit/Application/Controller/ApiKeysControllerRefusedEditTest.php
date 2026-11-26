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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ApiKeysController;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionProperty;

/**
 * A refused edit leaves the stored key as it was, so the form is drawn again from it
 * rather than from the service's empty answer.
 */
class ApiKeysControllerRefusedEditTest extends TestCase
{
    private ?string $requestMethod = null;

    protected function setUp(): void
    {
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        if ($this->requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethod;
        }
    }

    public function testARefusedEditShowsTheFormWithTheStoredKey(): void
    {
        $stored = new ApiKey('stored name', 'pwa_secret', 1, null, null, false, null, 7);
        $stored->setZoneIds([0, 42]);
        $stored->setAllowedOperations(['zones.read']);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->once())->method('updateApiKey')->willReturn(null);
        $service->method('zoneScopesReady')->willReturn(true);

        $controller = $this->controller($service, [42]);
        $controller->setRequestData(['id' => '7', 'name' => 'new name', 'zones' => ['42']]);

        $this->invokeEdit($controller);

        $this->assertCount(1, $controller->rendered);
        [$template, $params] = $controller->rendered[0];
        $this->assertSame('api_key_edit.html', $template);
        $this->assertSame($stored, $params['api_key']);
        $this->assertSame([0, 42], $params['selected_zones']);
        $this->assertSame(['zones.read'], $params['selected_operations']);
        $this->assertTrue($params['zone_scopes_ready']);
        $this->assertTrue($params['zone_scope_unmapped']);
    }

    public function testAnEditThatNeverRenderedAStoredZoneKeepsItInTheScope(): void
    {
        $stored = new ApiKey('stored name', 'pwa_secret', 1, null, null, false, null, 7);
        $stored->setZoneIds([42, 99]);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->once())->method('updateApiKey')
            ->with(7, 'renamed', null, false, false, null, [99])
            ->willReturn($stored);

        $controller = $this->controller($service, [42]);
        $controller->setRequestData(['id' => '7', 'name' => 'renamed']);

        $this->invokeEdit($controller);
    }

    public function testAnEditAddsASubmittedZoneToTheHiddenOnes(): void
    {
        $stored = new ApiKey('stored name', 'pwa_secret', 1, null, null, false, null, 7);
        $stored->setZoneIds([99]);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->once())->method('updateApiKey')
            ->with(7, 'renamed', null, false, false, null, [42, 99])
            ->willReturn($stored);

        $controller = $this->controller($service, [42]);
        $controller->setRequestData(['id' => '7', 'name' => 'renamed', 'zones' => ['42']]);

        $this->invokeEdit($controller);
    }

    public function testAnEditNamingAZoneTheEditorCannotAssignIsRefused(): void
    {
        $stored = new ApiKey('stored name', 'pwa_secret', 1, null, null, false, null, 7);

        $service = $this->createMock(ApiKeyService::class);
        $service->method('getApiKey')->with(7)->willReturn($stored);
        $service->expects($this->never())->method('updateApiKey');

        $controller = $this->controller($service, [42]);
        $controller->setRequestData(['id' => '7', 'name' => 'renamed', 'zones' => ['42', '1000']]);

        $this->invokeEdit($controller);

        $this->assertSame(['You do not have permission to access this zone.'], $controller->errors);
    }

    /**
     * @param int[] $assignable
     */
    private function controller(ApiKeyService $service, array $assignable = []): TestableApiKeysController
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $key === 'global_token_validation' ? false : $default
        );
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(1);
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('listZones')->willReturn(array_map(
            static fn(int $id): array => ['id' => $id, 'name' => "z$id.example", 'utf8_name' => "z$id.example"],
            $assignable
        ));

        $controller = new TestableApiKeysController();
        $controller->setConfig($config);
        $controller->userContext = $userContext;
        (new ReflectionProperty(ApiKeysController::class, 'apiKeyService'))->setValue($controller, $service);
        (new ReflectionProperty(ApiKeysController::class, 'zoneRepository'))->setValue($controller, $zones);
        (new ReflectionProperty(ApiKeysController::class, 'auditLogger'))->setValue($controller, $this->createMock(LegacyLogger::class));
        (new ReflectionProperty(ApiKeysController::class, 'ipAddressRetriever'))->setValue($controller, new IpAddressRetriever([]));
        (new ReflectionProperty(BaseController::class, 'messageService'))->setValue($controller, $this->createMock(MessageService::class));

        return $controller;
    }

    private function invokeEdit(TestableApiKeysController $controller): void
    {
        (new \ReflectionMethod(ApiKeysController::class, 'editApiKey'))->invoke($controller);
    }
}
