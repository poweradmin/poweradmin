<?php

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Controller\ManageGroupZonesController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Adding zones to a group follows zone_ownership_mode, as the per-zone ownership page does.
 */
class ManageGroupZonesControllerOwnershipModeTest extends SqliteIntegrationTestCase
{
    #[RunInSeparateProcess]
    public function testZonesAreNotAddedToAGroupWhenTheModeForbidsGroupOwners(): void
    {
        $_POST['domain_ids'] = '5';
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => $group === 'dns' && $key === 'zone_ownership_mode' ? 'users_only' : $default
        );

        $zoneGroupService = $this->createMock(ZoneGroupService::class);
        $zoneGroupService->expects($this->never())->method('bulkAddZones');

        $controller = new class ([], $this->db, $config) extends ManageGroupZonesController {
            use ControllerTestStubs;

            public UserContextService $userContext;

            protected function getUserContextService(): UserContextService
            {
                return $this->userContext;
            }

            public function redirect(string $url, array $args = []): void
            {
                throw new RuntimeException('redirect');
            }
        };
        $controller->userContext = $this->createMock(UserContextService::class);
        $controller->userContext->method('getLoggedInUserId')->willReturn(self::ADMIN_USER_ID);
        (new ReflectionProperty(ManageGroupZonesController::class, 'zoneGroupService'))->setValue($controller, $zoneGroupService);
        (new ReflectionProperty(ManageGroupZonesController::class, 'groupService'))->setValue($controller, $this->createMock(GroupService::class));
        (new ReflectionProperty(ManageGroupZonesController::class, 'request'))->setValue($controller, new Request());

        try {
            (new ReflectionMethod(ManageGroupZonesController::class, 'addZones'))->invoke($controller, 3);
        } catch (RuntimeException) {
            // The refusal re-renders the page, which ends on the stubbed redirect
        }

        $messages = $_SESSION['messages']['manage_group_zones'] ?? [];
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('disabled by the current zone ownership mode', $messages[0]['content']);
    }
}
